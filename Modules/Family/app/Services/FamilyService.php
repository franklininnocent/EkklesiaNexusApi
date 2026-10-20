<?php

namespace Modules\Family\app\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\BCC\Services\BccFamilyMembershipService;
use Modules\Family\app\Repositories\FamilyRepository;
use Modules\Family\Events\FamilyMemberStatusChanged;
use Modules\Family\Models\Family;
use Modules\Family\Support\PeopleLimitEligibility;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;
use Modules\Sacraments\Models\Sacrament;
use Modules\Family\Support\FamilyDashboardCache;
use Modules\Tenants\Services\Media\ImageMediaException;

class FamilyService
{
    protected FamilyRepository $familyRepository;

    protected FamilyFileUploadService $fileUploadService;

    protected FamilyAuditService $familyAuditService;

    /**
     * FamilyService constructor.
     */
    public function __construct(
        FamilyRepository $familyRepository,
        FamilyFileUploadService $fileUploadService,
        FamilyAuditService $familyAuditService,
        protected PersonService $personService,
        protected FamilyMemberParentNameResolver $parentNameResolver,
        protected PersonParentRelationshipService $parentRelationshipService,
        protected FamilyDuplicateDetectionService $duplicateDetectionService,
        protected MarriageDateSyncService $marriageDateSyncService,
    ) {
        $this->familyRepository = $familyRepository;
        $this->fileUploadService = $fileUploadService;
        $this->familyAuditService = $familyAuditService;
    }

    /**
     * Get paginated families
     */
    public function getPaginatedFamilies(string $tenantId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->familyRepository->getPaginatedFamilies($tenantId, $filters, $perPage);
    }

    /**
     * Get all families for tenant
     */
    public function getAllFamilies(string $tenantId): Collection
    {
        return $this->familyRepository->getAllFamilies($tenantId);
    }

    /**
     * Get family by ID
     */
    public function getFamilyById(string $id, string $tenantId): ?Family
    {
        $family = $this->familyRepository->findById($id, $tenantId);

        if ($family !== null && $family->relationLoaded('members') && $family->members->isNotEmpty()) {
            $this->parentNameResolver->attachToMembers($family->members);
            $this->marriageDateSyncService->attachToMembers($family->members, $tenantId);
        }

        return $family;
    }

    /**
     * Create a new family
     *
     * @throws \Exception
     */
    public function createFamily(array $data, string $tenantId, string $userId): Family
    {
        try {
            if (empty($data['allow_duplicate'])) {
                $duplicates = $this->duplicateDetectionService->findDuplicates($tenantId, $data);
                if (! empty($duplicates)) {
                    throw ValidationException::withMessages([
                        'family_name' => 'A family with the same surname, address, and phone already exists.',
                        'duplicate_candidates' => $duplicates,
                    ]);
                }
            }
            unset($data['allow_duplicate']);

            DB::beginTransaction();

            $familyStatus = $data['status'] ?? 'active';
            $peopleSlots = (! empty($data['members']) && is_array($data['members']))
                ? PeopleLimitEligibility::countPayloadsConsuming($data['members'], $familyStatus)
                : 0;
            $this->assertWithinPlanLimit($tenantId, 'FAMILY_LIMIT', 1);
            if ($peopleSlots > 0) {
                $this->assertWithinPlanLimit($tenantId, 'PEOPLE_LIMIT', $peopleSlots);
            }

            // Add tenant and audit info
            $data['tenant_id'] = $tenantId;
            $data['created_by'] = $userId;
            $data['updated_by'] = $userId;

            $family = $this->familyRepository->create($data);

            if (! empty($family->bcc_id)) {
                app(BccFamilyMembershipService::class)
                    ->syncFromFamilyPointer($family, null, (int) $tenantId);
            }

            // If members data is provided, create them
            if (! empty($data['members']) && is_array($data['members'])) {
                foreach ($data['members'] as $memberData) {
                    $memberData['created_by'] = $userId;
                    $memberData['updated_by'] = $userId;
                    $memberData = $this->ensureMemberPersonWithParents($memberData, $tenantId, $userId);
                    $this->enforceSacramentDependencies($memberData);
                    $this->familyRepository->addMember($family, $memberData);
                }
            }

            DB::commit();

            FamilyDashboardCache::bump($tenantId);

            Log::info('Family created', [
                'family_id' => $family->id,
                'family_code' => $family->family_code,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ]);

            try {
                $this->familyAuditService->log(
                    (int) $tenantId,
                    'family.created',
                    'family',
                    (string) $family->id,
                    null,
                    ['family_code' => $family->family_code],
                );
            } catch (\Throwable $e) {
                Log::warning('Family audit log failed on create', ['error' => $e->getMessage()]);
            }

            // Reload with relationships
            return $this->familyRepository->findById($family->id, $tenantId);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create family', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ]);
            throw $e;
        }
    }

    /**
     * Update family
     *
     * @throws \Exception
     */
    public function updateFamily(string $id, array $data, string $tenantId, string $userId): ?Family
    {
        try {
            $family = $this->familyRepository->findById($id, $tenantId);

            if (! $family) {
                return null;
            }

            DB::beginTransaction();

            // Optimistic locking: if client sent updated_at, ensure it matches current
            if (! empty($data['updated_at'])) {
                $clientUpdatedAt = Carbon::parse($data['updated_at']);
                if (! $family->updated_at || ! $family->updated_at->equalTo($clientUpdatedAt)) {
                    DB::rollBack();
                    throw new \RuntimeException('Conflict: record has been modified by another process.', 409);
                }
            }
            unset($data['updated_at']);

            // Extract members array if present (before updating family)
            $membersData = $data['members'] ?? null;
            unset($data['members']); // Remove members from family update data

            $peopleDelta = 0;
            $prospectiveFamilyStatus = array_key_exists('status', $data) ? (string) $data['status'] : $family->status;
            if (array_key_exists('status', $data)) {
                $peopleDelta += PeopleLimitEligibility::deltaForFamilyStatusChange($family, $prospectiveFamilyStatus);
            }
            if (! empty($membersData) && is_array($membersData)) {
                foreach ($membersData as $memberPayload) {
                    $memberId = null;
                    if (isset($memberPayload['id']) && $memberPayload['id'] !== null && $memberPayload['id'] !== '') {
                        $memberId = trim((string) $memberPayload['id']);
                    }
                    if ($memberId) {
                        $existing = $this->familyRepository->findMemberById($memberId, $family->id);
                        if ($existing) {
                            $newStatus = array_key_exists('status', $memberPayload)
                                ? (string) $memberPayload['status']
                                : $existing->status;
                            $peopleDelta += PeopleLimitEligibility::deltaForMemberStatusChange(
                                $existing,
                                $newStatus,
                                $prospectiveFamilyStatus,
                            );
                        }
                    } elseif (PeopleLimitEligibility::payloadWillConsumeSlot($memberPayload, $prospectiveFamilyStatus)) {
                        $peopleDelta++;
                    }
                }
            }
            if ($peopleDelta > 0) {
                $this->assertWithinPlanLimit($tenantId, 'PEOPLE_LIMIT', $peopleDelta);
            }

            $syncPersonAddresses = ! empty($data['sync_person_addresses']);
            unset($data['sync_person_addresses']);

            $previousBccId = $family->bcc_id;

            // Add audit info
            $data['updated_by'] = $userId;

            // Update family
            $this->familyRepository->update($family, $data);

            if ($syncPersonAddresses) {
                $this->syncPersonAddressesFromFamily($family->fresh(), $data);
            }

            if (array_key_exists('bcc_id', $data)) {
                $family->refresh();
                app(BccFamilyMembershipService::class)
                    ->syncFromFamilyPointer($family, $previousBccId, (int) $tenantId);
            }

            // Handle members if provided
            $pendingStatusChangeEvents = [];

            if (! empty($membersData) && is_array($membersData)) {
                foreach ($membersData as $memberData) {
                    // Extract and normalize member ID (could be UUID string, null, or empty)
                    // CRITICAL: Use isset() check first, then check if value is not empty string or null
                    $memberId = null;
                    if (isset($memberData['id']) && $memberData['id'] !== null && $memberData['id'] !== '') {
                        $memberId = trim((string) $memberData['id']);
                    }

                    // Log for debugging
                    Log::info('Processing family member update', [
                        'member_id' => $memberId,
                        'has_id' => ! empty($memberId),
                        'family_id' => $family->id,
                        'first_name' => $memberData['first_name'] ?? 'N/A',
                        'last_name' => $memberData['last_name'] ?? 'N/A',
                    ]);

                    unset($memberData['id']); // Remove id from update data

                    $parentPayload = $this->parentRelationshipService->extractFromMemberPayload($memberData);

                    if ($memberId) {
                        // Update existing member
                        $member = $this->familyRepository->findMemberById($memberId, $family->id);
                        if ($member) {
                            Log::info('Updating existing family member', [
                                'member_id' => $memberId,
                                'family_id' => $family->id,
                            ]);
                            $previousStatus = $member->status;
                            $memberData['updated_by'] = $userId;
                            $this->enforceSacramentDependencies($memberData, $member);
                            $this->familyRepository->updateMember($member, $memberData);
                            $this->syncMemberPersonFromPayload($member, $memberData, $parentPayload, $tenantId, $userId, $memberId);
                            $member->refresh();
                            $pendingStatusChangeEvents[] = [
                                'member' => $member,
                                'previous_status' => $previousStatus,
                            ];
                        } else {
                            // Member ID provided but not found - log error and throw exception
                            Log::error('Member ID provided but not found in database', [
                                'member_id' => $memberId,
                                'family_id' => $family->id,
                                'tenant_id' => $tenantId,
                                'member_data' => array_keys($memberData),
                            ]);
                            // Don't create a new member if ID was provided - this indicates a data integrity issue
                            throw new \RuntimeException("Member with ID {$memberId} not found for family {$family->id}. Cannot update non-existent member.");
                        }
                    } else {
                        // Create new member (no ID provided)
                        Log::info('Creating new family member (no ID provided)', [
                            'family_id' => $family->id,
                            'first_name' => $memberData['first_name'] ?? 'N/A',
                        ]);
                        $memberData['created_by'] = $userId;
                        $memberData['updated_by'] = $userId;
                        $memberData = $this->ensureMemberPersonWithParents($memberData, $tenantId, $userId);
                        $this->enforceSacramentDependencies($memberData);
                        $this->familyRepository->addMember($family, $memberData);
                    }
                }
            }

            DB::commit();

            FamilyDashboardCache::bump($tenantId);

            foreach ($pendingStatusChangeEvents as $statusChangeEvent) {
                $this->dispatchFamilyMemberStatusChangedEvent(
                    $tenantId,
                    $statusChangeEvent['member'],
                    $statusChangeEvent['previous_status'],
                );
            }

            Log::info('Family updated', [
                'family_id' => $family->id,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'members_processed' => ! empty($membersData) ? count($membersData) : 0,
            ]);

            try {
                $this->familyAuditService->log(
                    (int) $tenantId,
                    'family.updated',
                    'family',
                    (string) $family->id,
                    null,
                    ['members_processed' => ! empty($membersData) ? count($membersData) : 0],
                );
            } catch (\Throwable $e) {
                Log::warning('Family audit log failed on update', ['error' => $e->getMessage()]);
            }

            // Reload with relationships
            return $this->familyRepository->findById($id, $tenantId);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update family', [
                'family_id' => $id,
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ]);
            throw $e;
        }
    }

    /**
     * Delete family
     *
     * @throws \Exception
     */
    public function deleteFamily(string $id, string $tenantId, string $userId, bool $forceDelete = false): bool
    {
        try {
            $family = $this->familyRepository->findById($id, $tenantId);

            if (! $family) {
                return false;
            }

            $activeMemberCount = FamilyMember::query()
                ->where('family_id', $family->id)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->count();

            if ($activeMemberCount > 0 && ! $forceDelete) {
                throw ValidationException::withMessages([
                    'family_id' => 'Cannot delete a family with active members. Remove or reassign members first, or use force_delete.',
                ]);
            }

            DB::beginTransaction();

            // Delete family (this will cascade to members)
            $result = $this->familyRepository->delete($family);

            DB::commit();

            FamilyDashboardCache::bump($tenantId);

            Log::info('Family deleted', [
                'family_id' => $id,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ]);

            try {
                $this->familyAuditService->log(
                    (int) $tenantId,
                    'family.deleted',
                    'family',
                    (string) $id,
                );
            } catch (\Throwable $e) {
                Log::warning('Family audit log failed on delete', ['error' => $e->getMessage()]);
            }

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete family', [
                'family_id' => $id,
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ]);
            throw $e;
        }
    }

    /**
     * Get families by BCC
     */
    public function getFamiliesByBCC(string $bccId, string $tenantId): Collection
    {
        return $this->familyRepository->getFamiliesByBCC($bccId, $tenantId);
    }

    /**
     * Get families by parish zone
     */
    public function getFamiliesByParishZone(string $parishZoneId, string $tenantId): Collection
    {
        return $this->familyRepository->getFamiliesByParishZone($parishZoneId, $tenantId);
    }

    /**
     * Get families without BCC assignment
     */
    public function getFamiliesWithoutBCC(string $tenantId): Collection
    {
        return $this->familyRepository->getFamiliesWithoutBCC($tenantId);
    }

    /**
     * Get family statistics
     */
    public function getStatistics(string $tenantId): array
    {
        return $this->familyRepository->getStatistics($tenantId);
    }

    /**
     * Add member to family
     *
     * @throws \Exception
     */
    public function addMember(string $familyId, array $memberData, string $tenantId, string $userId): ?FamilyMember
    {
        try {
            $family = $this->familyRepository->findById($familyId, $tenantId);

            if (! $family) {
                return null;
            }

            DB::beginTransaction();

            if (PeopleLimitEligibility::payloadWillConsumeSlot($memberData, $family->status)) {
                $this->assertWithinPlanLimit($tenantId, 'PEOPLE_LIMIT', 1);
            }

            // Add audit info
            $memberData['created_by'] = $userId;
            $memberData['updated_by'] = $userId;
            $memberData = $this->ensureMemberPersonWithParents($memberData, $tenantId, $userId);

            $this->enforceSacramentDependencies($memberData);
            $this->enforceSingleActiveHead($familyId, $memberData);
            $spouseToSync = $this->marriageDateSyncService->assertAndPrepare(null, $memberData, $familyId, $tenantId);

            // Create member
            $member = $this->familyRepository->addMember($family, $memberData);

            if ($member->person_id) {
                $this->applyBaptismRegisterToMemberIfEmpty($member, (string) $member->person_id, (int) $tenantId);
            }

            $this->marriageDateSyncService->syncLinkedSpouseAndAudit(
                $member,
                $spouseToSync,
                null,
                $memberData,
                $tenantId,
                $userId
            );

            DB::commit();

            FamilyDashboardCache::bump($tenantId);

            Log::info('Family member added', [
                'member_id' => $member->id,
                'family_id' => $familyId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ]);

            return $member;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to add family member', [
                'family_id' => $familyId,
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ]);
            throw $e;
        }
    }

    /**
     * Update family member
     *
     * @throws \Exception
     */
    public function updateMember(string $familyId, string $memberId, array $data, string $tenantId, string $userId): ?FamilyMember
    {
        try {
            // Sanitize UUIDs - remove any whitespace or extra characters
            $familyId = trim($familyId);
            $memberId = trim($memberId);

            // Extract UUID pattern (36 characters with hyphens)
            if (preg_match('/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $familyId, $matches)) {
                $familyId = $matches[1];
            }
            if (preg_match('/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $memberId, $matches)) {
                $memberId = $matches[1];
            }

            // Verify family belongs to tenant
            $family = $this->familyRepository->findById($familyId, $tenantId);
            if (! $family) {
                return null;
            }

            $member = $this->familyRepository->findMemberById($memberId, $familyId);
            if (! $member) {
                Log::error('Family member not found for update - this should NOT create a new member', [
                    'member_id' => $memberId,
                    'family_id' => $familyId,
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                ]);

                return null;
            }

            DB::beginTransaction();

            $previousStatus = $member->status;
            $peopleDelta = PeopleLimitEligibility::deltaForMemberStatusChange(
                $member,
                array_key_exists('status', $data) ? (string) $data['status'] : null,
                $family->status,
            );
            if ($peopleDelta > 0) {
                $this->assertWithinPlanLimit($tenantId, 'PEOPLE_LIMIT', $peopleDelta);
            }

            // Log the data being sent for debugging
            Log::info('Updating existing family member', [
                'member_id' => $memberId,
                'family_id' => $familyId,
                'member_name' => "{$member->first_name} {$member->last_name}",
                'data_keys' => array_keys($data),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ]);

            // Add audit info
            $data['updated_by'] = $userId;

            $parentPayload = $this->parentRelationshipService->extractFromMemberPayload($data);

            $this->enforceSacramentDependencies($data, $member);
            $this->enforceSingleActiveHead($familyId, $data, $memberId);
            $previousMarriageDate = $member->marriage_date;
            $spouseToSync = $this->marriageDateSyncService->assertAndPrepare($member, $data, $familyId, $tenantId);

            // Update member
            $updated = $this->familyRepository->updateMember($member, $data);

            if (! $updated) {
                DB::rollBack();
                Log::error('Failed to update family member - update returned false', [
                    'member_id' => $memberId,
                    'family_id' => $familyId,
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'data' => $data,
                ]);
                throw new \RuntimeException('Failed to update family member');
            }

            $this->syncMemberPersonFromPayload($member, $data, $parentPayload, $tenantId, $userId, $memberId);

            $this->marriageDateSyncService->syncLinkedSpouseAndAudit(
                $member,
                $spouseToSync,
                $previousMarriageDate,
                $data,
                $tenantId,
                $userId
            );

            DB::commit();

            FamilyDashboardCache::bump($tenantId);

            $member->refresh();
            $this->dispatchFamilyMemberStatusChangedEvent($tenantId, $member, $previousStatus);

            Log::info('Family member updated', [
                'member_id' => $memberId,
                'family_id' => $familyId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'updated_fields' => array_keys($data),
            ]);

            $member = $member->fresh([
                'person:id,date_of_birth,gender,father_name,mother_name,father_person_id,mother_person_id',
                'person.father:id,first_name,middle_name,last_name,deleted_at',
                'person.mother:id,first_name,middle_name,last_name,deleted_at',
            ]);
            $this->parentNameResolver->attachToMembers([$member]);
            $this->marriageDateSyncService->attachToMembers([$member], $tenantId);

            return $member;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update family member', [
                'member_id' => $memberId,
                'family_id' => $familyId,
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ]);
            throw $e;
        }
    }

    /**
     * Delete family member
     *
     * @throws \Exception
     */
    public function deleteMember(string $familyId, string $memberId, string $tenantId, string $userId): bool
    {
        try {
            // Verify family belongs to tenant
            $family = $this->familyRepository->findById($familyId, $tenantId);
            if (! $family) {
                return false;
            }

            $member = $this->familyRepository->findMemberById($memberId, $familyId);
            if (! $member) {
                return false;
            }

            $activeMemberCount = FamilyMember::query()
                ->where('family_id', $familyId)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->count();

            if ($activeMemberCount <= 1) {
                throw ValidationException::withMessages([
                    'member_id' => 'Cannot remove the last active member. Reassign the member or delete the family.',
                ]);
            }

            $previousStatus = $member->status;
            $memberIdValue = $member->id;

            DB::beginTransaction();

            $result = $this->familyRepository->deleteMember($member);

            DB::commit();

            FamilyDashboardCache::bump($tenantId);

            $this->dispatchFamilyMemberStatusChangedEvent(
                $tenantId,
                null,
                $previousStatus,
                $memberIdValue,
                deleted: true,
            );

            Log::info('Family member deleted', [
                'member_id' => $memberIdValue,
                'family_id' => $familyId,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ]);

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete family member', [
                'member_id' => $memberId,
                'family_id' => $familyId,
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ]);
            throw $e;
        }
    }

    /**
     * Get all members of a family
     */
    public function getFamilyMembers(string $familyId, string $tenantId): ?Collection
    {
        // Verify family belongs to tenant
        $family = $this->familyRepository->findById($familyId, $tenantId);
        if (! $family) {
            return null;
        }

        $members = $this->familyRepository->getFamilyMembers($familyId);
        if ($members !== null) {
            $this->parentNameResolver->attachToMembers($members);
            $this->marriageDateSyncService->attachToMembers($members, $tenantId);
        }

        return $members;
    }

    /**
     * Upload profile image for family head
     *
     * @param  UploadedFile  $file
     *
     * @throws \Exception
     */
    public function uploadProfileImage(string $id, $file, string $tenantId, string $userId): ?Family
    {
        try {
            $family = $this->familyRepository->findById($id, $tenantId);

            if (! $family) {
                return null;
            }

            $tenantIdInt = (int) $tenantId;
            $previousPath = $family->profile_image_url;

            $this->fileUploadService->replace(
                $file,
                $tenantIdInt,
                $previousPath,
                function (string $storageKey) use ($family, $userId): void {
                    DB::transaction(function () use ($family, $storageKey, $userId): void {
                        $locked = Family::query()->whereKey($family->id)->lockForUpdate()->firstOrFail();
                        $locked->profile_image_url = $storageKey;
                        $locked->updated_by = $userId;
                        $locked->save();
                    });
                }
            );

            Log::info('Family profile image uploaded', [
                'family_id' => $id,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ]);

            try {
                $this->familyAuditService->log(
                    $tenantIdInt,
                    'family.profile_image.uploaded',
                    'family',
                    (string) $id,
                    $previousPath !== null ? ['profile_image_url' => $previousPath] : null,
                    ['profile_image_url' => '[stored]'],
                );
            } catch (\Throwable $e) {
                Log::warning('Family audit log failed on profile image upload', ['error' => $e->getMessage()]);
            }

            return $this->familyRepository->findById($id, $tenantId);

        } catch (ImageMediaException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to upload family profile image', [
                'family_id' => $id,
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ]);
            throw $e;
        }
    }

    /**
     * Delete profile image for family
     *
     * @throws \Exception
     */
    public function deleteProfileImage(string $id, string $tenantId, string $userId): ?Family
    {
        try {
            $family = $this->familyRepository->findById($id, $tenantId);

            if (! $family) {
                return null;
            }

            if (! $family->profile_image_url) {
                return $family;
            }

            $tenantIdInt = (int) $tenantId;
            $previousPath = $family->profile_image_url;

            DB::transaction(function () use ($family, $userId): void {
                $locked = Family::query()->whereKey($family->id)->lockForUpdate()->firstOrFail();
                $locked->profile_image_url = null;
                $locked->updated_by = $userId;
                $locked->save();
            });

            $this->fileUploadService->deletePair($previousPath, $tenantIdInt);

            Log::info('Family profile image deleted', [
                'family_id' => $id,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ]);

            try {
                $this->familyAuditService->log(
                    $tenantIdInt,
                    'family.profile_image.deleted',
                    'family',
                    (string) $id,
                    ['profile_image_url' => $previousPath],
                );
            } catch (\Throwable $e) {
                Log::warning('Family audit log failed on profile image delete', ['error' => $e->getMessage()]);
            }

            return $this->familyRepository->findById($id, $tenantId);

        } catch (\Exception $e) {
            Log::error('Failed to delete family profile image', [
                'family_id' => $id,
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ]);
            throw $e;
        }
    }

    /**
     * Upload profile image for family head
     *
     * @param  mixed  $file
     *
     * @throws \Exception
     */
    public function uploadHeadProfileImage(string $id, $file, string $tenantId, string $userId): ?Family
    {
        try {
            $family = $this->familyRepository->findById($id, $tenantId);

            if (! $family) {
                return null;
            }

            $tenantIdInt = (int) $tenantId;
            $previousPath = $family->head_profile_image_url;

            $this->fileUploadService->replace(
                $file,
                $tenantIdInt,
                $previousPath,
                function (string $storageKey) use ($family, $userId): void {
                    DB::transaction(function () use ($family, $storageKey, $userId): void {
                        $locked = Family::query()->whereKey($family->id)->lockForUpdate()->firstOrFail();
                        $locked->head_profile_image_url = $storageKey;
                        $locked->updated_by = $userId;
                        $locked->save();
                    });
                }
            );

            Log::info('Family head profile image uploaded', [
                'family_id' => $id,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ]);

            try {
                $this->familyAuditService->log(
                    $tenantIdInt,
                    'family.head_profile_image.uploaded',
                    'family',
                    (string) $id,
                    $previousPath !== null ? ['head_profile_image_url' => $previousPath] : null,
                    ['head_profile_image_url' => '[stored]'],
                );
            } catch (\Throwable $e) {
                Log::warning('Family audit log failed on head profile image upload', ['error' => $e->getMessage()]);
            }

            return $this->familyRepository->findById($id, $tenantId);

        } catch (ImageMediaException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to upload family head profile image', [
                'family_id' => $id,
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ]);
            throw $e;
        }
    }

    /**
     * Delete profile image for family head
     *
     * @throws \Exception
     */
    public function deleteHeadProfileImage(string $id, string $tenantId, string $userId): ?Family
    {
        try {
            $family = $this->familyRepository->findById($id, $tenantId);

            if (! $family) {
                return null;
            }

            if (! $family->head_profile_image_url) {
                return $family;
            }

            $tenantIdInt = (int) $tenantId;
            $previousPath = $family->head_profile_image_url;

            DB::transaction(function () use ($family, $userId): void {
                $locked = Family::query()->whereKey($family->id)->lockForUpdate()->firstOrFail();
                $locked->head_profile_image_url = null;
                $locked->updated_by = $userId;
                $locked->save();
            });

            $this->fileUploadService->deletePair($previousPath, $tenantIdInt);

            Log::info('Family head profile image deleted', [
                'family_id' => $id,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ]);

            try {
                $this->familyAuditService->log(
                    $tenantIdInt,
                    'family.head_profile_image.deleted',
                    'family',
                    (string) $id,
                    ['head_profile_image_url' => $previousPath],
                );
            } catch (\Throwable $e) {
                Log::warning('Family audit log failed on head profile image delete', ['error' => $e->getMessage()]);
            }

            return $this->familyRepository->findById($id, $tenantId);

        } catch (\Exception $e) {
            Log::error('Failed to delete family head profile image', [
                'family_id' => $id,
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ]);
            throw $e;
        }
    }

    /**
     * Ensure sacramental prerequisites are satisfied.
     *
     * @throws ValidationException
     */
    private function enforceSacramentDependencies(array $memberData, ?FamilyMember $existingMember = null): void
    {
        $resolve = function (string $key) use ($memberData, $existingMember) {
            if (array_key_exists($key, $memberData)) {
                return $memberData[$key];
            }

            return $existingMember?->{$key} ?? null;
        };

        $hasBaptism = $this->hasSacramentValue($resolve('baptism_date'));
        $hasConfirmation = $this->hasSacramentValue($resolve('confirmation_date'));
        $hasFirstCommunion = $this->hasSacramentValue($resolve('first_communion_date'));
        $hasMarriage = $this->hasSacramentValue($resolve('marriage_date'));

        $errors = [];

        if (($hasFirstCommunion || $hasConfirmation || $hasMarriage) && ! $hasBaptism) {
            if ($hasFirstCommunion) {
                $errors['first_communion_date'][] = 'Baptism must be recorded before First Communion.';
            }

            if ($hasConfirmation) {
                $errors['confirmation_date'][] = 'Baptism must be recorded before Confirmation.';
            }

            if ($hasMarriage) {
                $errors['marriage_date'][] = 'Baptism must be recorded before Marriage.';
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function hasSacramentValue(mixed $value): bool
    {
        if ($value instanceof Carbon) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        return ! empty($value);
    }

    /**
     * Get all members across all families for a tenant with pagination and filters
     */
    public function getAllMembers(string $tenantId, array $filters = [], int $perPage = 10, int $page = 1): LengthAwarePaginator
    {
        $paginator = $this->familyRepository->getAllMembers($tenantId, $filters, $perPage, $page);
        $this->parentNameResolver->attachToMembers($paginator->items());

        return $paginator;
    }

    private function dispatchFamilyMemberStatusChangedEvent(
        int|string $tenantId,
        ?FamilyMember $member,
        string $previousStatus,
        ?string $familyMemberId = null,
        bool $deleted = false,
    ): void {
        $familyMemberId ??= $member?->id;

        if ($familyMemberId === null) {
            return;
        }

        if ($deleted) {
            if (! $this->shouldEmitCensusStatusEvent($previousStatus, 'deleted')) {
                return;
            }

            FamilyMemberStatusChanged::dispatch(
                (int) $tenantId,
                $familyMemberId,
                $previousStatus,
                'deleted',
                now()->toDateString(),
            );

            return;
        }

        if ($member === null) {
            return;
        }

        $newStatus = $this->normalizeCensusEventStatus($member->status);

        if ($newStatus === null || ! $this->shouldEmitCensusStatusEvent($previousStatus, $newStatus)) {
            return;
        }

        FamilyMemberStatusChanged::dispatch(
            (int) $tenantId,
            $member->id,
            $previousStatus,
            $newStatus,
            $this->resolveCensusEffectiveDate($member, $newStatus),
        );
    }

    private function shouldEmitCensusStatusEvent(string $previousStatus, string $newStatus): bool
    {
        $triggers = ['inactive', 'deceased', 'transferred', 'deleted'];

        if (! in_array($newStatus, $triggers, true)) {
            return false;
        }

        $normalizedPrevious = $this->normalizeCensusEventStatus($previousStatus) ?? $previousStatus;

        if ($normalizedPrevious === 'migrated') {
            $normalizedPrevious = 'transferred';
        }

        if (in_array($normalizedPrevious, $triggers, true)) {
            return false;
        }

        return $normalizedPrevious !== $newStatus;
    }

    private function normalizeCensusEventStatus(string $status): ?string
    {
        return match ($status) {
            'migrated' => 'transferred',
            'inactive', 'deceased' => $status,
            'deleted' => 'deleted',
            default => null,
        };
    }

    private function resolveCensusEffectiveDate(FamilyMember $member, string $newStatus): string
    {
        if ($newStatus === 'deceased' && $member->deceased_date !== null) {
            return $member->deceased_date->toDateString();
        }

        return now()->toDateString();
    }

    /**
     * Link an existing unaffiliated Person to a Family (ADR-24 Scenario D).
     * Current business rule: one active FamilyMember per Person.
     *
     * @param  array<string, mixed>  $memberData
     */
    public function linkPersonToFamily(
        string $familyId,
        string $personId,
        array $memberData,
        int|string $tenantId,
        int|string $userId
    ): FamilyMember {
        $family = $this->familyRepository->findById($familyId, (string) $tenantId);
        if (! $family) {
            throw ValidationException::withMessages([
                'family_id' => 'Family not found in this parish.',
            ]);
        }

        $person = $this->personService->resolve($personId, $tenantId);
        if ($this->personService->hasActiveFamilyMembership($person->id)) {
            throw ValidationException::withMessages([
                'person_id' => 'This person already belongs to a family.',
            ]);
        }

        $this->assertWithinPlanLimit($tenantId, 'PEOPLE_LIMIT', 1, 'sacrament_recipient');

        $memberData['person_id'] = $person->id;
        $memberData['created_by'] = $userId;
        $memberData['updated_by'] = $userId;
        $memberData = $this->personService->memberIdentityFromPerson($person, $memberData);
        $this->enforceSacramentDependencies($memberData);
        $this->enforceSingleActiveHead($familyId, $memberData);

        $member = $this->familyRepository->addMember($family, $memberData);
        $this->applyBaptismRegisterToMemberIfEmpty($member, $person->id, (int) $tenantId);

        return $member;
    }

    /**
     * Create family + attach existing Person as member (caller owns the transaction).
     *
     * @param  array<string, mixed>  $familyData
     * @param  array<string, mixed>  $memberData
     * @return array{family: Family, person: Person, member: FamilyMember}
     */
    public function createFamilyWithPerson(
        array $familyData,
        Person $person,
        array $memberData,
        int|string $tenantId,
        int|string $userId
    ): array {
        $familyData['tenant_id'] = $tenantId;
        $familyData['created_by'] = $userId;
        $familyData['updated_by'] = $userId;
        unset($familyData['members']);

        $this->assertWithinPlanLimit($tenantId, 'FAMILY_LIMIT', 1, 'sacrament_recipient');
        $this->assertWithinPlanLimit($tenantId, 'PEOPLE_LIMIT', 1, 'sacrament_recipient');

        $family = $this->familyRepository->create($familyData);

        $memberData['person_id'] = $person->id;
        $memberData['created_by'] = $userId;
        $memberData['updated_by'] = $userId;
        $memberData = $this->personService->memberIdentityFromPerson($person, $memberData);
        if (empty($memberData['relationship_to_head'])) {
            $memberData['relationship_to_head'] = 'self';
        }
        $this->enforceSacramentDependencies($memberData);
        $this->enforceSingleActiveHead((string) $family->id, $memberData);
        $member = $this->familyRepository->addMember($family, $memberData);
        $this->applyBaptismRegisterToMemberIfEmpty($member, $person->id, (int) $tenantId);

        return ['family' => $family, 'person' => $person, 'member' => $member];
    }

    private function assertWithinPlanLimit(int|string $tenantId, string $metricCode, int $adding, ?string $flow = null): void
    {
        if (app()->bound(TenantLimitGuard::class)) {
            app(TenantLimitGuard::class)->assertTenantCanAdd((int) $tenantId, $metricCode, $adding, $flow);
        }
    }

    /**
     * @param  array<string, mixed>  $memberData
     * @return array<string, mixed>
     */
    private function ensureMemberPersonWithParents(array $memberData, int|string $tenantId, int|string $userId): array
    {
        $parentPayload = $this->parentRelationshipService->extractFromMemberPayload($memberData);
        $memberData = $this->ensureMemberPerson($memberData, $tenantId, $userId);

        if (! empty($memberData['person_id'])) {
            $person = Person::query()->find($memberData['person_id']);
            if ($person) {
                $this->parentRelationshipService->applyToPerson(
                    $person,
                    $parentPayload,
                    $tenantId,
                    (int) $userId,
                    [
                        'service' => $this->familyAuditService,
                        'tenant_id' => $tenantId,
                        'member_id' => $memberData['id'] ?? null,
                    ]
                );
            }
        }

        return $memberData;
    }

    /**
     * @param  array<string, mixed>  $memberData
     * @param  array<string, mixed>  $parentPayload
     */
    private function syncMemberPersonFromPayload(
        FamilyMember $member,
        array $memberData,
        array $parentPayload,
        int|string $tenantId,
        int|string $userId,
        ?string $memberId = null
    ): void {
        if (! $member->person_id) {
            return;
        }

        $person = Person::query()->find($member->person_id);
        if (! $person) {
            return;
        }

        $this->personService->syncFromFamilyMember($person, $memberData, (int) $userId);
        $this->parentRelationshipService->applyToPerson(
            $person,
            $parentPayload,
            $tenantId,
            (int) $userId,
            [
                'service' => $this->familyAuditService,
                'tenant_id' => $tenantId,
                'member_id' => $memberId ?? $member->id,
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $memberData
     * @return array<string, mixed>
     */
    private function ensureMemberPerson(array $memberData, int|string $tenantId, int|string $userId): array
    {
        if (! empty($memberData['person_id'])) {
            $person = $this->personService->resolve((string) $memberData['person_id'], $tenantId);
            if ($this->personService->hasActiveFamilyMembership($person->id)) {
                throw ValidationException::withMessages([
                    'person_id' => 'This person already belongs to a family.',
                ]);
            }

            return $this->personService->memberIdentityFromPerson($person, $memberData);
        }

        $person = $this->personService->create([
            'first_name' => $memberData['first_name'] ?? '',
            'middle_name' => $memberData['middle_name'] ?? null,
            'last_name' => $memberData['last_name'] ?? '',
            'date_of_birth' => $memberData['date_of_birth'] ?? null,
            'gender' => $memberData['gender'] ?? null,
            'phone' => $memberData['phone'] ?? null,
            'email' => $memberData['email'] ?? null,
        ], $tenantId, (int) $userId);

        $memberData['person_id'] = $person->id;

        return $memberData;
    }

    /**
     * @param  array<string, mixed>  $memberData
     */
    private function enforceSingleActiveHead(string $familyId, array $memberData, ?string $excludeMemberId = null): void
    {
        $relationship = strtolower((string) ($memberData['relationship_to_head'] ?? ''));
        if (! in_array($relationship, ['self', 'head'], true)) {
            return;
        }

        $query = FamilyMember::query()
            ->where('family_id', $familyId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->whereIn('relationship_to_head', ['self', 'head']);

        if ($excludeMemberId) {
            $query->where('id', '!=', $excludeMemberId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'relationship_to_head' => 'This family already has an active head of household. Demote the current head first.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $addressData
     */
    private function syncPersonAddressesFromFamily(Family $family, array $addressData): void
    {
        $payload = array_filter([
            'address_line_1' => $addressData['address_line_1'] ?? $family->address_line_1,
            'address_line_2' => $addressData['address_line_2'] ?? $family->address_line_2,
            'city' => $addressData['city'] ?? $family->city,
            'postal_code' => $addressData['postal_code'] ?? $family->postal_code,
        ], fn ($value) => $value !== null);

        if ($payload === []) {
            return;
        }

        $personIds = FamilyMember::query()
            ->where('family_id', $family->id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->whereNotNull('person_id')
            ->pluck('person_id');

        Person::query()
            ->whereIn('id', $personIds)
            ->update($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function submitUpdateRequest(string $familyId, array $payload, int|string $tenantId, int|string $userId): void
    {
        $family = $this->familyRepository->findById($familyId, (string) $tenantId);
        if (! $family) {
            throw ValidationException::withMessages([
                'family_id' => 'Family not found in this parish.',
            ]);
        }

        $this->familyAuditService->log(
            (int) $tenantId,
            'family.update_requested',
            'family',
            (string) $familyId,
            null,
            $payload,
            ['requested_by' => $userId],
        );
    }

    /**
     * When linking a baptized Person, copy the baptismal register date if the profile is empty.
     */
    private function applyBaptismRegisterToMemberIfEmpty(
        FamilyMember $member,
        string $personId,
        int $tenantId
    ): void {
        if ($member->baptism_date !== null) {
            return;
        }

        if (! class_exists(Sacrament::class)) {
            return;
        }

        $baptism = Sacrament::query()
            ->where('tenant_id', $tenantId)
            ->where('person_id', $personId)
            ->whereNull('deleted_at')
            ->whereHas('sacramentType', fn ($q) => $q->where('code', 'BAPTISM'))
            ->orderByDesc('date_administered')
            ->first();

        if (! $baptism) {
            return;
        }

        $date = $baptism->date_administered;
        if ($date instanceof \DateTimeInterface) {
            $date = $date->format('Y-m-d');
        }

        $member->update([
            'baptism_date' => $date,
            'baptism_place' => $baptism->place_administered,
        ]);
    }
}
