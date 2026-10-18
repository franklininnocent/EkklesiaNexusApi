<?php

namespace Modules\Sacraments\Services\Context;

use Modules\Family\Models\FamilyMember;
use Modules\Sacraments\Support\SacramentFieldState;
use Modules\Sacraments\Support\SacramentRecordStatus;
use Modules\Sacraments\Support\SacramentSourceType;

final class MemberProfileSacramentEvidenceProvider
{
    public function __construct(
        private readonly ProvenanceBuilder $provenance,
    ) {}

    /**
     * @param  array<string, mixed>  $parish
     * @return array<string, mixed>
     */
    public function resolveBaptism(?FamilyMember $member, array $parish): array
    {
        if ($member === null || $member->baptism_date === null) {
            return $this->notFound();
        }

        $date = $member->baptism_date->format('Y-m-d');
        $place = $this->resolveBaptismPlace($member, $parish);
        $sourceId = (string) $member->id;

        return [
            'record_status' => SacramentRecordStatus::FOUND,
            'candidates' => [],
            'evidence' => [
                'sacrament_id' => null,
                'member_id' => $member->id,
                'date' => $this->profileEvidence($date, $sourceId),
                'place' => $this->profileEvidence($place, $sourceId),
                'parish' => $this->profileEvidence($place, $sourceId),
                'baptism_location_type' => $member->baptism_location_type,
                'baptism_church_name' => $member->baptism_church_name,
                'baptism_church_address' => $member->baptism_church_address,
                'baptism_godparent_primary' => $member->baptism_godparent_primary,
                'baptism_godparent_secondary' => $member->baptism_godparent_secondary,
                'baptism_priest_name' => $member->baptism_priest_name,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $parish
     * @return array<string, mixed>
     */
    public function resolveConfirmation(?FamilyMember $member, array $parish): array
    {
        if ($member === null || $member->confirmation_date === null) {
            return $this->notFound();
        }

        $date = $member->confirmation_date->format('Y-m-d');
        $place = $this->resolveConfirmationPlace($member, $parish);
        $sourceId = (string) $member->id;

        return [
            'record_status' => SacramentRecordStatus::FOUND,
            'candidates' => [],
            'evidence' => [
                'sacrament_id' => null,
                'member_id' => $member->id,
                'date' => $this->profileEvidence($date, $sourceId),
                'place' => $this->profileEvidence($place, $sourceId),
                'parish' => $this->profileEvidence($place, $sourceId),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $parish
     */
    private function resolveBaptismPlace(FamilyMember $member, array $parish): ?string
    {
        $churchName = trim((string) ($member->baptism_church_name ?? ''));
        if ($churchName !== '') {
            return $churchName;
        }

        $place = trim((string) ($member->baptism_place ?? ''));
        if ($place !== '') {
            return $place;
        }

        $address = trim((string) ($member->baptism_church_address ?? ''));
        if ($address !== '') {
            return $address;
        }

        if (($member->baptism_location_type ?? 'home_parish') === 'home_parish') {
            return $parish['data']['name'] ?? null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $parish
     */
    private function resolveConfirmationPlace(FamilyMember $member, array $parish): ?string
    {
        $place = trim((string) ($member->confirmation_place ?? ''));
        if ($place !== '') {
            return $place;
        }

        return $parish['data']['name'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function profileEvidence(mixed $value, string $memberId): array
    {
        return $this->provenance->field(
            $value,
            SacramentFieldState::READ_ONLY_VERIFIED,
            SacramentSourceType::MEMBER_PROFILE,
            $memberId,
            'Member Profile Summary',
            'EXPLICIT',
            'NOT_INDEPENDENTLY_VERIFIED',
            SacramentRecordStatus::FOUND,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function notFound(): array
    {
        return [
            'record_status' => SacramentRecordStatus::NOT_FOUND,
            'candidates' => [],
            'evidence' => null,
        ];
    }
}
