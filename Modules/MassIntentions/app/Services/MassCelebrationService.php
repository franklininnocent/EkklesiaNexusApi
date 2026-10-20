<?php

namespace Modules\MassIntentions\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Models\User;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Models\MassIntentionAssignment;
use Modules\MassIntentions\Models\MassIntentionObligation;
use Modules\MassIntentions\Support\MassCelebrationOrigin;
use Modules\MassIntentions\Support\MassGenerationStatus;
use Modules\MassIntentions\Support\MassObligationStatus;
use Modules\MassIntentions\Support\MassSuppressionReason;

class MassCelebrationService
{
    public function __construct(
        private readonly MassIntentionAuditService $audits,
        private readonly MassIntentionAssignmentService $assignments,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $tenantId, string $celebrationId, array $data): MassCelebration
    {
        $celebration = MassCelebration::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $celebrationId)
            ->where('status', 'scheduled')
            ->firstOrFail();

        if ($celebration->slot_id !== null) {
            if (
                array_key_exists('celebrated_on', $data)
                && $data['celebrated_on'] !== $celebration->celebrated_on?->format('Y-m-d')
            ) {
                throw ValidationException::withMessages([
                    'celebrated_on' => 'Scheduled Masses from the weekly schedule cannot change date here. Cancel this Mass and add a one-time Mass, or update the weekly schedule.',
                ]);
            }
            if (
                array_key_exists('celebrated_at', $data)
                && substr((string) ($data['celebrated_at'] ?? ''), 0, 5) !== substr((string) $celebration->celebrated_at, 0, 5)
            ) {
                throw ValidationException::withMessages([
                    'celebrated_at' => 'Scheduled Masses from the weekly schedule cannot change time here. Cancel this Mass and add a one-time Mass, or update the weekly schedule.',
                ]);
            }

            $newPlace = $data['place'] ?? null;
            $newCelebrant = $data['celebrant_name'] ?? null;
            if (
                (string) $newPlace !== (string) ($celebration->place ?? '')
                || (string) $newCelebrant !== (string) ($celebration->celebrant_name ?? '')
            ) {
                $celebration->is_exception = true;
            }
            $celebration->fill([
                'place' => $newPlace,
                'celebrant_name' => $newCelebrant,
            ]);
        } else {
            $newDate = $data['celebrated_on'] ?? $celebration->celebrated_on?->format('Y-m-d');
            $newTime = $data['celebrated_at'] ?? $celebration->celebrated_at;
            $dateChanging = array_key_exists('celebrated_on', $data)
                && $data['celebrated_on'] !== $celebration->celebrated_on?->format('Y-m-d');
            $timeChanging = array_key_exists('celebrated_at', $data)
                && substr((string) ($data['celebrated_at'] ?? ''), 0, 5) !== substr((string) $celebration->celebrated_at, 0, 5);

            if (($dateChanging || $timeChanging) && $this->assignments->celebrationHasSaidFulfilment($tenantId, $celebration->id)) {
                throw ValidationException::withMessages([
                    'celebrated_on' => 'This Mass already has intentions marked said. Add another Mass and move the remaining intentions before changing the date or time.',
                ]);
            }

            $celebration->fill([
                'celebrated_on' => $newDate,
                'celebrated_at' => $newTime,
                'place' => $data['place'] ?? null,
                'celebrant_name' => $data['celebrant_name'] ?? null,
                'occasion' => $data['occasion'] ?? $celebration->occasion,
                'notes' => $data['notes'] ?? $celebration->notes,
            ]);
        }
        $celebration->save();

        if ($celebration->slot_id === null) {
            $this->assignments->syncRequestedDateForUnsaidOnCelebration($tenantId, $celebration->fresh());
        }

        return $celebration->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $tenantId, User $actor, array $data): MassCelebration
    {
        $timezone = DonationBusinessDate::timezoneForTenant($tenantId);

        $celebration = MassCelebration::query()->create([
            'tenant_id' => $tenantId,
            'origin' => MassCelebrationOrigin::ONE_TIME,
            'celebrated_on' => $data['celebrated_on'],
            'celebrated_at' => $data['celebrated_at'] ?? null,
            'place' => $data['place'] ?? null,
            'celebrant_name' => $data['celebrant_name'] ?? null,
            'occasion' => $data['occasion'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => 'scheduled',
            'generation_status' => MassGenerationStatus::ACTIVE,
            'timezone' => $timezone,
            'created_by_user_id' => $actor->id,
        ]);

        $this->audits->record($tenantId, 'celebration.created', $actor, null, $celebration->id, [
            'origin' => MassCelebrationOrigin::ONE_TIME,
        ]);

        return $celebration;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(MassCelebration $celebration): array
    {
        return [
            'id' => $celebration->id,
            'origin' => $celebration->origin,
            'slot_id' => $celebration->slot_id,
            'celebrated_on' => $celebration->celebrated_on?->format('Y-m-d'),
            'celebrated_at' => $celebration->celebrated_at
                ? substr((string) $celebration->celebrated_at, 0, 5)
                : null,
            'place' => $celebration->place,
            'celebrant_name' => $celebration->celebrant_name,
            'occasion' => $celebration->occasion,
            'notes' => $celebration->notes,
            'status' => $celebration->status,
            'generation_status' => $celebration->generation_status,
            'suppression_reason' => $celebration->suppression_reason,
            'source_label' => $celebration->source_label,
            'is_exception' => (bool) $celebration->is_exception,
        ];
    }

    /**
     * Apply a schedule preview proposal onto an existing Mass (same occurrence id).
     *
     * @param  array<string, mixed>  $proposal
     */
    public function applyScheduleProposal(
        int $tenantId,
        User $actor,
        string $celebrationId,
        array $proposal
    ): MassCelebration {
        return DB::transaction(function () use ($tenantId, $actor, $celebrationId, $proposal): MassCelebration {
            $celebration = MassCelebration::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $celebrationId)
                ->where('status', 'scheduled')
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->assignments->celebrationHasSaidFulfilment($tenantId, $celebration->id)) {
                throw ValidationException::withMessages([
                    'celebration' => 'This Mass has intentions marked said. Move remaining intentions before applying the new schedule time.',
                ]);
            }

            $newTime = isset($proposal['celebrated_at']) ? substr((string) $proposal['celebrated_at'], 0, 5) : null;
            if ($newTime === null || $newTime === '') {
                throw ValidationException::withMessages([
                    'celebrated_at' => 'The proposed Mass time is missing.',
                ]);
            }

            $before = MassCelebrationEligibility::massSnapshot($celebration);

            $celebration->celebrated_at = $newTime;
            if (array_key_exists('place', $proposal)) {
                $celebration->place = $proposal['place'];
            }
            if (array_key_exists('celebrant_name', $proposal)) {
                $celebration->celebrant_name = $proposal['celebrant_name'];
            }
            if (array_key_exists('revision_id', $proposal)) {
                $celebration->revision_id = $proposal['revision_id'];
            }
            if (array_key_exists('schedule_id', $proposal)) {
                $celebration->schedule_id = $proposal['schedule_id'];
            }
            if (array_key_exists('source_label', $proposal)) {
                $celebration->source_label = $proposal['source_label'];
            }
            $celebration->is_exception = false;
            $celebration->save();

            $this->assignments->syncRequestedDateForUnsaidOnCelebration($tenantId, $celebration->fresh());

            $this->audits->record($tenantId, 'celebration.schedule_applied', $actor, null, $celebration->id, [
                'before' => $before,
                'after' => MassCelebrationEligibility::massSnapshot($celebration->fresh()),
            ]);

            return $celebration->fresh();
        });
    }

    public function keepOnSchedule(int $tenantId, User $actor, string $celebrationId): MassCelebration
    {
        $celebration = MassCelebration::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $celebrationId)
            ->where('status', 'scheduled')
            ->firstOrFail();

        if ($celebration->slot_id === null) {
            throw ValidationException::withMessages([
                'celebration' => 'Only Masses from the schedule can be kept on the calendar this way.',
            ]);
        }

        if ($celebration->generation_status !== MassGenerationStatus::ACTIVE) {
            throw ValidationException::withMessages([
                'celebration' => 'This Mass is no longer on the active schedule.',
            ]);
        }

        if (! $celebration->is_exception) {
            $celebration->is_exception = true;
            $celebration->save();
            $this->audits->record($tenantId, 'celebration.schedule_kept', $actor, null, $celebration->id, []);
        }

        return $celebration->fresh();
    }

    /**
     * Mark a scheduled occurrence as removed from the calendar (schedule changed) after intentions are moved off.
     */
    public function markScheduleChanged(int $tenantId, User $actor, string $celebrationId): MassCelebration
    {
        return DB::transaction(function () use ($tenantId, $actor, $celebrationId): MassCelebration {
            $celebration = MassCelebration::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $celebrationId)
                ->where('status', 'scheduled')
                ->lockForUpdate()
                ->firstOrFail();

            if ($celebration->slot_id === null) {
                throw ValidationException::withMessages([
                    'celebration' => 'Only Masses from the weekly schedule can be marked schedule changed this way.',
                ]);
            }

            if ($celebration->generation_status !== MassGenerationStatus::ACTIVE) {
                throw ValidationException::withMessages([
                    'celebration' => 'This Mass is no longer on the active schedule.',
                ]);
            }

            $activeIntentions = MassCelebrationEligibility::intentionCount($tenantId, $celebration->id);
            if ($activeIntentions > 0) {
                throw ValidationException::withMessages([
                    'celebration' => 'Move every unsaid intention to another Mass before removing this one from the schedule.',
                ]);
            }

            $saidCount = (int) DB::table('mass_intention_fulfilments')
                ->where('tenant_id', $tenantId)
                ->where('celebration_id', $celebration->id)
                ->whereNull('undone_at')
                ->count();

            $celebration->generation_status = MassGenerationStatus::SUPPRESSED;
            $celebration->suppression_reason = MassSuppressionReason::SCHEDULE_CHANGED;
            $celebration->suppressed_at = now();
            if ($celebration->source_label === null) {
                $celebration->source_label = $celebration->celebrated_on?->format('Y-m-d').' '
                    .substr((string) $celebration->celebrated_at, 0, 5);
            }
            $celebration->save();

            $this->audits->record($tenantId, 'celebration.schedule_changed', $actor, null, $celebration->id, [
                'mass' => MassCelebrationEligibility::massSnapshot($celebration),
                'intentions_said' => $saidCount,
                'intentions_active' => 0,
            ]);

            return $celebration->fresh();
        });
    }

    /**
     * Cancel a Mass after every unsaid intention has been moved to another eligible Mass.
     *
     * @param  list<array{obligation_id: string, celebration_id: string}>  $reassignments
     */
    public function cancel(
        int $tenantId,
        User $actor,
        string $celebrationId,
        string $reason,
        array $reassignments = []
    ): MassCelebration {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Enter why this Mass is cancelled.',
            ]);
        }

        $reassignMap = [];
        foreach ($reassignments as $item) {
            if (! empty($item['obligation_id']) && ! empty($item['celebration_id'])) {
                $reassignMap[$item['obligation_id']] = $item['celebration_id'];
            }
        }

        return DB::transaction(function () use ($tenantId, $actor, $celebrationId, $reason, $reassignMap): MassCelebration {
            $celebration = MassCelebration::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $celebrationId)
                ->where('status', 'scheduled')
                ->lockForUpdate()
                ->firstOrFail();

            $unsaidAssignments = MassIntentionAssignment::query()
                ->where('tenant_id', $tenantId)
                ->where('celebration_id', $celebration->id)
                ->whereNull('unassigned_at')
                ->get();

            $moves = [];
            $saidCount = 0;
            $movedCount = 0;

            foreach ($unsaidAssignments as $assignment) {
                $obligation = MassIntentionObligation::query()
                    ->where('tenant_id', $tenantId)
                    ->where('id', $assignment->obligation_id)
                    ->first();

                if (! $obligation) {
                    continue;
                }

                if ($obligation->status === MassObligationStatus::SAID) {
                    $saidCount++;

                    continue;
                }

                $target = $reassignMap[$obligation->id] ?? null;
                if ($target === null || $target === '') {
                    throw ValidationException::withMessages([
                        'reassignments' => 'Every unsaid intention must be moved to another Mass before this Mass can be cancelled.',
                    ]);
                }

                $moves[] = [
                    'obligation_id' => $obligation->id,
                    'celebration_id' => $target,
                ];
                $movedCount++;
            }

            if ($moves !== []) {
                $this->assignments->reassignUnsaidForCancel($tenantId, $actor, $celebration->id, $moves);
            }

            $celebration->status = 'cancelled';
            $celebration->cancel_reason = $reason;
            if ($celebration->slot_id !== null) {
                $celebration->generation_status = MassGenerationStatus::SUPPRESSED;
                $celebration->suppression_reason = MassSuppressionReason::USER_CANCELLED;
                $celebration->suppressed_at = now();
            }
            $celebration->save();

            $this->audits->record($tenantId, 'celebration.cancelled', $actor, null, $celebration->id, [
                'reason' => $reason,
                'intentions_said' => $saidCount,
                'intentions_moved' => $movedCount,
            ]);

            return $celebration->fresh();
        });
    }
}
