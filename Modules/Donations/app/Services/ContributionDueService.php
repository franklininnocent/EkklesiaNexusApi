<?php

namespace Modules\Donations\Services;

use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Donations\Exceptions\ScheduleGenerationBusyException;
use Modules\Donations\Jobs\GenerateFullContributionScheduleJob;
use Modules\Donations\Jobs\SendContributionReminderJob;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\ContributionPlan;
use Modules\Donations\Models\ContributionPlanAssignment;
use Modules\Donations\Support\ContributionBalance;
use Modules\Donations\Support\ContributionPeriod;
use Modules\Donations\Support\ContributionPlanFrequencies;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Support\MoneyMath;
use Modules\Family\Models\Family;
use Modules\Tenants\Models\Scopes\TenantScope;
use Modules\Tenants\Support\SubscriptionJobWriteGuard;

class ContributionDueService
{
    public function __construct(private readonly DonationAuditService $auditService) {}

    public function resolveAmountForFamily(ContributionPlan $plan, string $familyId, string $asOfDate): ?float
    {
        $override = $this->findActiveAssignment($plan, $familyId, $asOfDate);

        if ($override?->is_exempt) {
            return null;
        }

        if ($override) {
            return MoneyMath::toApiNumber($override->amount);
        }

        if ($plan->plan_type === 'individual') {
            return null;
        }

        return MoneyMath::toApiNumber($plan->default_amount);
    }

    /**
     * @return array<int, string>
     */
    public function getEnrolledFamilyIds(ContributionPlan $plan, string $periodStart, ?string $periodEnd = null): array
    {
        $periodEnd ??= $periodStart;
        $timezone = DonationBusinessDate::timezoneForTenant((int) $plan->tenant_id);

        if ($plan->plan_type === 'uniform') {
            return Family::query()
                ->where('tenant_id', $plan->tenant_id)
                ->where('status', 'active')
                ->get(['id', 'created_at'])
                ->filter(function (Family $family) use ($periodEnd, $timezone): bool {
                    $joinedOn = $family->created_at
                        ? Carbon::parse($family->created_at)->timezone($timezone)->toDateString()
                        : $periodEnd;

                    return $joinedOn <= $periodEnd;
                })
                ->pluck('id')
                ->all();
        }

        return ContributionPlanAssignment::forTenant($plan->tenant_id)
            ->where('plan_id', $plan->id)
            ->where('status', 'active')
            ->where('is_exempt', false)
            ->whereDate('effective_from', '<=', $periodStart)
            ->where(function ($query) use ($periodStart): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $periodStart);
            })
            ->pluck('family_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{generated_count: int, unchanged_count: int, family_count: int, period_count: int, status?: string, periods?: array<int, array{period_label: string, period_start: string, period_end: string, due_date: string}>}
     */
    public function previewSchedule(ContributionPlan $plan, ?array $familyIds = null): array
    {
        if ($plan->status !== 'active') {
            return array_merge([
                'period_count' => 0,
                'family_count' => 0,
                'estimated_new_ops' => 0,
                'periods' => [],
            ], $this->emptyAssessment());
        }

        $periods = ContributionPeriod::enumerateForPlan($plan);

        if ($periods === []) {
            return array_merge([
                'period_count' => 0,
                'family_count' => 0,
                'estimated_new_ops' => 0,
                'periods' => [],
            ], $this->emptyAssessment());
        }

        $familyCount = $familyIds !== null
            ? count($familyIds)
            : $this->estimateEnrolledFamilyCount($plan, $periods);

        return array_merge([
            'period_count' => count($periods),
            'family_count' => $familyCount,
            'estimated_new_ops' => $familyCount * count($periods),
            'periods' => array_map(fn (array $period): array => [
                'period_label' => $period['period_label'],
                'period_start' => $period['period_start'],
                'period_end' => $period['period_end'],
                'due_date' => ContributionPeriod::applyGraceDays($period['due_date'], (int) $plan->grace_days),
            ], $periods),
        ], $this->assessBillingPreview($plan, $periods, $familyIds));
    }

    /**
     * @return array{generated_count: int, unchanged_count: int, family_count: int, period_count: int, status?: string}
     */
    public function generateSchedule(
        int $tenantId,
        int $userId,
        ContributionPlan $plan,
        ?array $familyIds = null,
        bool $forceSync = false,
        string $mode = 'manual'
    ): array {
        if ($plan->status !== 'active') {
            throw new InvalidArgumentException('Contribution plan is not active.');
        }

        $lockKey = "donations:generate-schedule:{$tenantId}:{$plan->id}";
        $lock = Cache::lock($lockKey, 60);

        if (! $lock->get()) {
            throw new ScheduleGenerationBusyException('Dues are already being generated for this plan. Please wait and try again.');
        }

        try {
            $businessToday = DonationBusinessDate::today($tenantId);
            $periods = ContributionPeriod::enumerateForPlan($plan);

            if ($periods === []) {
                return [
                    'generated_count' => 0,
                    'unchanged_count' => 0,
                    'family_count' => 0,
                    'period_count' => 0,
                ];
            }

            $uniformFamilies = $plan->plan_type === 'uniform'
                ? $this->loadUniformFamiliesForPlan($plan)
                : null;
            $individualAssignments = $plan->plan_type === 'individual'
                ? $this->loadIndividualAssignmentsForPlan($plan)
                : null;

            $familyCount = $familyIds !== null
                ? count($familyIds)
                : $this->estimateEnrolledFamilyCount($plan, $periods, $uniformFamilies, $individualAssignments);
            $totalOps = $familyCount * count($periods);

            if (! $forceSync && $totalOps > ContributionPlanFrequencies::ASYNC_THRESHOLD) {
                GenerateFullContributionScheduleJob::dispatch($tenantId, $userId, $plan->id, $familyIds);

                return [
                    'generated_count' => 0,
                    'unchanged_count' => 0,
                    'family_count' => $familyCount,
                    'period_count' => count($periods),
                    'status' => 'queued',
                ];
            }

            $generated = 0;
            $unchanged = 0;
            $exemptSkipped = 0;

            foreach ($periods as $period) {
                $enrolled = $familyIds ?? $this->resolveEnrolledFamilyIds(
                    $plan,
                    $period['period_start'],
                    $period['period_end'],
                    $uniformFamilies,
                    $individualAssignments
                );
                if ($familyIds !== null) {
                    $enrolled = array_values(array_intersect($enrolled, $familyIds));
                }

                $dueDate = ContributionPeriod::applyGraceDays($period['due_date'], (int) $plan->grace_days);

                foreach (array_chunk($enrolled, 100) as $chunk) {
                    DB::transaction(function () use (
                        $tenantId,
                        $userId,
                        $plan,
                        $chunk,
                        $period,
                        $dueDate,
                        &$generated,
                        &$unchanged,
                        &$exemptSkipped
                    ): void {
                        foreach ($chunk as $familyId) {
                            $amount = $this->resolveAmountForFamily($plan, $familyId, $period['period_start']);
                            if ($amount === null || $amount <= 0) {
                                if ($this->familyIsExempt($plan, $familyId, $period['period_start'])) {
                                    $exemptSkipped++;
                                }
                                continue;
                            }

                            $result = $this->upsertDue(
                                $tenantId,
                                $userId,
                                $plan,
                                $familyId,
                                $period['period_label'],
                                $dueDate,
                                $amount,
                                null,
                                $period['period_start'],
                                $period['period_end'],
                                false
                            );

                            if ($result === 'created' || $result === 'reactivated') {
                                $generated++;
                            } elseif ($result === 'unchanged') {
                                $unchanged++;
                            }
                        }
                    });
                }
            }

            $this->auditService->log(
                $tenantId,
                'due.bulk_generated',
                'plan',
                $plan->id,
                null,
                [
                    'generated_count' => $generated,
                    'unchanged_count' => $unchanged,
                    'exempt_skipped_count' => $exemptSkipped,
                    'period_count' => count($periods),
                ],
                ['mode' => $mode]
            );

            return [
                'generated_count' => $generated,
                'unchanged_count' => $unchanged,
                'exempt_skipped_count' => $exemptSkipped,
                'family_count' => $familyCount,
                'period_count' => count($periods),
            ];
        } finally {
            $lock->release();
        }
    }

    public function generateForFamilies(
        int $tenantId,
        int $userId,
        ContributionPlan $plan,
        array $familyIds,
        string $periodLabel,
        string $dueDate,
        ?float $amountDue = null,
        ?string $notes = null,
        ?string $periodStart = null,
        ?string $periodEnd = null
    ): array {
        return DB::transaction(function () use ($tenantId, $userId, $plan, $familyIds, $periodLabel, $dueDate, $amountDue, $notes, $periodStart, $periodEnd): array {
            $validFamilies = Family::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $familyIds)
                ->pluck('id')
                ->all();

            if (count($validFamilies) !== count($familyIds)) {
                throw new InvalidArgumentException('One or more families are invalid for this tenant.');
            }

            $created = [];
            $asOfDate = Carbon::parse($dueDate)->toDateString();
            $period = $periodStart === null
                ? ContributionPeriod::currentForPlan($plan, Carbon::parse($asOfDate))
                : [
                    'period_label' => $periodLabel,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd ?? $periodStart,
                    'due_date' => Carbon::parse($dueDate)->toDateString(),
                ];

            foreach ($validFamilies as $familyId) {
                $resolvedAmount = $amountDue ?? $this->resolveAmountForFamily($plan, $familyId, $period['period_start']);
                if ($resolvedAmount === null || $resolvedAmount <= 0) {
                    continue;
                }

                $result = $this->upsertDue(
                    $tenantId,
                    $userId,
                    $plan,
                    $familyId,
                    $periodLabel,
                    $dueDate,
                    $resolvedAmount,
                    $notes,
                    $period['period_start'],
                    $period['period_end'],
                    true
                );

                if ($result === 'created' || $result === 'updated' || $result === 'reactivated') {
                    $due = ContributionDue::forTenant($tenantId)
                        ->where('plan_id', $plan->id)
                        ->where('family_id', $familyId)
                        ->where('period_label', $periodLabel)
                        ->first();
                    if ($due) {
                        $created[] = $due->fresh(['family', 'plan']);
                    }
                }
            }

            $this->auditService->log(
                $tenantId,
                'due.bulk_generated',
                'plan',
                $plan->id,
                null,
                ['count' => count($created), 'period_label' => $periodLabel],
                ['family_count' => count($validFamilies)]
            );

            return $created;
        });
    }

    /**
     * @return array{generated_count: int, unchanged_count: int, period_count: int}
     */
    public function generateCurrentPeriodWithGaps(int $tenantId, int $userId, ContributionPlan $plan, ?Carbon $reference = null): array
    {
        $businessToday = DonationBusinessDate::today($tenantId);
        $reference ??= Carbon::parse($businessToday);

        if (! $this->isPlanActiveOnDate($plan, $reference->toDateString())) {
            return [
                'generated_count' => 0,
                'unchanged_count' => 0,
                'period_count' => 0,
            ];
        }

        if ($plan->frequency === 'one_time') {
            $created = $this->generateCurrentPeriod($tenantId, $userId, $plan, $reference);

            return [
                'generated_count' => count($created),
                'unchanged_count' => 0,
                'period_count' => count($created) > 0 ? 1 : 0,
            ];
        }

        $result = $this->generateSchedule($tenantId, $userId, $plan, null, true, 'scheduled');

        return [
            'generated_count' => $result['generated_count'],
            'unchanged_count' => $result['unchanged_count'],
            'period_count' => $result['period_count'],
        ];
    }

    public function generateCurrentPeriod(int $tenantId, int $userId, ContributionPlan $plan, ?Carbon $reference = null): array
    {
        $businessToday = DonationBusinessDate::today($tenantId);
        $reference ??= Carbon::parse($businessToday);

        if (! $this->isPlanActiveOnDate($plan, $reference->toDateString())) {
            return [];
        }

        if ($plan->frequency === 'one_time') {
            $period = ContributionPeriod::oneTime($plan, $reference);
            $dueDate = ContributionPeriod::applyGraceDays($period['due_date'], (int) $plan->grace_days);
            $familyIds = $this->getEnrolledFamilyIds($plan, $period['period_start'], $period['period_end']);
            $created = [];

            foreach ($familyIds as $familyId) {
                $alreadyIssued = ContributionDue::forTenant($tenantId)
                    ->where('plan_id', $plan->id)
                    ->where('family_id', $familyId)
                    ->whereNotIn('status', ['cancelled'])
                    ->exists();

                if ($alreadyIssued) {
                    continue;
                }

                $amount = $this->resolveAmountForFamily($plan, $familyId, $period['period_start']);
                if ($amount === null || $amount <= 0) {
                    continue;
                }

                $result = $this->upsertDue(
                    $tenantId,
                    $userId,
                    $plan,
                    $familyId,
                    $period['period_label'],
                    $dueDate,
                    $amount,
                    null,
                    $period['period_start'],
                    $period['period_end'],
                    true
                );

                if (in_array($result, ['created', 'updated', 'reactivated'], true)) {
                    $due = ContributionDue::forTenant($tenantId)
                        ->where('plan_id', $plan->id)
                        ->where('family_id', $familyId)
                        ->where('period_label', $period['period_label'])
                        ->first();
                    if ($due) {
                        $created[] = $due->fresh(['family', 'plan']);
                    }
                }
            }

            return $created;
        }

        $period = ContributionPeriod::currentForPlan($plan, $reference);
        $dueDate = ContributionPeriod::applyGraceDays($period['due_date'], (int) $plan->grace_days);
        $familyIds = $this->getEnrolledFamilyIds($plan, $period['period_start'], $period['period_end']);

        return $this->generateForFamilies(
            $tenantId,
            $userId,
            $plan,
            $familyIds,
            $period['period_label'],
            $dueDate,
            null,
            null,
            $period['period_start'],
            $period['period_end']
        );
    }

    /**
     * @return array{processed:int, generated:int}
     */
    public function generateScheduledDuesForTenant(int $tenantId, int $userId): array
    {
        $processed = 0;
        $generated = 0;

        $plans = ContributionPlan::forTenant($tenantId)
            ->where('status', 'active')
            ->where('auto_generate', true)
            ->get();

        foreach ($plans as $plan) {
            $processed++;
            $result = $this->generateCurrentPeriodWithGaps($tenantId, $userId, $plan);
            $generated += (int) ($result['generated_count'] ?? 0);
        }

        return ['processed' => $processed, 'generated' => $generated];
    }

    /**
     * @return array{processed:int, generated:int}
     */
    public function generateScheduledDuesForAllTenants(): array
    {
        $processed = 0;
        $generated = 0;
        $writeGuard = app(SubscriptionJobWriteGuard::class);

        $tenantIds = ContributionPlan::withoutTenantScope()
            ->where('status', 'active')
            ->where('auto_generate', true)
            ->distinct()
            ->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            $tenantId = (int) $tenantId;
            if (! $writeGuard->allowsMutationsForTenantId($tenantId)) {
                continue;
            }

            TenantScope::runWithout(function () use ($tenantId, &$processed, &$generated): void {
                $result = $this->generateScheduledDuesForTenant($tenantId, 0);
                $processed += $result['processed'];
                $generated += $result['generated'];
            });
        }

        return ['processed' => $processed, 'generated' => $generated];
    }

    public function waiveDue(int $tenantId, int $userId, ContributionDue $due, ?string $reason = null): ContributionDue
    {
        $oldStatus = $due->status;
        $due->status = 'waived';
        $due->status_changed_at = now();
        $due->notes = trim(($due->notes ? $due->notes.' ' : '').($reason ?? 'Waived'));
        $due->updated_by = $userId;
        $due->save();

        $this->auditService->log(
            $tenantId,
            'due.waived',
            'due',
            $due->id,
            ['status' => $oldStatus],
            ['status' => 'waived', 'reason' => $reason]
        );

        return $due->fresh(['family', 'plan']);
    }

    /**
     * Insert missing installments and cancel future unpaid rows outside the plan schedule.
     *
     * @return array{generated_count: int, unchanged_count: int, family_count: int, period_count: int, cancelled_count: int, status?: string}
     */
    public function reconcileScheduleOnPlanUpdate(
        int $tenantId,
        int $userId,
        ContributionPlan $plan,
        bool $forceSync = false
    ): array {
        if ($plan->status !== 'active') {
            return [
                'generated_count' => 0,
                'unchanged_count' => 0,
                'family_count' => 0,
                'period_count' => 0,
                'cancelled_count' => 0,
            ];
        }

        $businessToday = DonationBusinessDate::today($tenantId);
        $periods = ContributionPeriod::enumerateForPlan($plan);

        if ($periods === []) {
            return [
                'generated_count' => 0,
                'unchanged_count' => 0,
                'family_count' => 0,
                'period_count' => 0,
                'cancelled_count' => 0,
            ];
        }

        $validLabels = array_column($periods, 'period_label');
        $familyCount = count($this->getEnrolledFamilyIds($plan, $periods[0]['period_start']));
        $totalOps = $familyCount * count($periods);

        if (! $forceSync && $totalOps > ContributionPlanFrequencies::ASYNC_THRESHOLD) {
            GenerateFullContributionScheduleJob::dispatch($tenantId, $userId, $plan->id, null, true);

            return [
                'generated_count' => 0,
                'unchanged_count' => 0,
                'family_count' => $familyCount,
                'period_count' => count($periods),
                'cancelled_count' => 0,
                'status' => 'queued',
            ];
        }

        $result = $this->generateSchedule($tenantId, $userId, $plan, null, true, 'reconcile');
        $this->updateFuturePendingOnReconcile($tenantId, $userId, $plan, $periods, $businessToday);
        $cancelled = 0;

        ContributionDue::forTenant($tenantId)
            ->where('plan_id', $plan->id)
            ->where('status', 'pending')
            ->where('amount_paid', 0)
            ->where(function ($query) use ($businessToday): void {
                $query->whereDate('period_start', '>', $businessToday)
                    ->orWhere(function ($inner) use ($businessToday): void {
                        $inner->whereNull('period_start')
                            ->whereDate('due_date', '>', $businessToday);
                    });
            })
            ->whereNotIn('period_label', $validLabels)
            ->orderBy('id')
            ->chunkById(100, function ($dues) use ($tenantId, $userId, &$cancelled): void {
                foreach ($dues as $due) {
                    $this->cancelDue($tenantId, $userId, $due, 'Plan schedule reconciled');
                    $cancelled++;
                }
            });

        $result['cancelled_count'] = $cancelled;

        return $result;
    }

    public function cancelDue(int $tenantId, int $userId, ContributionDue $due, ?string $reason = null): ContributionDue
    {
        $oldStatus = $due->status;
        $due->status = 'cancelled';
        $due->notes = trim(($due->notes ? $due->notes.' ' : '').($reason ?? 'Cancelled'));
        $due->updated_by = $userId;
        $due->save();

        $this->auditService->log(
            $tenantId,
            'due.cancelled',
            'due',
            $due->id,
            ['status' => $oldStatus],
            ['status' => 'cancelled', 'reason' => $reason]
        );

        return $due->fresh(['family', 'plan']);
    }

    /**
     * Waive tenant-scoped dues. Unknown or ineligible IDs are skipped, never trusted.
     *
     * @param  array<int, string>  $dueIds
     * @return array{processed_count: int, skipped_count: int, processed_ids: array<int, string>, skipped: array<int, array{id: string, family_name: string|null, reason: string}>}
     */
    public function bulkWaive(int $tenantId, int $userId, array $dueIds, ?string $reason = null): array
    {
        return DB::transaction(function () use ($tenantId, $userId, $dueIds, $reason): array {
            return $this->processBulkDues($tenantId, $dueIds, function (ContributionDue $due) use ($tenantId, $userId, $reason): ?string {
                if ($due->status === 'waived') {
                    return 'This due is already waived.';
                }
                if (! in_array($due->status, ['pending', 'partially_paid'], true)) {
                    return 'Only pending or partially paid dues can be waived.';
                }

                $this->waiveDue($tenantId, $userId, $due, $reason);

                return null;
            }, true);
        });
    }

    /**
     * Queue reminders for tenant-scoped dues. Unknown IDs are skipped, never trusted.
     *
     * @param  array<int, string>  $dueIds
     * @return array{processed_count: int, skipped_count: int, processed_ids: array<int, string>, skipped: array<int, array{id: string, family_name: string|null, reason: string}>}
     */
    public function bulkRemind(int $tenantId, array $dueIds): array
    {
        $today = DonationBusinessDate::today($tenantId);

        return $this->processBulkDues($tenantId, $dueIds, function (ContributionDue $due) use ($tenantId, $today): ?string {
            if (! ContributionBalance::isCollectableOverdue($due, $today)) {
                return 'Reminders are sent only for collectable overdue dues.';
            }

            SendContributionReminderJob::dispatch($tenantId, $due->id);

            return null;
        });
    }

    /**
     * @param  array<int, string>  $dueIds
     * @param  callable(ContributionDue): (?string)  $apply  Return skip reason, or null when processed.
     * @return array{processed_count: int, skipped_count: int, processed_ids: array<int, string>, skipped: array<int, array{id: string, family_name: string|null, reason: string}>}
     */
    private function processBulkDues(int $tenantId, array $dueIds, callable $apply, bool $lockForUpdate = false): array
    {
        $uniqueIds = array_values(array_unique(array_filter($dueIds, fn ($id): bool => is_string($id) && $id !== '')));
        $query = ContributionDue::forTenant($tenantId)
            ->with('family')
            ->whereIn('id', $uniqueIds);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $dues = $query->get()->keyBy('id');

        $processedIds = [];
        $skipped = [];

        foreach ($uniqueIds as $id) {
            $due = $dues->get($id);
            if (! $due) {
                $skipped[] = [
                    'id' => $id,
                    'family_name' => null,
                    'reason' => 'This contribution due was not found in your parish.',
                ];

                continue;
            }

            $skipReason = $apply($due);
            if ($skipReason !== null) {
                $skipped[] = [
                    'id' => $id,
                    'family_name' => $due->family?->family_name,
                    'reason' => $skipReason,
                ];

                continue;
            }

            $processedIds[] = $id;
        }

        return [
            'processed_count' => count($processedIds),
            'skipped_count' => count($skipped),
            'processed_ids' => $processedIds,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return 'created'|'updated'|'unchanged'|'reactivated'|null
     */
    private function upsertDue(
        int $tenantId,
        int $userId,
        ContributionPlan $plan,
        string $familyId,
        string $periodLabel,
        string $dueDate,
        float $amountDue,
        ?string $notes,
        ?string $periodStart,
        ?string $periodEnd,
        bool $allowUpdatePending
    ): ?string {
        $existing = ContributionDue::forTenant($tenantId)
            ->where('plan_id', $plan->id)
            ->where('family_id', $familyId)
            ->where('period_label', $periodLabel)
            ->first();

        if ($existing) {
            if (in_array($existing->status, ['paid', 'waived'], true)) {
                return null;
            }

            if ($existing->status === 'cancelled') {
                if (! MoneyMath::isPositive($existing->amount_paid ?? 0)) {
                    $existing->status = 'pending';
                    $existing->due_date = $dueDate;
                    $existing->amount_due = MoneyMath::normalize($amountDue);
                    $existing->period_start = $periodStart;
                    $existing->period_end = $periodEnd;
                    if ($notes !== null) {
                        $existing->notes = $notes;
                    }
                    $existing->updated_by = $userId;
                    $existing->save();

                    return 'reactivated';
                }

                return null;
            }

            if (! $allowUpdatePending) {
                if ($existing->period_start === null && $periodStart !== null) {
                    $existing->period_start = $periodStart;
                    $existing->period_end = $periodEnd;
                    $existing->updated_by = $userId;
                    $existing->save();

                    return 'updated';
                }

                return 'unchanged';
            }

            $existing->due_date = $dueDate;
            $existing->amount_due = MoneyMath::normalize($amountDue);
            $existing->period_start = $periodStart;
            $existing->period_end = $periodEnd;
            if ($notes !== null) {
                $existing->notes = $notes;
            }
            $existing->updated_by = $userId;
            $existing->save();

            return 'updated';
        }

        try {
            ContributionDue::create([
                'tenant_id' => $tenantId,
                'family_id' => $familyId,
                'plan_id' => $plan->id,
                'period_label' => $periodLabel,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'due_date' => $dueDate,
                'amount_due' => MoneyMath::normalize($amountDue),
                'amount_paid' => 0,
                'status' => 'pending',
                'notes' => $notes,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->handleUniqueViolation(
                $tenantId,
                $plan,
                $familyId,
                $periodLabel,
                $dueDate,
                $amountDue,
                $notes,
                $periodStart,
                $periodEnd,
                $userId,
                $allowUpdatePending
            );
        }

        return 'created';
    }

    /**
     * @return 'created'|'updated'|'unchanged'|'reactivated'|null
     */
    private function handleUniqueViolation(
        int $tenantId,
        ContributionPlan $plan,
        string $familyId,
        string $periodLabel,
        string $dueDate,
        float $amountDue,
        ?string $notes,
        ?string $periodStart,
        ?string $periodEnd,
        int $userId,
        bool $allowUpdatePending
    ): ?string {
        $existing = ContributionDue::forTenant($tenantId)
            ->withTrashed()
            ->where('plan_id', $plan->id)
            ->where('family_id', $familyId)
            ->where('period_label', $periodLabel)
            ->first();

        if (! $existing) {
            return 'unchanged';
        }

        if ($existing->trashed()) {
            if (in_array($existing->status, ['paid', 'waived'], true)
                || MoneyMath::isPositive($existing->amount_paid ?? 0)) {
                return null;
            }

            $existing->restore();
            $existing->status = 'pending';
            $existing->due_date = $dueDate;
            $existing->amount_due = MoneyMath::normalize($amountDue);
            $existing->period_start = $periodStart;
            $existing->period_end = $periodEnd;
            if ($notes !== null) {
                $existing->notes = $notes;
            }
            $existing->updated_by = $userId;
            $existing->save();

            return 'reactivated';
        }

        return 'unchanged';
    }

    /**
     * @return array<int, array{id: string, joined_on: string}>
     */
    private function loadUniformFamiliesForPlan(ContributionPlan $plan): array
    {
        $timezone = DonationBusinessDate::timezoneForTenant((int) $plan->tenant_id);

        return Family::query()
            ->where('tenant_id', $plan->tenant_id)
            ->where('status', 'active')
            ->get(['id', 'created_at'])
            ->map(fn (Family $family): array => [
                'id' => $family->id,
                'joined_on' => $family->created_at
                    ? Carbon::parse($family->created_at)->timezone($timezone)->toDateString()
                    : DonationBusinessDate::today((int) $plan->tenant_id),
            ])
            ->all();
    }

    /**
     * @return Collection<int, ContributionPlanAssignment>
     */
    private function loadIndividualAssignmentsForPlan(ContributionPlan $plan)
    {
        return ContributionPlanAssignment::forTenant($plan->tenant_id)
            ->where('plan_id', $plan->id)
            ->where('status', 'active')
            ->where('is_exempt', false)
            ->get(['family_id', 'effective_from', 'effective_to']);
    }

    /**
     * @param  array<int, array{period_label: string, period_start: string, period_end: string, due_date: string}>  $periods
     * @param  array<int, array{id: string, joined_on: string}>|null  $uniformFamilies
     */
    private function estimateEnrolledFamilyCount(
        ContributionPlan $plan,
        array $periods,
        ?array $uniformFamilies = null,
        $individualAssignments = null
    ): int {
        $max = 0;

        foreach ($periods as $period) {
            $count = count($this->resolveEnrolledFamilyIds(
                $plan,
                $period['period_start'],
                $period['period_end'],
                $uniformFamilies,
                $individualAssignments
            ));
            $max = max($max, $count);
        }

        return $max;
    }

    /**
     * @param  array<int, array{id: string, joined_on: string}>|null  $uniformFamilies
     * @param  Collection<int, ContributionPlanAssignment>|null  $individualAssignments
     * @return array<int, string>
     */
    private function resolveEnrolledFamilyIds(
        ContributionPlan $plan,
        string $periodStart,
        ?string $periodEnd = null,
        ?array $uniformFamilies = null,
        $individualAssignments = null
    ): array {
        $periodEnd ??= $periodStart;

        if ($plan->plan_type === 'uniform') {
            $uniformFamilies ??= $this->loadUniformFamiliesForPlan($plan);

            return collect($uniformFamilies)
                ->filter(fn (array $family): bool => $family['joined_on'] <= $periodEnd)
                ->pluck('id')
                ->values()
                ->all();
        }

        $individualAssignments ??= $this->loadIndividualAssignmentsForPlan($plan);

        return $individualAssignments
            ->filter(function (ContributionPlanAssignment $assignment) use ($periodStart): bool {
                if ($assignment->effective_from->toDateString() > $periodStart) {
                    return false;
                }

                if ($assignment->effective_to && $assignment->effective_to->toDateString() < $periodStart) {
                    return false;
                }

                return true;
            })
            ->pluck('family_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{period_label: string, period_start: string, period_end: string, due_date: string}>  $periods
     */
    private function updateFuturePendingOnReconcile(
        int $tenantId,
        int $userId,
        ContributionPlan $plan,
        array $periods,
        string $businessToday
    ): void {
        $periodByLabel = collect($periods)->keyBy('period_label');

        ContributionDue::forTenant($tenantId)
            ->where('plan_id', $plan->id)
            ->where('status', 'pending')
            ->where('amount_paid', 0)
            ->where(function ($query) use ($businessToday): void {
                $query->whereDate('period_start', '>', $businessToday)
                    ->orWhere(function ($inner) use ($businessToday): void {
                        $inner->whereNull('period_start')
                            ->whereDate('due_date', '>', $businessToday);
                    });
            })
            ->orderBy('id')
            ->chunkById(100, function ($dues) use ($userId, $plan, $periodByLabel): void {
                foreach ($dues as $due) {
                    $period = $periodByLabel->get($due->period_label);
                    if ($period === null) {
                        continue;
                    }

                    $amount = $this->resolveAmountForFamily($plan, $due->family_id, $period['period_start']);
                    if ($amount === null || $amount <= 0) {
                        continue;
                    }

                    $due->due_date = ContributionPeriod::applyGraceDays($period['due_date'], (int) $plan->grace_days);
                    $due->amount_due = MoneyMath::normalize($amount);
                    $due->period_start = $period['period_start'];
                    $due->period_end = $period['period_end'];
                    $due->updated_by = $userId;
                    $due->save();
                }
            });
    }

    /**
     * @return array{eligible_family_count: int, exempt_family_count: int, already_billed_count: int, expected_new_amount: float|int}
     */
    private function emptyAssessment(): array
    {
        return [
            'eligible_family_count' => 0,
            'exempt_family_count' => 0,
            'already_billed_count' => 0,
            'expected_new_amount' => MoneyMath::toApiNumber(0),
        ];
    }

    /**
     * Read-only counts for the billing confirm. Does not write dues.
     *
     * @param  array<int, array{period_label: string, period_start: string, period_end: string, due_date: string}>  $periods
     * @param  array<int, string>|null  $familyIds
     * @return array{eligible_family_count: int, exempt_family_count: int, already_billed_count: int, expected_new_amount: float|int}
     */
    private function assessBillingPreview(ContributionPlan $plan, array $periods, ?array $familyIds): array
    {
        $labels = array_column($periods, 'period_label');
        $existingKeys = [];
        ContributionDue::forTenant((int) $plan->tenant_id)
            ->where('plan_id', $plan->id)
            ->whereIn('period_label', $labels)
            ->get(['family_id', 'period_label'])
            ->each(function (ContributionDue $due) use (&$existingKeys): void {
                $existingKeys[$due->family_id.'|'.$due->period_label] = true;
            });

        $eligibleFamilies = [];
        $exemptFamilies = [];
        $alreadyBilled = 0;
        $expectedNew = '0';

        foreach ($periods as $period) {
            $enrolled = $familyIds ?? $this->getEnrolledFamilyIds($plan, $period['period_start'], $period['period_end']);
            foreach ($enrolled as $familyId) {
                $amount = $this->resolveAmountForFamily($plan, $familyId, $period['period_start']);
                if ($amount === null || $amount <= 0) {
                    if ($this->familyIsExempt($plan, $familyId, $period['period_start'])) {
                        $exemptFamilies[$familyId] = true;
                    }
                    continue;
                }

                $eligibleFamilies[$familyId] = true;
                $key = $familyId.'|'.$period['period_label'];
                if (isset($existingKeys[$key])) {
                    $alreadyBilled++;
                    continue;
                }

                $expectedNew = MoneyMath::add($expectedNew, $amount);
            }
        }

        return [
            'eligible_family_count' => count($eligibleFamilies),
            'exempt_family_count' => count($exemptFamilies),
            'already_billed_count' => $alreadyBilled,
            'expected_new_amount' => MoneyMath::toApiNumber($expectedNew),
        ];
    }

    private function familyIsExempt(ContributionPlan $plan, string $familyId, string $asOfDate): bool
    {
        return (bool) $this->findActiveAssignment($plan, $familyId, $asOfDate)?->is_exempt;
    }

    private function findActiveAssignment(ContributionPlan $plan, string $familyId, string $asOfDate): ?ContributionPlanAssignment
    {
        return ContributionPlanAssignment::forTenant($plan->tenant_id)
            ->where('plan_id', $plan->id)
            ->where('family_id', $familyId)
            ->where('status', 'active')
            ->whereDate('effective_from', '<=', $asOfDate)
            ->where(function ($query) use ($asOfDate): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $asOfDate);
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    private function isPlanActiveOnDate(ContributionPlan $plan, string $asOfDate): bool
    {
        if ($plan->status !== 'active') {
            return false;
        }

        if ($plan->start_date && $plan->start_date->toDateString() > $asOfDate) {
            return false;
        }

        if ($plan->end_date && $plan->end_date->toDateString() < $asOfDate) {
            return false;
        }

        return true;
    }
}
