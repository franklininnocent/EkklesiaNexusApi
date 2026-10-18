<?php

namespace Modules\Family\app\Repositories;

use App\Support\CaseInsensitiveSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\BCC\Support\BccAgeBands;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Services\OccupationClassificationService;
use Modules\Family\Services\ParishMemberMissingSacramentQuery;
use Modules\Family\Support\FamilyQueryFilters;
use Modules\Family\Support\MemberDirectorySearch;
use Modules\Family\Support\MemberEducationQualification;
use Modules\Family\Support\ParishProgressionFilter;
use Modules\Tenants\Support\TenantQueryGuard;

class FamilyRepository
{
    /**
     * Get paginated families for a tenant with optional filters
     */
    public function getPaginatedFamilies(string $tenantId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Family::where('tenant_id', $tenantId)
            ->with([
                'bcc:id,name',
                'members' => fn ($memberQuery) => $memberQuery->select([
                    'id',
                    'family_id',
                    'first_name',
                    'last_name',
                    'relationship_to_head',
                    'is_primary_contact',
                    'status',
                    'phone',
                    'email',
                ])->where('status', 'active'),
            ]);

        if (! empty($filters['family_id'])) {
            $query->where('id', $filters['family_id']);
        }

        // Apply filters
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $pattern = "%{$search}%";
            $query->where(function ($q) use ($pattern) {
                CaseInsensitiveSearch::applyColumnLike($q, 'family_name', $pattern);
                CaseInsensitiveSearch::applyColumnLike($q, 'family_code', $pattern, 'or');
                CaseInsensitiveSearch::applyColumnLike($q, 'head_of_family', $pattern, 'or');
                $q->orWhereHas('members', function ($memberQuery) use ($pattern) {
                    $memberQuery->where(function ($mq) use ($pattern) {
                        CaseInsensitiveSearch::applyMemberFullNameLike($mq, $pattern);
                        CaseInsensitiveSearch::applyColumnLike($mq, 'phone', $pattern, 'or');
                        CaseInsensitiveSearch::applyColumnLike($mq, 'email', $pattern, 'or');
                    });
                });
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        FamilyQueryFilters::applyBcc($query, $filters['bcc_id'] ?? null);

        if (! empty($filters['city_exact'])) {
            FamilyQueryFilters::applyCityExact($query, $filters['city_exact']);
        } elseif (! empty($filters['city'])) {
            CaseInsensitiveSearch::applyColumnLike($query, 'city', "%{$filters['city']}%");
        }

        FamilyQueryFilters::applyFamilyMissing($query, $tenantId, $filters['missing'] ?? null);
        FamilyQueryFilters::applySizeBand($query, $tenantId, $filters['size_band'] ?? null);
        FamilyQueryFilters::applyHousehold($query, $tenantId, $filters['household'] ?? null);

        if (! empty($filters['created_from'])) {
            $query->whereDate('created_at', '>=', $filters['created_from']);
        }
        if (! empty($filters['created_to'])) {
            $query->whereDate('created_at', '<=', $filters['created_to']);
        }

        if (! empty($filters['missing_sacrament'])) {
            $familyIds = app(ParishMemberMissingSacramentQuery::class)->familyIdsForMissingSacrament(
                $tenantId,
                (string) $filters['missing_sacrament'],
                ! empty($filters['bcc_id']) ? (string) $filters['bcc_id'] : null
            );

            if ($familyIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('id', $familyIds);
            }
        } elseif (! empty($filters['progression'])) {
            $progression = ParishProgressionFilter::normalize((string) $filters['progression']);
            if ($progression === null) {
                $query->whereRaw('1 = 0');
            } else {
                $familyIds = app(ParishMemberMissingSacramentQuery::class)->familyIdsForProgression(
                    $tenantId,
                    $progression,
                    ! empty($filters['bcc_id']) ? (string) $filters['bcc_id'] : null
                );

                if ($familyIds === []) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('id', $familyIds);
                }
            }
        }

        [$sortBy, $sortOrder] = $this->resolveSort(
            $filters['sort_by'] ?? null,
            $filters['sort_order'] ?? null,
            ['created_at', 'updated_at', 'family_name', 'family_code', 'head_of_family', 'bcc_name', 'status'],
            'family_code',
            'asc'
        );

        if ($sortBy === 'bcc_name') {
            $query->orderByRaw(
                "(SELECT name FROM bccs WHERE bccs.id = families.bcc_id LIMIT 1) {$sortOrder}"
            );
        } else {
            $query->orderBy($sortBy, $sortOrder);
        }

        return $query->paginate($perPage);
    }

    /**
     * Get all families for a tenant
     */
    public function getAllFamilies(string $tenantId): Collection
    {
        return Family::where('tenant_id', $tenantId)
            ->with(['bcc', 'members'])
            ->orderBy('family_name')
            ->get();
    }

    /**
     * Find family by ID
     */
    public function findById(string $id, string $tenantId): ?Family
    {
        return Family::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with([
                'bcc',
                'country',
                'state',
                'members' => function ($query) {
                    $query->with([
                        'person:id,date_of_birth,gender,father_name,mother_name,father_person_id,mother_person_id',
                        'person.father:id,first_name,middle_name,last_name,deleted_at',
                        'person.mother:id,first_name,middle_name,last_name,deleted_at',
                    ])->orderBy('relationship_to_head');
                },
                'creator:id,name',
                'updater:id,name',
            ])
            ->first();
    }

    /**
     * Find family by family code
     */
    public function findByFamilyCode(string $familyCode, string $tenantId): ?Family
    {
        return Family::where('family_code', $familyCode)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    /**
     * Create a new family
     */
    public function create(array $data): Family
    {
        return Family::create($data);
    }

    /**
     * Update family
     */
    public function update(Family $family, array $data): bool
    {
        unset($data['tenant_id']);

        return $family->update($data);
    }

    /**
     * Delete family (soft delete)
     */
    public function delete(Family $family): ?bool
    {
        return $family->delete();
    }

    /**
     * Get families by BCC
     */
    public function getFamiliesByBCC(string $bccId, string $tenantId): Collection
    {
        return Family::where('tenant_id', $tenantId)
            ->where('bcc_id', $bccId)
            ->with(['members'])
            ->orderBy('family_name')
            ->get();
    }

    /**
     * Get families by parish zone
     */
    public function getFamiliesByParishZone(string $parishZoneId, string $tenantId): Collection
    {
        return Family::where('tenant_id', $tenantId)
            ->where('parish_zone_id', $parishZoneId)
            ->with(['members'])
            ->orderBy('family_name')
            ->get();
    }

    /**
     * Get families without BCC
     */
    public function getFamiliesWithoutBCC(string $tenantId): Collection
    {
        return Family::where('tenant_id', $tenantId)
            ->whereNull('bcc_id')
            ->orderBy('family_name')
            ->get();
    }

    /**
     * Get family statistics for tenant
     */
    public function getStatistics(string $tenantId): array
    {
        $familyRow = Family::query()
            ->where('tenant_id', $tenantId)
            ->selectRaw('COUNT(*) as total_families')
            ->selectRaw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_families")
            ->selectRaw('SUM(CASE WHEN bcc_id IS NOT NULL THEN 1 ELSE 0 END) as families_with_bcc')
            ->selectRaw('SUM(CASE WHEN bcc_id IS NULL THEN 1 ELSE 0 END) as families_without_bcc')
            ->first();

        $totalFamilies = (int) ($familyRow->total_families ?? 0);
        $activeFamilies = (int) ($familyRow->active_families ?? 0);
        $familiesWithBCC = (int) ($familyRow->families_with_bcc ?? 0);
        $familiesWithoutBCC = (int) ($familyRow->families_without_bcc ?? 0);

        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();
        $memberRow = $this->membersForTenant($tenantId)
            ->selectRaw('COUNT(*) as total_members')
            ->selectRaw("SUM(CASE WHEN family_members.status = 'active' THEN 1 ELSE 0 END) as active_members")
            ->selectRaw(
                'SUM(CASE WHEN family_members.created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as members_created_this_month',
                [$monthStart, $monthEnd]
            )
            ->first();

        $totalMembers = (int) ($memberRow->total_members ?? 0);
        $activeMembers = (int) ($memberRow->active_members ?? 0);
        $membersCreatedThisMonth = (int) ($memberRow->members_created_this_month ?? 0);

        // Parish Zone removed; keep key for compatibility but empty list
        $familiesByZone = collect();

        return [
            'total_families' => $totalFamilies,
            'active_families' => $activeFamilies,
            'inactive_families' => $totalFamilies - $activeFamilies,
            'total_members' => $totalMembers,
            'active_members' => $activeMembers,
            'members_created_this_month' => $membersCreatedThisMonth,
            'families_with_bcc' => $familiesWithBCC,
            'families_without_bcc' => $familiesWithoutBCC,
            'families_by_zone' => $familiesByZone,
        ];
    }

    /**
     * Add member to family
     */
    public function addMember(Family $family, array $memberData): FamilyMember
    {
        $memberData['family_id'] = $family->id;
        if (! array_key_exists('baptism_priest_is_home', $memberData) || $memberData['baptism_priest_is_home'] === null) {
            $memberData['baptism_priest_is_home'] = false;
        }
        $member = FamilyMember::create($memberData);
        $this->syncHeadOfFamily($family->id);

        return $member;
    }

    /**
     * Update family member
     */
    public function updateMember(FamilyMember $member, array $data): bool
    {
        // CRITICAL: Ensure we're updating an existing model, not creating a new one
        if (! $member->exists) {
            \Log::error('Attempted to update non-existent member model', [
                'member_id' => $member->id ?? 'none',
                'family_id' => $member->family_id ?? 'none',
            ]);

            return false;
        }

        // Store original ID to verify it doesn't change
        $originalId = $member->id;

        $updated = $member->update($data);

        if ($updated) {
            // Verify the ID hasn't changed (should never happen, but safety check)
            if ($member->id !== $originalId) {
                \Log::error('CRITICAL: Member ID changed during update - this should never happen!', [
                    'original_id' => $originalId,
                    'new_id' => $member->id,
                    'family_id' => $member->family_id,
                ]);

                return false;
            }

            // Refresh the model to ensure we have the latest data
            $member->refresh();

            $this->syncHeadOfFamily($member->family_id);
        }

        return $updated;
    }

    /**
     * Delete family member
     */
    public function deleteMember(FamilyMember $member): ?bool
    {
        $familyId = (string) $member->family_id;
        $deleted = $member->delete();
        if ($deleted) {
            $this->syncHeadOfFamily($familyId);
        }

        return $deleted;
    }

    /**
     * Get member by ID
     */
    public function findMemberById(string $memberId, string $familyId): ?FamilyMember
    {
        return FamilyMember::where('id', $memberId)
            ->where('family_id', $familyId)
            ->first();
    }

    /**
     * Get all members of a family
     */
    public function getFamilyMembers(string $familyId): Collection
    {
        return FamilyMember::where('family_id', $familyId)
            ->with([
                'person:id,date_of_birth,gender,father_name,mother_name,father_person_id,mother_person_id',
                'person.father:id,first_name,middle_name,last_name,deleted_at',
                'person.mother:id,first_name,middle_name,last_name,deleted_at',
            ])
            ->orderBy('relationship_to_head')
            ->orderBy('date_of_birth')
            ->get();
    }

    /**
     * Sync the family's head_of_family field from active members
     */
    public function syncHeadOfFamily(string $familyId): void
    {
        $family = TenantQueryGuard::apply(Family::query()->whereKey($familyId))->first();
        if (! $family) {
            return;
        }

        $headMember = FamilyMember::query()
            ->where('family_id', $familyId)
            ->whereIn('relationship_to_head', ['self', 'head'])
            ->where('status', 'active')
            ->orderBy('created_at')
            ->first();

        if ($headMember === null) {
            $family->update(['head_of_family' => null]);

            return;
        }

        $family->update([
            'head_of_family' => trim("{$headMember->first_name} {$headMember->last_name}"),
        ]);
    }

    /**
     * Get all members across all families for a tenant with pagination and filters
     */
    public function getAllMembers(string $tenantId, array $filters = [], int $perPage = 10, int $page = 1): LengthAwarePaginator
    {
        $query = $this->membersForTenant($tenantId, ! empty($filters['bcc_id']) ? (string) $filters['bcc_id'] : null)
            ->with(['family' => function ($q) {
                $q->with(['bcc:id,name,bcc_code']);
            }, 'person:id,date_of_birth,gender,father_name,mother_name,father_person_id,mother_person_id', 'person.father:id,first_name,middle_name,last_name,deleted_at', 'person.mother:id,first_name,middle_name,last_name,deleted_at']);

        $search = trim((string) ($filters['search'] ?? ''));
        if (mb_strlen($search) >= 2) {
            MemberDirectorySearch::apply($query, '%'.$search.'%', $tenantId);
        }

        // Apply status filter
        if (! empty($filters['status'])) {
            $query->where('family_members.status', $filters['status']);
        }

        if (! empty($filters['family_status'])) {
            $query->whereHas('family', function ($familyQuery) use ($filters): void {
                FamilyQueryFilters::applyFamilyStatus($familyQuery, $filters['family_status']);
            });
        }

        FamilyQueryFilters::applyGender($query, $filters['gender'] ?? null);
        FamilyQueryFilters::applyMemberMissing($query, $filters['missing'] ?? null);
        OccupationClassificationService::applyListFilter($query, $filters['occupation'] ?? null);
        MemberEducationQualification::applyListFilter($query, $filters['education'] ?? null);

        // Apply relationship filter (to identify family heads)
        if (! empty($filters['is_head'])) {
            if ($filters['is_head'] === 'true' || $filters['is_head'] === true) {
                $query->whereIn('relationship_to_head', ['self', 'head']);
            }
        }

        if (! empty($filters['progression'])) {
            $progression = ParishProgressionFilter::normalize((string) $filters['progression']);
            if ($progression === null) {
                $query->whereRaw('1 = 0');
            } else {
                $memberIds = app(ParishMemberMissingSacramentQuery::class)->memberIdsForProgression(
                    $tenantId,
                    $progression,
                    ! empty($filters['bcc_id']) ? (string) $filters['bcc_id'] : null
                );

                if ($memberIds === []) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('family_members.id', $memberIds);
                }
            }
        }

        $this->applyAgeBandFilter($query, $filters['age_band'] ?? null);

        [$sortBy, $sortOrder] = $this->resolveSort(
            $filters['sort_by'] ?? null,
            $filters['sort_order'] ?? null,
            ['name', 'first_name', 'last_name', 'address', 'bcc', 'father_name', 'created_at', 'status', 'gender'],
            'name',
            'asc'
        );

        // Handle special sorting cases
        if ($sortBy === 'name' || $sortBy === 'first_name') {
            $this->applyMemberNameOrder($query, $sortOrder);
        } elseif ($sortBy === 'address') {
            // Sort by family address (address_line_1)
            $query->join('families', 'family_members.family_id', '=', 'families.id')
                ->orderBy('families.address_line_1', $sortOrder)
                ->select('family_members.*'); // Select only member columns to avoid conflicts
        } elseif ($sortBy === 'bcc') {
            // Sort by BCC name
            $query->join('families', 'family_members.family_id', '=', 'families.id')
                ->leftJoin('bccs', 'families.bcc_id', '=', 'bccs.id')
                ->orderBy('bccs.name', $sortOrder)
                ->select('family_members.*'); // Select only member columns to avoid conflicts
        } elseif ($sortBy === 'father_name') {
            // Sort by father's name - this requires a subquery or complex join
            // For now, we'll sort by a placeholder or skip sorting by father_name
            // Note: This is complex as father_name is not a direct column
            // We'll need to implement a subquery or handle it differently
            // For simplicity, we'll sort by last_name as a fallback
            $query->orderBy('last_name', $sortOrder);
        } elseif (in_array($sortBy, ['last_name', 'created_at', 'status', 'gender'], true)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $this->applyMemberNameOrder($query, $sortOrder);
        }

        // Use paginate with explicit page number
        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Restrict members to a BccAgeBands key using the same AGE() case as dashboard counts.
     *
     * @param  Builder<FamilyMember>  $query
     */
    private function applyAgeBandFilter(Builder $query, mixed $ageBand): void
    {
        if (! is_string($ageBand) || $ageBand === '' || ! in_array($ageBand, BccAgeBands::keys(), true)) {
            return;
        }

        $ageYears = BccAgeBands::ageYearsSql();
        $ageSql = BccAgeBands::sqlCase(
            "CASE WHEN family_members.date_of_birth IS NULL THEN NULL ELSE {$ageYears} END"
        );
        $query->whereRaw("{$ageSql} = ?", [$ageBand]);
    }

    /**
     * Members in this tenant whose family still exists.
     * Uses family_members.tenant_id (NOT NULL) and excludes soft-deleted families.
     *
     * @return Builder<FamilyMember>
     */
    private function membersForTenant(string $tenantId, ?string $bccId = null): Builder
    {
        return FamilyMember::query()
            ->where('family_members.tenant_id', $tenantId)
            ->whereIn('family_members.family_id', function ($query) use ($tenantId, $bccId): void {
                $query->select('id')
                    ->from('families')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at');

                if ($bccId !== null && $bccId !== '') {
                    FamilyQueryFilters::applyBcc($query, $bccId);
                }
            });
    }

    /**
     * @param  list<string>  $allowed
     * @return array{0: string, 1: string}
     */
    private function resolveSort(mixed $column, mixed $direction, array $allowed, string $defaultColumn, string $defaultDirection): array
    {
        $column = is_string($column) ? $column : $defaultColumn;
        if (! in_array($column, $allowed, true)) {
            $column = $defaultColumn;
        }

        $direction = is_string($direction) ? strtolower($direction) : $defaultDirection;
        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = $defaultDirection;
        }

        return [$column, $direction];
    }

    /**
     * Sort members by displayed full name: first, middle, then last (case-insensitive).
     *
     * @param  Builder<FamilyMember>  $query
     */
    private function applyMemberNameOrder(Builder $query, string $sortOrder): void
    {
        $direction = strtolower($sortOrder) === 'desc' ? 'desc' : 'asc';
        $table = $query->getModel()->getTable();

        $query->orderByRaw("LOWER(COALESCE({$table}.first_name, '')) {$direction}")
            ->orderByRaw("LOWER(COALESCE({$table}.middle_name, '')) {$direction}")
            ->orderByRaw("LOWER(COALESCE({$table}.last_name, '')) {$direction}");
    }
}
