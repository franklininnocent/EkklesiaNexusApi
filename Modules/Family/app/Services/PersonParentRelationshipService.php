<?php

namespace Modules\Family\app\Services;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;

/**
 * Normalize and persist canonical parent links and name snapshots on Person.
 */
class PersonParentRelationshipService
{
    public const PARENT_KEYS = [
        'father_person_id',
        'father_name',
        'mother_person_id',
        'mother_name',
    ];

    /**
     * @param  array<string, mixed>  $memberData
     * @return array<string, mixed>
     */
    public function extractFromMemberPayload(array &$memberData): array
    {
        $parentPayload = [];

        foreach (self::PARENT_KEYS as $key) {
            if (array_key_exists($key, $memberData)) {
                $parentPayload[$key] = $memberData[$key];
                unset($memberData[$key]);
            }
        }

        return $this->normalizePayload($parentPayload);
    }

    /**
     * @param  array<string, mixed>  $parentPayload
     */
    public function applyToPerson(
        Person $person,
        array $parentPayload,
        int|string $tenantId,
        ?int $userId = null,
        ?array $auditContext = null
    ): Person {
        if ($parentPayload === []) {
            return $person;
        }

        $updates = $this->buildPersonUpdates($person, $parentPayload, $tenantId);

        if ($updates === []) {
            return $person;
        }

        if ($userId !== null) {
            $updates['updated_by'] = $userId;
        }

        $person->fill($updates);
        $person->save();

        if ($auditContext !== null && isset($auditContext['service'])) {
            $this->logParentChanges($auditContext, $person, $updates);
        }

        return $person->fresh(['father', 'mother']);
    }

    /**
     * @param  array<string, mixed>  $parentPayload
     * @return array<string, mixed>
     */
    private function buildPersonUpdates(Person $person, array $parentPayload, int|string $tenantId): array
    {
        $updates = [];

        if (array_key_exists('father_person_id', $parentPayload) || array_key_exists('father_name', $parentPayload)) {
            $fatherPersonId = array_key_exists('father_person_id', $parentPayload)
                ? $this->nullableUuid($parentPayload['father_person_id'])
                : $person->father_person_id;

            $fatherNameInput = array_key_exists('father_name', $parentPayload)
                ? $this->nullableTrimmedString($parentPayload['father_name'])
                : $person->father_name;

            [$resolvedFatherId, $resolvedFatherName] = $this->resolveParentSide(
                $person,
                'father',
                $fatherPersonId,
                $fatherNameInput,
                $tenantId,
                $person->mother_person_id
            );

            if ($resolvedFatherId !== $person->father_person_id) {
                $updates['father_person_id'] = $resolvedFatherId;
            }
            if ($resolvedFatherName !== $person->father_name) {
                $updates['father_name'] = $resolvedFatherName;
            }
        }

        if (array_key_exists('mother_person_id', $parentPayload) || array_key_exists('mother_name', $parentPayload)) {
            $motherPersonId = array_key_exists('mother_person_id', $parentPayload)
                ? $this->nullableUuid($parentPayload['mother_person_id'])
                : $person->mother_person_id;

            $motherNameInput = array_key_exists('mother_name', $parentPayload)
                ? $this->nullableTrimmedString($parentPayload['mother_name'])
                : $person->mother_name;

            $fatherIdForCheck = $updates['father_person_id'] ?? $person->father_person_id;

            [$resolvedMotherId, $resolvedMotherName] = $this->resolveParentSide(
                $person,
                'mother',
                $motherPersonId,
                $motherNameInput,
                $tenantId,
                $fatherIdForCheck
            );

            if ($resolvedMotherId !== $person->mother_person_id) {
                $updates['mother_person_id'] = $resolvedMotherId;
            }
            if ($resolvedMotherName !== $person->mother_name) {
                $updates['mother_name'] = $resolvedMotherName;
            }
        }

        return $updates;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveParentSide(
        Person $person,
        string $side,
        ?string $personId,
        ?string $nameInput,
        int|string $tenantId,
        ?string $otherParentPersonId
    ): array {
        $fieldPrefix = $side === 'father' ? 'father' : 'mother';
        $expectedGender = $side === 'father' ? 'male' : 'female';

        if ($personId === null) {
            return [null, $nameInput];
        }

        if ($personId === $person->id) {
            throw ValidationException::withMessages([
                "{$fieldPrefix}_person_id" => $side === 'father'
                    ? 'A person cannot be their own father.'
                    : 'A person cannot be their own mother.',
            ]);
        }

        if ($personId === $otherParentPersonId) {
            throw ValidationException::withMessages([
                "{$fieldPrefix}_person_id" => 'Father and mother cannot be the same person.',
            ]);
        }

        $parentPerson = Person::query()
            ->forTenant($tenantId)
            ->whereNull('deleted_at')
            ->where('id', $personId)
            ->first();

        if (! $parentPerson) {
            throw ValidationException::withMessages([
                "{$fieldPrefix}_person_id" => 'That parish record is no longer available.',
            ]);
        }

        if (
            in_array($parentPerson->gender, ['male', 'female'], true)
            && $parentPerson->gender !== $expectedGender
        ) {
            throw ValidationException::withMessages([
                "{$fieldPrefix}_person_id" => $side === 'father'
                    ? 'The selected person is not recorded as male.'
                    : 'The selected person is not recorded as female.',
            ]);
        }

        $this->assertNoDirectCycle($person, $parentPerson, $side);

        return [$parentPerson->id, $parentPerson->full_name_display ?: $this->personFullName($parentPerson)];
    }

    private function assertNoDirectCycle(Person $person, Person $parentPerson, string $side): void
    {
        $reverseFatherId = $side === 'father' ? $person->id : null;
        $reverseMotherId = $side === 'mother' ? $person->id : null;

        if ($side === 'father' && $parentPerson->father_person_id === $person->id) {
            throw ValidationException::withMessages([
                'father_person_id' => 'This would create a circular parent relationship.',
            ]);
        }

        if ($side === 'mother' && $parentPerson->mother_person_id === $person->id) {
            throw ValidationException::withMessages([
                'mother_person_id' => 'This would create a circular parent relationship.',
            ]);
        }

        unset($reverseFatherId, $reverseMotherId);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalizePayload(array $payload): array
    {
        foreach (self::PARENT_KEYS as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            if (str_contains($key, '_person_id')) {
                $payload[$key] = $this->nullableUuid($payload[$key]);
            } else {
                $payload[$key] = $this->nullableTrimmedString($payload[$key]);
            }
        }

        return $payload;
    }

    public function displayFatherName(Person $person): ?string
    {
        if ($person->relationLoaded('father') && $person->father && $person->father->deleted_at === null) {
            return $person->father->full_name_display ?: $this->personFullName($person->father);
        }

        if ($person->father_person_id) {
            $father = Person::query()->find($person->father_person_id);
            if ($father && $father->deleted_at === null) {
                return $father->full_name_display ?: $this->personFullName($father);
            }
        }

        $snapshot = trim((string) ($person->father_name ?? ''));

        return $snapshot !== '' ? $snapshot : null;
    }

    public function displayMotherName(Person $person): ?string
    {
        if ($person->relationLoaded('mother') && $person->mother && $person->mother->deleted_at === null) {
            return $person->mother->full_name_display ?: $this->personFullName($person->mother);
        }

        if ($person->mother_person_id) {
            $mother = Person::query()->find($person->mother_person_id);
            if ($mother && $mother->deleted_at === null) {
                return $mother->full_name_display ?: $this->personFullName($mother);
            }
        }

        $snapshot = trim((string) ($person->mother_name ?? ''));

        return $snapshot !== '' ? $snapshot : null;
    }

    private function personFullName(Person $person): string
    {
        return trim(implode(' ', array_filter([
            $person->first_name,
            $person->middle_name,
            $person->last_name,
        ])));
    }

    private function nullableUuid(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' && Str::isUuid($normalized) ? $normalized : null;
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @param  array<string, mixed>  $auditContext
     * @param  array<string, mixed>  $updates
     */
    private function logParentChanges(array $auditContext, Person $person, array $updates): void
    {
        /** @var FamilyAuditService $auditService */
        $auditService = $auditContext['service'];
        $tenantId = (int) $auditContext['tenant_id'];
        $memberId = (string) ($auditContext['member_id'] ?? $person->id);

        try {
            $auditService->log(
                $tenantId,
                'family_member.parent_updated',
                'family_member',
                $memberId,
                null,
                $updates,
                ['person_id' => $person->id]
            );
        } catch (\Throwable) {
            // Non-blocking audit, consistent with family create/update.
        }
    }
}
