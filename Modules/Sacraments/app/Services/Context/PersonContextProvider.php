<?php

namespace Modules\Sacraments\Services\Context;

use Illuminate\Validation\ValidationException;
use Modules\Family\app\Services\FamilyMemberParentNameResolver;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;
use Modules\Sacraments\Support\SacramentSourceType;

final class PersonContextProvider
{
    public function __construct(
        private readonly FamilyMemberParentNameResolver $parentNameResolver,
        private readonly ProvenanceBuilder $provenance,
    ) {}

    /**
     * @return array{
     *     person: ?Person,
     *     family_member: ?FamilyMember,
     *     canonical_identity: array<string, array<string, mixed>>
     * }
     */
    public function resolve(int|string $tenantId, ?string $familyMemberId, ?string $personId): array
    {
        $member = null;
        $person = null;

        if ($familyMemberId !== null && $familyMemberId !== '') {
            $member = FamilyMember::query()
                ->whereHas('family', fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('id', $familyMemberId)
                ->whereNull('deleted_at')
                ->with(['person', 'family'])
                ->first();

            if ($member === null) {
                throw ValidationException::withMessages([
                    'family_member_id' => 'Family member not found in this parish.',
                ]);
            }

            $person = $member->person;
            if ($person !== null && (int) $person->tenant_id !== (int) $tenantId) {
                throw ValidationException::withMessages([
                    'family_member_id' => 'Family member not found in this parish.',
                ]);
            }
        } elseif ($personId !== null && $personId !== '') {
            $person = Person::query()
                ->forTenant($tenantId)
                ->where('id', $personId)
                ->whereNull('deleted_at')
                ->first();

            if ($person === null) {
                throw ValidationException::withMessages([
                    'person_id' => 'Person not found in this parish.',
                ]);
            }

            $member = FamilyMember::query()
                ->where('person_id', $person->id)
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->whereHas('family', fn ($q) => $q->where('tenant_id', $tenantId))
                ->with('family')
                ->orderByDesc('updated_at')
                ->first();
        }

        if ($person === null && $member === null) {
            throw ValidationException::withMessages([
                'subject' => 'Either family_member_id or person_id is required.',
            ]);
        }

        if ($member !== null) {
            $this->parentNameResolver->attachToMembers([$member]);
        }

        return [
            'person' => $person,
            'family_member' => $member,
            'canonical_identity' => $this->buildCanonicalIdentity($person, $member),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildCanonicalIdentity(?Person $person, ?FamilyMember $member): array
    {
        $name = $this->resolveName($person, $member);
        $dob = $this->resolveDate($person?->date_of_birth, $member?->date_of_birth);
        $gender = $person?->gender ?? $member?->gender;
        $father = $person?->father_name ?? ($member?->getAttribute('father_name'));
        $mother = $person?->mother_name ?? ($member?->getAttribute('mother_name'));

        $sourceType = $person !== null ? SacramentSourceType::PERSON_PROFILE : SacramentSourceType::MEMBER_PROFILE;
        $sourceId = $person?->id ?? $member?->id;

        return [
            'name' => $this->provenance->canonical($name, $sourceType, $sourceId),
            'date_of_birth' => $this->provenance->canonical($dob, $sourceType, $sourceId),
            'gender' => $this->provenance->canonical($gender, $sourceType, $sourceId),
            'father_name' => $this->provenance->canonical($father, $sourceType, $sourceId),
            'mother_name' => $this->provenance->canonical($mother, $sourceType, $sourceId),
        ];
    }

    private function resolveName(?Person $person, ?FamilyMember $member): ?string
    {
        if ($person !== null) {
            return trim($person->full_name_display ?? '') ?: null;
        }

        if ($member !== null) {
            return trim($member->full_name_display ?? '') ?: null;
        }

        return null;
    }

    private function resolveDate($personDob, $memberDob): ?string
    {
        $date = $personDob ?? $memberDob;

        return $date !== null ? $date->format('Y-m-d') : null;
    }
}
