<?php

namespace Modules\Family\Support;

use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;

/**
 * Canonical contract for PEOPLE_LIMIT consumption (subscription enforcement only).
 */
final class PeopleLimitEligibility
{
    public static function memberConsumesSlot(
        ?string $memberStatus,
        ?string $familyStatus,
        bool $memberSoftDeleted = false,
        bool $familySoftDeleted = false,
    ): bool {
        if ($memberSoftDeleted || $familySoftDeleted) {
            return false;
        }

        if ($memberStatus !== 'active') {
            return false;
        }

        return $familyStatus !== 'migrated';
    }

    public static function memberModelConsumesSlot(FamilyMember $member, ?Family $family = null): bool
    {
        if ($member->deleted_at !== null) {
            return false;
        }

        $family ??= $member->relationLoaded('family') ? $member->family : $member->family()->first();

        return self::memberConsumesSlot(
            $member->status,
            $family?->status,
            false,
            $family?->deleted_at !== null,
        );
    }

    /**
     * Whether a new or updated member row will consume a slot (default status is active when omitted).
     *
     * @param  array<string, mixed>  $memberData
     */
    public static function payloadWillConsumeSlot(array $memberData, ?string $familyStatus): bool
    {
        $status = isset($memberData['status']) ? (string) $memberData['status'] : 'active';

        return self::memberConsumesSlot($status, $familyStatus);
    }

    public static function deltaForMemberStatusChange(
        FamilyMember $member,
        ?string $newStatus,
        ?string $familyStatus,
    ): int {
        $before = self::memberConsumesSlot($member->status, $familyStatus);
        $after = self::memberConsumesSlot($newStatus ?? $member->status, $familyStatus);

        return ($after ? 1 : 0) - ($before ? 1 : 0);
    }

    /**
     * Net new slots when a family's status changes (active members on that family only).
     */
    public static function deltaForFamilyStatusChange(Family $family, ?string $newFamilyStatus): int
    {
        $oldStatus = $family->status;
        $newStatus = $newFamilyStatus ?? $oldStatus;
        if ($oldStatus === $newStatus) {
            return 0;
        }

        $activeCount = (int) $family->members()
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->count();

        if ($activeCount === 0) {
            return 0;
        }

        $wasMigrated = $oldStatus === 'migrated';
        $willBeMigrated = $newStatus === 'migrated';

        if ($wasMigrated && ! $willBeMigrated) {
            return $activeCount;
        }
        if (! $wasMigrated && $willBeMigrated) {
            return -$activeCount;
        }

        return 0;
    }

    /**
     * @param  list<array<string, mixed>>  $memberPayloads
     */
    public static function countPayloadsConsuming(array $memberPayloads, ?string $familyStatus): int
    {
        $count = 0;
        foreach ($memberPayloads as $payload) {
            if (self::payloadWillConsumeSlot($payload, $familyStatus)) {
                $count++;
            }
        }

        return $count;
    }
}
