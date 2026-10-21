<?php

namespace Modules\Donations\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Donations\Models\Donation;
use Modules\Donations\Models\Donor;
use Modules\Donations\Models\RecurringDonationSchedule;
use Modules\Tenants\Services\ChurchCurrencyResolver;

class RecurringDonationScheduleService
{
    public function __construct(
        private readonly DonationLedgerService $ledgerService,
        private readonly DonationAuditService $auditService
    ) {
    }

    /**
     * @return array{processed:int, succeeded:int, failed:int, schedule_ids:array}
     */
    public function runDueSchedules(?int $tenantId = null): array
    {
        $query = RecurringDonationSchedule::query()
            ->where('status', 'active')
            ->whereDate('next_run_on', '<=', now()->toDateString());

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $scheduleIds = [];

        $query->chunk(100, function ($schedules) use (&$processed, &$succeeded, &$failed, &$scheduleIds): void {
            foreach ($schedules as $schedule) {
                $processed++;
                $scheduleIds[] = $schedule->id;
                $actorUserId = (int) ($schedule->updated_by ?? $schedule->created_by ?? 1);
                $lock = Cache::lock('donations:recurring-schedule:'.$schedule->id, 120);
                if (! $lock->get()) {
                    $failed++;
                    $this->auditService->log(
                        $schedule->tenant_id,
                        'recurring.execution_failed',
                        'recurring_schedule',
                        $schedule->id,
                        null,
                        null,
                        ['error' => 'Another run is already posting this schedule.']
                    );

                    continue;
                }

                try {
                    DB::transaction(function () use ($schedule, $actorUserId): void {
                        $currency = app(ChurchCurrencyResolver::class)->currencyCodeForTenantId((int) $schedule->tenant_id);
                        if (! is_string($currency) || $currency === '') {
                            throw new \RuntimeException('Parish currency is not configured.');
                        }
                        $donor = $schedule->donor_id
                            ? Donor::forTenant($schedule->tenant_id)->find($schedule->donor_id)
                            : null;

                        $donation = Donation::create([
                            'tenant_id' => $schedule->tenant_id,
                            'donor_id' => $schedule->donor_id,
                            'family_id' => $schedule->family_id,
                            'donation_category_id' => $schedule->donation_category_id,
                            'title' => 'Recurring Donation',
                            'pledged_amount' => (float) $schedule->amount,
                            'collected_amount' => 0,
                            'received_at' => now()->toDateString(),
                            'status' => 'pledged',
                            'is_anonymous' => (bool) ($donor?->is_anonymous ?? false),
                            'created_by' => $actorUserId,
                            'updated_by' => $actorUserId,
                        ]);

                        $payerName = $donor?->is_anonymous
                            ? 'Anonymous Donor'
                            : ($donor?->name ?? ($schedule->family_id ? 'Recurring Family Donation' : 'Recurring Donation'));

                        $payment = $this->ledgerService->createPayment($schedule->tenant_id, $actorUserId, [
                            'family_id' => $schedule->family_id,
                            'donor_id' => $schedule->donor_id,
                            'is_anonymous' => (bool) ($donor?->is_anonymous ?? false),
                            'payer_name' => $payerName,
                            'payer_email' => $donor?->email,
                            'payer_phone' => $donor?->phone,
                            'payment_date' => now()->toDateString(),
                            'amount' => (float) $schedule->amount,
                            'method' => 'online_placeholder',
                            'status' => 'succeeded',
                            'source_type' => 'recurring_schedule',
                            'notes' => sprintf('Recurring schedule %s execution', $schedule->id),
                            'allocations' => [
                                [
                                    'allocatable_type' => 'donation',
                                    'allocatable_id' => $donation->id,
                                    'amount' => (float) $schedule->amount,
                                ],
                            ],
                        ]);

                        $oldValues = $schedule->toArray();
                        $schedule->next_run_on = $this->nextRunDate($schedule->frequency, $schedule->next_run_on);
                        if ($schedule->end_on && $schedule->next_run_on->greaterThan($schedule->end_on)) {
                            $schedule->status = 'cancelled';
                        }
                        $schedule->updated_by = $actorUserId;
                        $schedule->save();

                        $this->auditService->log(
                            $schedule->tenant_id,
                            'recurring.executed',
                            'recurring_schedule',
                            $schedule->id,
                            $oldValues,
                            $schedule->toArray(),
                            ['payment_id' => $payment->id, 'donation_id' => $donation->id]
                        );
                    });

                    $succeeded++;
                } catch (\Throwable $exception) {
                    $failed++;
                    $this->auditService->log(
                        $schedule->tenant_id,
                        'recurring.execution_failed',
                        'recurring_schedule',
                        $schedule->id,
                        null,
                        null,
                        ['error' => $exception->getMessage()]
                    );
                } finally {
                    $lock->release();
                }
            }
        });

        return [
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'schedule_ids' => $scheduleIds,
        ];
    }

    public function updateStatus(RecurringDonationSchedule $schedule, int $userId, string $status): RecurringDonationSchedule
    {
        $oldValues = $schedule->toArray();
        $schedule->status = $status;
        $schedule->updated_by = $userId;
        $schedule->save();

        $this->auditService->log(
            $schedule->tenant_id,
            'recurring.updated',
            'recurring_schedule',
            $schedule->id,
            $oldValues,
            $schedule->toArray()
        );

        return $schedule;
    }

    private function nextRunDate(string $frequency, $fromDate)
    {
        $base = \Carbon\Carbon::parse($fromDate);

        return match ($frequency) {
            'weekly' => $base->copy()->addWeek(),
            'monthly' => $base->copy()->addMonth(),
            'quarterly' => $base->copy()->addMonths(3),
            'yearly' => $base->copy()->addYear(),
            default => $base->copy()->addMonth(),
        };
    }
}
