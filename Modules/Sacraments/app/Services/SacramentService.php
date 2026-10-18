<?php

namespace Modules\Sacraments\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Sacraments\Exceptions\SacramentBusinessRuleException;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentDispensation;
use Modules\Sacraments\Models\SacramentParticipant;
use Modules\Sacraments\Models\SacramentType;
use Modules\Sacraments\Repositories\SacramentRepository;
use Modules\Sacraments\Services\Certificates\SacramentCertificateService;
use Modules\Sacraments\Support\MarriageCanonicalClassification;
use Modules\Sacraments\Support\SacramentFeatureFlags;
use Modules\Sacraments\Support\SacramentPrivacyAccess;
use Modules\Sacraments\Support\SacramentStatus;
use Modules\Sacraments\Support\SacramentTypeCode;
use Modules\Family\Models\Person;
use Modules\Tenants\Support\TenantCacheVersion;

/**
 * Sacrament lifecycle orchestrator (create / update / correct / void / restore / metadata).
 *
 * ADR-07 lifecycle · ADR-13 no silent FamilyMember mutation · ADR-02 server snapshots.
 */
class SacramentService
{
    private const CACHE_TTL_SECONDS = 300;

    private const METADATA_FIELDS = [
        'notes',
        'book_number',
        'page_number',
        'registry_entry',
        'certificate_number',
        'place_administered',
    ];

    private const CORRECTABLE_FIELDS = [
        'date_administered',
        'place_administered',
        'status',
        'book_number',
        'page_number',
        'registry_entry',
        'certificate_number',
        'notes',
        'minister_name',
        'minister_title',
        'recipient_name',
        'recipient_birth_date',
        'recipient_birth_place',
        'recipient_gender',
        'father_name',
        'mother_name',
        'godparent1_name',
        'godparent2_name',
        'witnesses',
        'baptism_date',
        'event_subtype',
        'typed_attributes',
        'place_classification',
        'marriage_bride_full_name',
        'marriage_bride_father_name',
        'marriage_bride_mother_name',
        'marriage_bride_address',
        'marriage_bride_church_type',
        'marriage_bride_church_name',
        'marriage_bride_church_address',
        'marriage_bride_diocese_name',
        'marriage_bride_diocese_region',
        'marriage_bride_diocese_country',
        'marriage_groom_full_name',
        'marriage_groom_father_name',
        'marriage_groom_mother_name',
        'marriage_groom_address',
        'marriage_groom_church_type',
        'marriage_groom_church_name',
        'marriage_groom_church_address',
        'marriage_groom_diocese_name',
        'marriage_groom_diocese_region',
        'marriage_groom_diocese_country',
        'marriage_canonical_classification',
    ];

    public function __construct(
        protected SacramentRepository $repository,
        protected SacramentParticipantValidator $participantValidator,
        protected SacramentLegacyDenormMapper $denormMapper,
        protected SacramentIdempotencyService $idempotency,
        protected SacramentAuditService $audit,
        protected SacramentCertificateService $certificates,
        protected SacramentDuplicateDetector $duplicateDetector,
        protected SacramentTypedAttributesValidator $typedAttributesValidator,
        protected SacramentPrivacyAccess $privacyAccess,
        protected SacramentRecipientResolver $recipientResolver,
        protected SacramentBaptismMembershipService $baptismMembershipService,
        protected MarriageCanonicalClassifier $canonicalClassifier,
        protected SacramentLeadershipContextBuilder $leadershipContextBuilder,
    ) {}

    public function getAll(array $params = []): LengthAwarePaginator
    {
        $params['exclude_restricted'] = ! ($params['include_restricted'] ?? false);
        if (! empty($params['exclude_restricted'])) {
            $params['restricted_type_codes'] = $this->privacyAccess->restrictedTypeCodes();
        }

        $cacheKey = $this->getCacheKey($params);

        // Only cache stable list views. Family/BCC/search/date filters must never share a key.
        $shouldCache = empty($params['search'])
            && empty($params['date_from'])
            && empty($params['date_to'])
            && empty($params['minister_name'])
            && empty($params['certificate_number'])
            && empty($params['book_number'])
            && empty($params['marriage_register_filter'])
            && empty($params['family_id'])
            && empty($params['bcc_id'])
            && empty($params['family_member_id']);

        if ($shouldCache) {
            return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($params) {
                return $this->repository->getPaginated($params);
            });
        }

        return $this->repository->getPaginated($params);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function getCacheKey(array $params): string
    {
        $tenantId = (int) ($params['tenant_id'] ?? 0);
        $suffix = implode('_', [
            'page_'.($params['page'] ?? 1),
            'per_page_'.($params['per_page'] ?? 20),
            'type_'.($params['sacrament_type_id'] ?? 'all'),
            'status_'.($params['status'] ?? 'all'),
            'subtype_'.($params['event_subtype'] ?? 'all'),
            'family_'.($params['family_id'] ?? 'all'),
            'bcc_'.($params['bcc_id'] ?? 'all'),
            'member_'.($params['family_member_id'] ?? 'all'),
            'marriage_filter_'.($params['marriage_register_filter'] ?? 'all'),
            'restricted_'.(($params['include_restricted'] ?? false) ? '1' : '0'),
            'sort_'.($params['sort_by'] ?? 'date_administered').'_'.($params['sort_dir'] ?? 'desc'),
        ]);

        return TenantCacheVersion::scopedKey($tenantId, 'sacraments', $suffix);
    }

    private function getTenantCacheVersion(int $tenantId): int
    {
        return TenantCacheVersion::current($tenantId);
    }

    private function tenantCacheVersionKey(int $tenantId): string
    {
        return TenantCacheVersion::key($tenantId);
    }

    public function clearCache(?int $tenantId = null): void
    {
        if ($tenantId !== null && $tenantId > 0) {
            TenantCacheVersion::bump($tenantId);

            return;
        }
    }

    public function getById(int $id): ?Sacrament
    {
        return $this->repository->findById($id);
    }

    public function getByIdForTenant(int $id, int $tenantId): ?Sacrament
    {
        return $this->repository->findByIdForTenant($id, $tenantId);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{sacrament: Sacrament, replay: bool, warning: ?array}
     */
    public function create(array $data, ?string $idempotencyKey = null): array
    {
        $tenantId = (int) ($data['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            throw new SacramentBusinessRuleException(
                'tenant_required',
                'Tenant context is required to create a sacrament.'
            );
        }

        $requestHash = $this->idempotency->hashPayload($data);
        $begin = $this->idempotency->begin($tenantId, $idempotencyKey, $requestHash);
        if ($begin['replay']) {
            if (! $begin['sacrament']) {
                throw new SacramentBusinessRuleException(
                    'idempotency_replay_missing',
                    'Idempotency-Key refers to a missing sacrament.',
                    ['idempotency_key' => $idempotencyKey],
                    409
                );
            }

            return [
                'sacrament' => $begin['sacrament'],
                'replay' => true,
                'warning' => null,
            ];
        }

        $type = SacramentType::findOrFail((int) $data['sacrament_type_id']);
        $this->privacyAccess->assertCanCreateType(auth()->user(), $type);

        $typed = $this->typedAttributesValidator->validateAndNormalize($type, $data);
        $data = array_merge($data, $typed);

        $participantsInput = $data['participants'] ?? [];
        $hasParticipants = is_array($participantsInput) && count($participantsInput) > 0;
        $acknowledgeDuplicate = (bool) ($data['acknowledge_duplicate_warning'] ?? false);
        $userId = isset($data['created_by']) ? (int) $data['created_by'] : auth()->id();

        if ($hasParticipants || SacramentFeatureFlags::participantsV1Enabled()) {
            if ($hasParticipants) {
                $result = $this->createWithParticipants(
                    $data,
                    $participantsInput,
                    $tenantId,
                    $type,
                    $acknowledgeDuplicate,
                    $idempotencyKey,
                    $requestHash,
                    $userId
                );
            } else {
                // Legacy flat create still allowed when no participants payload is sent.
                $result = $this->createLegacyFlat($data, $tenantId, $type, $idempotencyKey, $requestHash);
            }
        } else {
            $result = $this->createLegacyFlat($data, $tenantId, $type, $idempotencyKey, $requestHash);
        }

        $this->clearCache($tenantId);

        return [
            'sacrament' => $result['sacrament']->load(['sacramentType', 'participants', 'creator', 'updater']),
            'replay' => false,
            'warning' => $result['warning'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $participantsInput
     * @return array{sacrament: Sacrament, warning: ?array}
     */
    private function createWithParticipants(
        array $data,
        array $participantsInput,
        int $tenantId,
        SacramentType $type,
        bool $acknowledgeDuplicate,
        ?string $idempotencyKey,
        string $requestHash,
        ?int $userId
    ): array {
        return DB::transaction(function () use (
            $data,
            $participantsInput,
            $tenantId,
            $type,
            $acknowledgeDuplicate,
            $idempotencyKey,
            $requestHash,
            $userId
        ) {
            $membershipContext = $data;
            $data = $this->recipientResolver->resolve($data, $type, $tenantId, $userId);
            $membershipContext = array_merge($membershipContext, $data);
            $participantsInput = $data['participants'] ?? $participantsInput;

            $normalized = $this->participantValidator->validateAndNormalize($type, $participantsInput, $tenantId);
            $this->assertRecipientPersonConsistency($data, $normalized);
            $this->lockResolvedPersonForCreate($tenantId, $data);

            $warning = $this->duplicateDetector->detect($tenantId, $type, $data, $normalized);
            if ($warning !== null) {
                $identityMatch = (bool) ($warning['identity_match'] ?? false);
                if ($identityMatch || ! $acknowledgeDuplicate) {
                    throw new SacramentBusinessRuleException(
                        'duplicate_sacrament',
                        $identityMatch
                            ? 'An official register entry already exists for this person in this parish.'
                            : 'A similar sacrament may already exist. Set acknowledge_duplicate_warning=true to proceed.',
                        $warning
                    );
                }
            }

            $denorm = $this->denormMapper->toSacramentAttributes($normalized);
            $denorm = array_merge($denorm, $this->matrimonyCanonicalAttributes($type, $normalized, $data));
            if (empty($denorm['recipient_name']) && empty($data['recipient_name'])) {
                throw new SacramentBusinessRuleException(
                    'participant_cardinality',
                    'Could not derive recipient name from participants.'
                );
            }

            // Prefer non-empty denorm fields; never wipe a valid request value with null/''.
            $denormFilled = array_filter(
                $denorm,
                static fn ($value) => $value !== null && $value !== ''
            );

            $attrs = array_merge($data, $denormFilled, [
                'status' => SacramentStatus::normalize($data['status'] ?? null) ?? SacramentStatus::REGISTERED,
                'lock_version' => 0,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);
            $eventDate = $attrs['date_administered'] ?? null;
            if ($eventDate instanceof \DateTimeInterface) {
                $eventDate = $eventDate->format('Y-m-d');
            }
            $attrs['leadership_context_json'] = $this->leadershipContextBuilder->build($tenantId, $eventDate);
            $attrs = $this->stripNonColumnAttrs($attrs);

            /** @var Sacrament $sacrament */
            $sacrament = $this->repository->create($attrs);

            foreach ($normalized as $row) {
                SacramentParticipant::create($this->participantPersistAttributes($tenantId, $sacrament->id, $row));
            }

            if (SacramentTypeCode::normalize($type->code) === SacramentTypeCode::BAPTISM) {
                $this->baptismMembershipService->establishAfterCreate(
                    $sacrament->fresh(['participants']),
                    $membershipContext,
                    $tenantId,
                    $userId
                );
            }

            $this->syncDispensations($sacrament, $data['dispensations'] ?? []);

            if ($idempotencyKey) {
                $this->idempotency->store($tenantId, $idempotencyKey, $requestHash, $sacrament->id);
            }

            $this->audit->log(
                $tenantId,
                'create',
                (string) $sacrament->id,
                null,
                $this->lifecycleSnapshot($sacrament->fresh(['participants'])),
                ['participants_v1' => true],
                'sacrament',
                $this->privacyAccess->privacyClassForType($type)
            );

            return [
                'sacrament' => $sacrament->fresh(['participants', 'sacramentType', 'family']),
                'warning' => $warning,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{sacrament: Sacrament, warning: null}
     */
    private function createLegacyFlat(
        array $data,
        int $tenantId,
        SacramentType $type,
        ?string $idempotencyKey,
        string $requestHash
    ): array {
        return DB::transaction(function () use ($data, $tenantId, $type, $idempotencyKey, $requestHash) {
            $attrs = array_merge($data, [
                'status' => SacramentStatus::normalize($data['status'] ?? null) ?? SacramentStatus::REGISTERED,
                'lock_version' => $data['lock_version'] ?? 0,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);
            $attrs = $this->stripNonColumnAttrs($attrs);

            $sacrament = $this->repository->create($attrs);

            if ($idempotencyKey) {
                $this->idempotency->store($tenantId, $idempotencyKey, $requestHash, $sacrament->id);
            }

            $this->audit->log(
                $tenantId,
                'create',
                (string) $sacrament->id,
                null,
                $this->lifecycleSnapshot($sacrament),
                ['participants_v1' => false],
                'sacrament',
                $this->privacyAccess->privacyClassForType($type)
            );

            return ['sacrament' => $sacrament, 'warning' => null];
        });
    }

    public function update(int $id, array $data): ?Sacrament
    {
        $sacrament = $this->repository->findById($id);
        if (! $sacrament) {
            return null;
        }

        if (($sacrament->status ?? null) === SacramentStatus::VOIDED) {
            throw new SacramentBusinessRuleException(
                'voided_immutable',
                'Voided sacraments cannot be edited. Use Correct only when reinstatement is supported, or create a new record.',
                [],
                422
            );
        }

        $data['updated_by'] = $data['updated_by'] ?? auth()->id();
        if (array_key_exists('status', $data)) {
            $data['status'] = SacramentStatus::normalize($data['status']) ?? $data['status'];
        }
        $data = $this->stripNonColumnAttrs($data);
        unset($data['lock_version'], $data['tenant_id'], $data['created_by']);

        $before = $this->lifecycleSnapshot($sacrament);
        $updated = $this->repository->update($sacrament, $data);
        $this->clearCache($sacrament->tenant_id);

        $this->audit->log(
            (int) $sacrament->tenant_id,
            'update',
            (string) $id,
            $before,
            $this->lifecycleSnapshot($updated),
            [],
            'sacrament',
            $this->privacyAccess->privacyClassForType($updated->sacramentType)
        );

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function correct(int $id, array $data, int $tenantId, int $userId): Sacrament
    {
        return DB::transaction(function () use ($id, $data, $tenantId, $userId) {
            /** @var Sacrament|null $sacrament */
            $sacrament = Sacrament::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->find($id);

            if (! $sacrament) {
                throw new SacramentBusinessRuleException(
                    'sacrament_not_found',
                    'Sacrament not found.',
                    [],
                    404
                );
            }

            $this->assertLockVersion($sacrament, (int) $data['lock_version']);

            if ($sacrament->status === SacramentStatus::VOIDED) {
                throw new SacramentBusinessRuleException(
                    'voided_immutable',
                    'Voided sacraments cannot be corrected.'
                );
            }

            $reason = (string) $data['reason'];
            $before = $this->lifecycleSnapshot($sacrament->loadMissing(['participants', 'sacramentType']));
            $type = $sacrament->sacramentType ?? SacramentType::findOrFail((int) $sacrament->sacrament_type_id);

            $patch = [];
            foreach (self::CORRECTABLE_FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $patch[$field] = $data[$field];
                }
            }
            if (array_key_exists('status', $patch)) {
                $patch['status'] = SacramentStatus::normalize($patch['status']) ?? $patch['status'];
            }

            $participantsInput = $data['participants'] ?? null;
            if (is_array($participantsInput) && count($participantsInput) > 0) {
                $normalized = $this->participantValidator->validateAndNormalize($type, $participantsInput, $tenantId);
                $denorm = $this->denormMapper->toSacramentAttributes($normalized);
                $denorm = array_merge($denorm, $this->matrimonyCanonicalAttributes($type, $normalized, $data));
                $denormFilled = array_filter(
                    $denorm,
                    static fn ($value) => $value !== null && $value !== ''
                );
                $patch = array_merge($patch, $denormFilled);

                SacramentParticipant::query()
                    ->where('sacrament_id', $sacrament->id)
                    ->whereNull('deleted_at')
                    ->each(function (SacramentParticipant $participant) {
                        $participant->delete();
                    });

                foreach ($normalized as $row) {
                    SacramentParticipant::create($this->participantPersistAttributes($tenantId, $sacrament->id, $row));
                }
            }

            if (array_key_exists('dispensations', $data)) {
                $this->syncDispensations($sacrament, is_array($data['dispensations']) ? $data['dispensations'] : []);
            }

            $patch['updated_by'] = $userId;
            $patch['lock_version'] = ((int) $sacrament->lock_version) + 1;
            $eventDate = $patch['date_administered'] ?? $sacrament->date_administered;
            if ($eventDate instanceof \DateTimeInterface) {
                $eventDate = $eventDate->format('Y-m-d');
            } elseif ($eventDate !== null) {
                $eventDate = (string) $eventDate;
            }
            $patch['leadership_context_json'] = $this->leadershipContextBuilder->build($tenantId, $eventDate);
            $sacrament->fill($patch);
            $sacrament->save();

            $this->certificates->supersedeIssuedForSacrament($sacrament->id, $tenantId, $reason);

            $fresh = $sacrament->fresh(['participants', 'sacramentType', 'creator', 'updater']);
            $this->audit->log(
                $tenantId,
                'correction',
                (string) $sacrament->id,
                $before,
                $this->lifecycleSnapshot($fresh),
                ['reason' => $reason],
                'sacrament',
                $this->privacyAccess->privacyClassForType($type)
            );

            $this->clearCache($tenantId);

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function void(int $id, array $data, int $tenantId, int $userId): Sacrament
    {
        return DB::transaction(function () use ($id, $data, $tenantId, $userId) {
            /** @var Sacrament|null $sacrament */
            $sacrament = Sacrament::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->find($id);

            if (! $sacrament) {
                throw new SacramentBusinessRuleException(
                    'sacrament_not_found',
                    'Sacrament not found.',
                    [],
                    404
                );
            }

            $this->privacyAccess->assertCanAccessSacrament(auth()->user(), $sacrament);
            $this->assertLockVersion($sacrament, (int) $data['lock_version']);

            if ($sacrament->status === SacramentStatus::VOIDED) {
                throw new SacramentBusinessRuleException(
                    'already_voided',
                    'This sacrament is already voided.'
                );
            }

            $before = $this->lifecycleSnapshot($sacrament);
            $sacrament->status = SacramentStatus::VOIDED;
            $sacrament->updated_by = $userId;
            $sacrament->lock_version = ((int) $sacrament->lock_version) + 1;
            $sacrament->save();

            $fresh = $sacrament->fresh(['sacramentType', 'participants']);
            $this->audit->log(
                $tenantId,
                'void',
                (string) $sacrament->id,
                $before,
                $this->lifecycleSnapshot($fresh),
                ['reason' => (string) $data['reason']],
                'sacrament',
                $this->privacyAccess->privacyClassForType($fresh->sacramentType)
            );

            $this->clearCache($tenantId);

            return $fresh;
        });
    }

    public function restore(int $id, int $tenantId, int $userId): Sacrament
    {
        return DB::transaction(function () use ($id, $tenantId, $userId) {
            /** @var Sacrament|null $sacrament */
            $sacrament = Sacrament::withTrashed()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->find($id);

            if (! $sacrament) {
                throw new SacramentBusinessRuleException(
                    'sacrament_not_found',
                    'Sacrament not found.',
                    [],
                    404
                );
            }

            if (! $sacrament->trashed()) {
                throw new SacramentBusinessRuleException(
                    'not_deleted',
                    'Sacrament is not soft-deleted.'
                );
            }

            $before = $this->lifecycleSnapshot($sacrament);
            $sacrament->restore();
            $sacrament->updated_by = $userId;
            $sacrament->save();

            $fresh = $sacrament->fresh(['sacramentType', 'participants']);
            $this->audit->log(
                $tenantId,
                'restore',
                (string) $sacrament->id,
                $before,
                $this->lifecycleSnapshot($fresh),
                ['note' => 'Soft-delete cleared; status unchanged (restore ≠ unvoid)'],
                'sacrament',
                $this->privacyAccess->privacyClassForType($fresh->sacramentType)
            );

            $this->clearCache($tenantId);

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function patchMetadata(int $id, array $data, int $tenantId, int $userId): Sacrament
    {
        return DB::transaction(function () use ($id, $data, $tenantId, $userId) {
            /** @var Sacrament|null $sacrament */
            $sacrament = Sacrament::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->find($id);

            if (! $sacrament) {
                throw new SacramentBusinessRuleException(
                    'sacrament_not_found',
                    'Sacrament not found.',
                    [],
                    404
                );
            }

            $this->privacyAccess->assertCanAccessSacrament(auth()->user(), $sacrament);
            $this->assertLockVersion($sacrament, (int) $data['lock_version']);

            if ($sacrament->status === SacramentStatus::VOIDED) {
                throw new SacramentBusinessRuleException(
                    'voided_immutable',
                    'Voided sacraments cannot have metadata patched.'
                );
            }

            $before = $this->lifecycleSnapshot($sacrament);
            $patch = [];
            foreach (self::METADATA_FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $patch[$field] = $data[$field];
                }
            }
            $patch['updated_by'] = $userId;
            $patch['lock_version'] = ((int) $sacrament->lock_version) + 1;
            $sacrament->fill($patch);
            $sacrament->save();

            $fresh = $sacrament->fresh(['sacramentType']);
            $this->audit->log(
                $tenantId,
                'metadata_patch',
                (string) $sacrament->id,
                $before,
                $this->lifecycleSnapshot($fresh),
                [],
                'sacrament',
                $this->privacyAccess->privacyClassForType($fresh->sacramentType)
            );

            $this->clearCache($tenantId);

            return $fresh;
        });
    }

    public function delete(int $id): bool
    {
        $sacrament = $this->repository->findById($id);
        if (! $sacrament) {
            return false;
        }

        $before = $this->lifecycleSnapshot($sacrament);
        $tenantId = (int) $sacrament->tenant_id;
        $deleted = $this->repository->delete($sacrament);

        if ($deleted) {
            $this->audit->log(
                $tenantId,
                'soft_delete',
                (string) $id,
                $before,
                null,
                [],
                'sacrament',
                $this->privacyAccess->privacyClassForType($sacrament->sacramentType)
            );
            $this->clearCache($tenantId);
        }

        return $deleted;
    }

    public function getByIds(array $ids, ?int $tenantId = null)
    {
        return $this->repository->findByIds($ids, $tenantId);
    }

    public function bulkUpdateStatus(array $ids, string $status, ?int $updatedBy = null): int
    {
        $updatedBy = $updatedBy ?? auth()->id();
        $status = SacramentStatus::normalize($status) ?? $status;

        if ($status === SacramentStatus::VOIDED) {
            throw new SacramentBusinessRuleException(
                'bulk_void_forbidden',
                'Use per-record void with a reason. Bulk void is not allowed.'
            );
        }

        $tenantIds = Sacrament::whereIn('id', $ids)
            ->distinct()
            ->pluck('tenant_id')
            ->filter()
            ->unique()
            ->toArray();

        $updated = Sacrament::whereIn('id', $ids)
            ->where('status', '!=', SacramentStatus::VOIDED)
            ->update([
                'status' => $status,
                'updated_by' => $updatedBy,
                'updated_at' => now(),
            ]);

        foreach ($tenantIds as $tenantId) {
            $this->clearCache((int) $tenantId);
        }

        return $updated;
    }

    public function bulkDelete(array $ids): int
    {
        $tenantIds = Sacrament::whereIn('id', $ids)
            ->distinct()
            ->pluck('tenant_id')
            ->filter()
            ->unique()
            ->toArray();

        $deleted = 0;
        foreach ($ids as $id) {
            if ($this->delete((int) $id)) {
                $deleted++;
            }
        }

        foreach ($tenantIds as $tenantId) {
            $this->clearCache((int) $tenantId);
        }

        return $deleted;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $normalized
     */
    /**
     * Serialize competing creates for the same resolved person in this tenant.
     * Locking only matching sacrament rows cannot serialize the empty-set case.
     *
     * @param  array<string, mixed>  $data
     */
    private function lockResolvedPersonForCreate(int $tenantId, array $data): void
    {
        $personId = $data['person_id'] ?? null;
        if (! is_string($personId) || $personId === '') {
            return;
        }

        $person = Person::query()
            ->forTenant($tenantId)
            ->whereKey($personId)
            ->lockForUpdate()
            ->first();

        if ($person === null) {
            throw new SacramentBusinessRuleException(
                'person_not_found',
                'Person not found in this parish.',
                ['person_id' => $personId]
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $normalized
     */
    private function assertRecipientPersonConsistency(array $data, array $normalized): void
    {
        $sacramentPersonId = $data['person_id'] ?? null;
        if (! $sacramentPersonId) {
            return;
        }

        foreach ($normalized as $row) {
            if (($row['role'] ?? '') !== 'recipient') {
                continue;
            }
            $participantPersonId = $row['person_id'] ?? null;
            if (! $participantPersonId || (string) $participantPersonId !== (string) $sacramentPersonId) {
                throw new SacramentBusinessRuleException(
                    'recipient_identity_mismatch',
                    'Sacrament recipient person_id must match the recipient participant person_id.',
                    [
                        'sacrament_person_id' => $sacramentPersonId,
                        'participant_person_id' => $participantPersonId,
                    ]
                );
            }
        }
    }

    private function assertLockVersion(Sacrament $sacrament, int $expected): void
    {
        if ((int) $sacrament->lock_version !== $expected) {
            throw new SacramentBusinessRuleException(
                'record_version_conflict',
                'This record was changed by someone else. Refresh and try again.',
                [
                    'current_lock_version' => (int) $sacrament->lock_version,
                    'provided_lock_version' => $expected,
                ],
                409
            );
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function participantPersistAttributes(int $tenantId, int $sacramentId, array $row): array
    {
        return [
            'tenant_id' => $tenantId,
            'sacrament_id' => $sacramentId,
            'role' => $row['role'],
            'source' => $row['source'],
            'person_id' => $row['person_id'] ?? null,
            'family_member_id' => $row['family_member_id'] ?? null,
            'church_leadership_id' => $row['church_leadership_id'] ?? null,
            'leadership_assignment_id' => $row['leadership_assignment_id'] ?? null,
            'sort_order' => $row['sort_order'] ?? 0,
            'affiliation_type' => $row['affiliation_type'] ?? null,
            'affiliation_parish_name' => $row['affiliation_parish_name'] ?? null,
            'affiliation_parish_address' => $row['affiliation_parish_address'] ?? null,
            'affiliation_diocese_name' => $row['affiliation_diocese_name'] ?? null,
            'affiliation_diocese_region' => $row['affiliation_diocese_region'] ?? null,
            'affiliation_diocese_country' => $row['affiliation_diocese_country'] ?? null,
            'external_full_name' => $row['external_full_name'] ?? null,
            'external_date_of_birth' => $row['external_date_of_birth'] ?? null,
            'external_gender' => $row['external_gender'] ?? null,
            'external_address' => $row['external_address'] ?? null,
            'external_contact_number' => $row['external_contact_number'] ?? null,
            'external_title' => $row['external_title'] ?? null,
            'external_minister_role' => $row['external_minister_role'] ?? null,
            'baptismal_status' => $row['baptismal_status'] ?? null,
            'ecclesial_affiliation_code' => $row['ecclesial_affiliation_code'] ?? null,
            'ecclesial_affiliation_label' => $row['ecclesial_affiliation_label'] ?? null,
            'canonical_delegation_status' => $row['canonical_delegation_status'] ?? null,
            'snapshot_json' => $row['snapshot_json'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lifecycleSnapshot(Sacrament $sacrament): array
    {
        $attrs = $sacrament->attributesToArray();
        unset($attrs['deleted_at']);

        if ($sacrament->relationLoaded('participants')) {
            $attrs['participants'] = $sacrament->participants
                ->whereNull('deleted_at')
                ->values()
                ->map(fn (SacramentParticipant $p) => [
                    'id' => $p->id,
                    'role' => $p->role,
                    'source' => $p->source,
                    'person_id' => $p->person_id,
                    'family_member_id' => $p->family_member_id,
                    'external_full_name' => $p->external_full_name,
                    'snapshot_json' => $p->snapshot_json,
                ])
                ->all();
        }

        return $attrs;
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    private function stripNonColumnAttrs(array $attrs): array
    {
        unset(
            $attrs['participants'],
            $attrs['acknowledge_duplicate_warning'],
            $attrs['acknowledge_person_match'],
            $attrs['use_person_id'],
            $attrs['family_association'],
            $attrs['family_member_id'],
            $attrs['relationship_to_head'],
            $attrs['person'],
            $attrs['family'],
            $attrs['_baptism_membership_deferred'],
            $attrs['recipient_dob'],
            $attrs['include_restricted'],
            $attrs['reason'],
            $attrs['dispensations']
        );

        return $attrs;
    }

    /**
     * @param  list<array<string, mixed>>  $participants
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function matrimonyCanonicalAttributes(SacramentType $type, array $participants, array $data): array
    {
        if (! SacramentTypeCode::isMatrimony($type->code)) {
            return [];
        }

        $derived = $this->canonicalClassifier->deriveFromParticipants($participants);
        $override = $data['marriage_canonical_classification'] ?? null;
        $classification = is_string($override) && $override !== '' ? $override : $derived;

        if ($classification !== null && $classification !== ''
            && ! in_array($classification, MarriageCanonicalClassification::all(), true)
        ) {
            throw new SacramentBusinessRuleException(
                'invalid_marriage_classification',
                'The marriage classification is not recognized.'
            );
        }

        if ($classification
            && MarriageCanonicalClassification::requiresDispensation($classification)
        ) {
            $dispensations = is_array($data['dispensations'] ?? null) ? $data['dispensations'] : [];
            $hasGrant = false;
            foreach ($dispensations as $row) {
                if (trim((string) ($row['dispensation_type'] ?? '')) !== '') {
                    $hasGrant = true;
                    break;
                }
            }
            if (! $hasGrant) {
                throw new SacramentBusinessRuleException(
                    'dispensation_required',
                    'A permission or dispensation must be recorded for this kind of marriage.'
                );
            }
        }

        return [
            'marriage_canonical_classification' => $classification,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncDispensations(Sacrament $sacrament, array $rows): void
    {
        SacramentDispensation::query()
            ->where('sacrament_id', $sacrament->id)
            ->whereNull('deleted_at')
            ->each(function (SacramentDispensation $row) {
                $row->delete();
            });

        foreach ($rows as $row) {
            $type = trim((string) ($row['dispensation_type'] ?? ''));
            if ($type === '') {
                continue;
            }
            SacramentDispensation::create([
                'tenant_id' => $sacrament->tenant_id,
                'sacrament_id' => $sacrament->id,
                'dispensation_type' => $type,
                'granting_authority' => $row['granting_authority'] ?? null,
                'protocol_number' => $row['protocol_number'] ?? null,
                'date_granted' => $row['date_granted'] ?? null,
            ]);
        }
    }
}
