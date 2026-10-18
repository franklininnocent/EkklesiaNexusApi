<?php

namespace Modules\Donations\Services;

use Illuminate\Support\Collection;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\ContributionPlan;
use Modules\Donations\Models\ContributionPlanAssignment;
use Modules\Donations\Models\Donation;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationSetting;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Donations\Support\ContributionBalance;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Family\Models\Family;
use Modules\Tenants\Services\ChurchCurrencyResolver;
use Modules\Tenants\Services\ChurchFinancialPeriodResolver;
use Modules\Tenants\Support\ChurchFinancialPeriod;
use Modules\Tenants\Support\ChurchMoneyFormatter;

class FamilyFinancialProfileService
{
    public function __construct(
        private readonly DonationProjectService $projectService,
        private readonly FamilyFinancialAnalyticsService $analyticsService,
        private readonly FinancialHealthService $healthService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $tenantId, string $familyId): array
    {
        $this->assertFamilyBelongsToTenant($tenantId, $familyId);

        $asOfDate = DonationBusinessDate::today($tenantId);
        $fyPeriod = app(ChurchFinancialPeriodResolver::class)->currentFiscalYear($tenantId);
        $financialYear = $fyPeriod->label;
        $financialYearKey = $fyPeriod->key;
        $currency = app(ChurchCurrencyResolver::class)
            ->currencyCodeForTenantId($tenantId)
            ?? DonationSetting::forTenant($tenantId)->first()?->default_currency;

        $paymentBreakdown = $this->buildPaymentBreakdown($tenantId, $familyId);
        $mandatory = $this->buildMandatorySummary($tenantId, $familyId, $asOfDate);
        $projectSummary = $this->projectService->getFamilyProjectSummary($tenantId, $familyId);
        $projectSummary = $this->enrichProjectInstallmentStatus($tenantId, $familyId, $projectSummary, $asOfDate);
        $donationsOfferings = $this->buildDonationsOfferings($tenantId, $familyId, $fyPeriod, $asOfDate);
        $paymentHistory = $this->buildPaymentHistory($tenantId, $familyId);
        $outstandingBalances = $this->buildOutstandingBalances($mandatory, $projectSummary, $donationsOfferings);
        $analytics = $this->analyticsService->build($tenantId, $familyId, $mandatory, $paymentBreakdown);

        $pendingMandatoryDue = $mandatory['totals']['pending'];
        $pendingProjectDue = $projectSummary['totals']['installment_outstanding'];
        $pendingDue = round($pendingMandatoryDue + $pendingProjectDue, 2);

        $profile = [
            'family_id' => $familyId,
            'financial_year' => $financialYear,
            'currency' => $currency,
            'financial_health' => $this->buildFamilyHealth($mandatory, $projectSummary, $donationsOfferings, $paymentBreakdown, $analytics),
            'financial_timeline' => $this->buildFinancialTimeline($paymentHistory, $donationsOfferings, $mandatory),
            'engagement_heatmap' => $this->buildEngagementHeatmap($analytics),
            'fund_breakdown' => $this->buildFundBreakdown($mandatory, $projectSummary),
            'totals' => [
                'total_paid' => $paymentBreakdown['total_paid'],
                'mandatory_paid' => $paymentBreakdown['mandatory_paid'],
                'project_paid' => $paymentBreakdown['project_paid'],
                'voluntary_paid' => $paymentBreakdown['voluntary_paid'],
                'pending_due' => $pendingDue,
                'pending_mandatory_due' => round($pendingMandatoryDue, 2),
                'pending_project_due' => round($pendingProjectDue, 2),
                'overdue_count' => $mandatory['totals']['overdue_count'] + $this->countOverdueInstallmentsForFamily($tenantId, $familyId, $asOfDate),
                'overdue_amount' => round($mandatory['totals']['overdue_amount'] + $this->sumOverdueInstallmentAmountForFamily($tenantId, $familyId, $asOfDate), 2),
                'net' => round($paymentBreakdown['total_paid'] - $pendingDue, 2),
                'voluntary_collected' => $donationsOfferings['lifetime_collected'],
            ],
            'mandatory_contributions' => $mandatory,
            'project_contributions' => $projectSummary,
            'donations_offerings' => $donationsOfferings,
            'voluntary_donations' => [
                'totals' => [
                    'collected' => $donationsOfferings['lifetime_collected'],
                    'pledged_outstanding' => $donationsOfferings['pledged_outstanding'],
                    'entries' => $donationsOfferings['entry_count'],
                ],
                'recent_donations' => $donationsOfferings['recent_donations'],
            ],
            'payment_history' => $paymentHistory,
            'outstanding_balances' => $outstandingBalances,
            'analytics' => $analytics,
            'ai_insights' => $this->buildAiInsights($tenantId, $mandatory, $projectSummary, $donationsOfferings, $analytics, $outstandingBalances),
            'recommended_actions' => $this->buildRecommendedActions($mandatory, $projectSummary, $outstandingBalances),
        ];

        // Backward-compatible aliases used by existing UI/tests.
        $profile['recent_payments'] = $paymentHistory['recent'];
        $profile['outstanding_dues'] = $mandatory['outstanding_dues'];

        return $profile;
    }

    private function assertFamilyBelongsToTenant(int $tenantId, string $familyId): void
    {
        $family = Family::query()->find($familyId);

        if (! $family || (int) $family->tenant_id !== (int) $tenantId) {
            throw new \RuntimeException('Family does not belong to the tenant.');
        }
    }

    /**
     * @return array{total_paid: float, mandatory_paid: float, project_paid: float, voluntary_paid: float}
     */
    private function buildPaymentBreakdown(int $tenantId, string $familyId): array
    {
        $payments = DonationPayment::forTenant($tenantId)
            ->where('family_id', $familyId)
            ->where('status', 'succeeded')
            ->with('allocations')
            ->get();

        $mandatoryPaid = 0.0;
        $projectPaid = 0.0;
        $voluntaryPaid = 0.0;
        $totalPaid = 0.0;

        foreach ($payments as $payment) {
            $totalPaid += (float) $payment->amount;
            $allocated = 0.0;

            foreach ($payment->allocations as $allocation) {
                $amount = (float) $allocation->amount;
                if ($allocation->allocatable_type === 'advance') {
                    continue;
                }

                $allocated += $amount;
                match ($allocation->allocatable_type) {
                    'due' => $mandatoryPaid += $amount,
                    'project', 'project_installment' => $projectPaid += $amount,
                    'donation', 'fund', 'plan' => $voluntaryPaid += $amount,
                    default => null,
                };
            }

            $unallocated = max(0, (float) $payment->amount - $allocated);
            if ($unallocated > 0) {
                $voluntaryPaid += $unallocated;
            }
        }

        return [
            'total_paid' => round($totalPaid, 2),
            'mandatory_paid' => round($mandatoryPaid, 2),
            'project_paid' => round($projectPaid, 2),
            'voluntary_paid' => round($voluntaryPaid, 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMandatorySummary(int $tenantId, string $familyId, string $asOfDate): array
    {
        $allDues = ContributionDue::forTenant($tenantId)
            ->where('family_id', $familyId)
            ->with('plan:id,name,code,frequency,grace_days,status')
            ->get();

        $collectableDues = $allDues
            ->filter(fn (ContributionDue $due) => ContributionBalance::isCollectable($due, $asOfDate))
            ->sortBy('due_date')
            ->values();

        $outstandingDues = $collectableDues
            ->filter(fn (ContributionDue $due) => in_array(
                ContributionBalance::scheduleState($due, $asOfDate),
                ['overdue', 'due_today', 'grace'],
                true
            ))
            ->map(fn (ContributionDue $due) => $this->mapDueRow($due, $asOfDate));

        $currentPeriodDues = $collectableDues
            ->filter(fn (ContributionDue $due) => ContributionBalance::scheduleState($due, $asOfDate) === 'current')
            ->map(fn (ContributionDue $due) => $this->mapDueRow($due, $asOfDate));

        $scheduledDues = $allDues
            ->filter(fn (ContributionDue $due) => ContributionBalance::scheduleState($due, $asOfDate) === 'future')
            ->sortBy('due_date')
            ->take(50)
            ->values()
            ->map(fn (ContributionDue $due) => $this->mapDueRow($due, $asOfDate));

        $collectAllocationDues = $this->buildCollectAllocationDues($collectableDues, $asOfDate);

        $overdueDues = $outstandingDues
            ->filter(fn (array $row) => $row['is_overdue'])
            ->values();

        $assignments = $this->buildMandatoryAssignments($tenantId, $familyId, $asOfDate);
        $planSummaries = $this->buildContributionPlanSummaries($assignments, $allDues, $asOfDate, $tenantId);
        $contributionPlanTotals = $this->buildContributionPlanTotals($planSummaries);

        return [
            'assignments' => $assignments,
            'plan_summaries' => $planSummaries,
            'contribution_plans' => $planSummaries,
            'contribution_plan_totals' => $contributionPlanTotals,
            'outstanding_dues' => $outstandingDues->take(50)->values()->all(),
            'current_period_dues' => $currentPeriodDues->take(50)->values()->all(),
            'scheduled_dues' => $scheduledDues->all(),
            'collect_allocation_dues' => $collectAllocationDues,
            'overdue_dues' => $overdueDues->take(50)->values()->all(),
            'totals' => [
                'assigned' => $contributionPlanTotals['total_commitment'],
                'paid' => $contributionPlanTotals['total_paid'],
                'pending' => $contributionPlanTotals['total_outstanding'],
                'overdue_amount' => round((float) $overdueDues->sum('outstanding_amount'), 2),
                'overdue_count' => $overdueDues->count(),
            ],
        ];
    }

    /**
     * Merge active plan assignments with generated dues so families see obligations
     * even before dues are generated for the current period.
     *
     * @param  array<int, array<string, mixed>>  $assignments
     * @return array<int, array<string, mixed>>
     */
    private function buildContributionPlanSummaries(array $assignments, $allDues, string $asOfDate, int $tenantId): array
    {
        $duesByPlan = $allDues->groupBy('plan_id');
        $summariesByPlanId = [];

        foreach ($assignments as $assignment) {
            $planId = $assignment['plan_id'];
            $dues = $duesByPlan->get($planId, collect());
            $summariesByPlanId[$planId] = $this->summarizeContributionPlanForFamily($assignment, $dues, $asOfDate);
        }

        foreach ($duesByPlan as $planId => $dues) {
            if (isset($summariesByPlanId[$planId])) {
                continue;
            }

            $firstDue = $dues->first();
            $summariesByPlanId[$planId] = $this->summarizeContributionPlanForFamily([
                'plan_id' => $planId,
                'plan_name' => $firstDue->plan?->name,
                'plan_code' => $firstDue->plan?->code,
                'plan_type' => $firstDue->plan?->plan_type ?? null,
                'frequency' => $firstDue->plan?->frequency,
                'assigned_amount' => null,
                'is_exempt' => false,
                'plan_status' => $firstDue->plan?->status,
            ], $dues, $asOfDate);
        }

        return collect($summariesByPlanId)
            ->sortBy(fn (array $row) => strtolower((string) ($row['plan_name'] ?? '')))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $assignment
     * @param  Collection<int, ContributionDue>  $dues
     * @return array<string, mixed>
     */
    private function summarizeContributionPlanForFamily(array $assignment, $dues, string $asOfDate): array
    {
        $openDues = $dues->whereIn('status', ['pending', 'partially_paid']);
        $overdueCount = $dues->filter(fn (ContributionDue $due) => $this->isDueOverdue($due, $asOfDate))->count();

        $assignedAmount = ($assignment['is_exempt'] ?? false)
            ? 0.0
            : round((float) ($assignment['assigned_amount'] ?? 0), 2);

        if ($dues->isNotEmpty()) {
            $amountPaid = round((float) $dues->sum('amount_paid'), 2);
            $scheduledTotal = round((float) $dues->where('status', '!=', 'cancelled')->sum('amount_due'), 2);
            $installmentCount = $dues->where('status', '!=', 'cancelled')->count();

            $collectableRows = $openDues->filter(fn (ContributionDue $due) => ContributionBalance::isCollectable($due, $asOfDate));
            $outstandingBalance = round(
                (float) $collectableRows->sum(fn (ContributionDue $due) => ContributionBalance::outstandingForDue($due)),
                2
            );
            $overdueAmount = round(
                (float) $openDues
                    ->filter(fn (ContributionDue $due) => ContributionBalance::scheduleState($due, $asOfDate) === 'overdue')
                    ->sum(fn (ContributionDue $due) => ContributionBalance::outstandingForDue($due)),
                2
            );
            $currentPeriodAmount = round(
                (float) $openDues
                    ->filter(fn (ContributionDue $due) => ContributionBalance::scheduleState($due, $asOfDate) === 'current')
                    ->sum(fn (ContributionDue $due) => ContributionBalance::outstandingForDue($due)),
                2
            );
            $futureScheduledAmount = round(
                (float) $openDues
                    ->filter(fn (ContributionDue $due) => ContributionBalance::scheduleState($due, $asOfDate) === 'future')
                    ->sum(fn (ContributionDue $due) => ContributionBalance::outstandingForDue($due)),
                2
            );

            $nextDueDate = $openDues->sortBy('due_date')->first()?->due_date?->toDateString();
            $nextUpcomingDueDate = $openDues
                ->filter(fn (ContributionDue $due) => ($due->due_date?->toDateString() ?? '') >= $asOfDate)
                ->sortBy('due_date')
                ->first()?->due_date?->toDateString();

            if ($scheduledTotal > $assignedAmount) {
                $assignedAmount = $scheduledTotal;
            }
        } else {
            $amountPaid = 0.0;
            $scheduledTotal = 0.0;
            $installmentCount = 0;
            $outstandingBalance = $assignedAmount;
            $overdueAmount = 0.0;
            $currentPeriodAmount = 0.0;
            $futureScheduledAmount = 0.0;
            $nextDueDate = null;
            $nextUpcomingDueDate = null;
        }

        return [
            'plan_id' => $assignment['plan_id'],
            'plan_name' => $assignment['plan_name'] ?? null,
            'plan_code' => $assignment['plan_code'] ?? null,
            'frequency' => $assignment['frequency'] ?? null,
            'plan_type' => $assignment['plan_type'] ?? null,
            'assigned_amount' => $assignedAmount,
            'amount_paid' => $amountPaid,
            'amount_pending' => $outstandingBalance,
            'outstanding_balance' => $outstandingBalance,
            'scheduled_total' => $scheduledTotal,
            'overdue_amount' => $overdueAmount,
            'current_period_amount' => $currentPeriodAmount,
            'future_scheduled_amount' => $futureScheduledAmount,
            'installment_count' => $installmentCount,
            'next_due_date' => $nextDueDate,
            'next_upcoming_due_date' => $nextUpcomingDueDate,
            'overdue_count' => $overdueCount,
            'status' => $this->resolveContributionPlanStatus($assignment, $dues, $overdueCount, $outstandingBalance),
            'is_exempt' => (bool) ($assignment['is_exempt'] ?? false),
            'effective_from' => $assignment['effective_from'] ?? null,
            'effective_to' => $assignment['effective_to'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $assignment
     * @param  Collection<int, ContributionDue>  $dues
     */
    private function resolveContributionPlanStatus(
        array $assignment,
        $dues,
        int $overdueCount,
        float $outstandingBalance
    ): string {
        if (($assignment['plan_status'] ?? 'active') !== 'active') {
            return 'inactive';
        }

        if ($overdueCount > 0) {
            return 'overdue';
        }

        if ($dues->isNotEmpty() && $outstandingBalance <= 0) {
            return 'completed';
        }

        return 'active';
    }

    /**
     * @param  array<int, array<string, mixed>>  $planSummaries
     * @return array<string, float|int>
     */
    private function buildContributionPlanTotals(array $planSummaries): array
    {
        $activePlans = collect($planSummaries)->filter(
            fn (array $row) => in_array($row['status'] ?? 'active', ['active', 'overdue'], true)
        );

        return [
            'total_commitment' => round((float) collect($planSummaries)->sum('assigned_amount'), 2),
            'total_paid' => round((float) collect($planSummaries)->sum('amount_paid'), 2),
            'total_outstanding' => round((float) collect($planSummaries)->sum('outstanding_balance'), 2),
            'active_plans_count' => $activePlans->count(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildMandatoryAssignments(int $tenantId, string $familyId, string $asOfDate): array
    {
        $assignments = ContributionPlanAssignment::forTenant($tenantId)
            ->where('family_id', $familyId)
            ->where('status', 'active')
            ->whereDate('effective_from', '<=', $asOfDate)
            ->where(function ($query) use ($asOfDate): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $asOfDate);
            })
            ->with('plan:id,name,code,plan_type,frequency,default_amount,status')
            ->get();

        $assignmentRows = $assignments->map(function (ContributionPlanAssignment $assignment): array {
            $amount = $assignment->is_exempt
                ? null
                : ($assignment->amount ?? $assignment->plan?->default_amount);

            return [
                'plan_id' => $assignment->plan_id,
                'plan_name' => $assignment->plan?->name,
                'plan_code' => $assignment->plan?->code,
                'plan_type' => $assignment->plan?->plan_type,
                'frequency' => $assignment->plan?->frequency,
                'assigned_amount' => $amount !== null ? (float) $amount : null,
                'is_exempt' => $assignment->is_exempt,
                'effective_from' => $assignment->effective_from?->toDateString(),
                'effective_to' => $assignment->effective_to?->toDateString(),
                'plan_status' => $assignment->plan?->status,
            ];
        });

        $uniformPlans = ContributionPlan::forTenant($tenantId)
            ->where('status', 'active')
            ->where('plan_type', 'uniform')
            ->where(function ($query) use ($asOfDate): void {
                $query->whereNull('start_date')->orWhereDate('start_date', '<=', $asOfDate);
            })
            ->where(function ($query) use ($asOfDate): void {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $asOfDate);
            })
            ->get(['id', 'name', 'code', 'plan_type', 'frequency', 'default_amount', 'status']);

        $overridePlanIds = $assignments->pluck('plan_id')->all();
        $uniformRows = $uniformPlans
            ->reject(fn ($plan) => in_array($plan->id, $overridePlanIds, true))
            ->map(fn ($plan) => [
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'plan_code' => $plan->code,
                'plan_type' => $plan->plan_type,
                'frequency' => $plan->frequency,
                'assigned_amount' => (float) $plan->default_amount,
                'is_exempt' => false,
                'effective_from' => null,
                'effective_to' => null,
                'plan_status' => $plan->status,
            ]);

        return $assignmentRows->concat($uniformRows)->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDonationsOfferings(
        int $tenantId,
        string $familyId,
        ChurchFinancialPeriod $fyPeriod,
        string $asOfDate
    ): array {
        $baseQuery = Donation::forTenant($tenantId)->where('family_id', $familyId);

        $lifetimeCollected = (float) (clone $baseQuery)->sum('collected_amount');
        $fyEnd = min($fyPeriod->end, $asOfDate);
        $currentYearCollected = (float) (clone $baseQuery)
            ->where(function ($query) use ($fyPeriod, $fyEnd): void {
                $query->whereBetween('received_at', [$fyPeriod->start, $fyEnd])
                    ->orWhereIn('financial_year', $this->legacyFinancialYearKeys($fyPeriod));
            })
            ->sum('collected_amount');
        $lastDonationDate = (clone $baseQuery)
            ->where('collected_amount', '>', 0)
            ->max('received_at');
        $pledgedOutstanding = (float) (clone $baseQuery)
            ->whereIn('status', ['pledged', 'partially_paid'])
            ->get()
            ->sum(fn (Donation $donation) => max((float) $donation->pledged_amount - (float) $donation->collected_amount, 0));

        $recentDonations = (clone $baseQuery)
            ->with(['category:id,name', 'donor:id,name'])
            ->orderByDesc('received_at')
            ->limit(20)
            ->get()
            ->map(fn (Donation $donation) => [
                'id' => $donation->id,
                'title' => $donation->title,
                'category' => $donation->category?->name,
                'donor' => $donation->is_anonymous ? 'Anonymous' : $donation->donor?->name,
                'status' => $donation->status,
                'pledged_amount' => (float) $donation->pledged_amount,
                'collected_amount' => (float) $donation->collected_amount,
                'received_at' => $donation->received_at?->toDateString(),
                'is_anonymous' => (bool) $donation->is_anonymous,
            ])
            ->values()
            ->all();

        $byCategory = (clone $baseQuery)
            ->with('category:id,name')
            ->where('collected_amount', '>', 0)
            ->get()
            ->groupBy(fn (Donation $donation) => $donation->donation_category_id ?? 'uncategorized')
            ->map(function ($group, $categoryId) {
                $first = $group->first();

                return [
                    'category_id' => $categoryId === 'uncategorized' ? null : $categoryId,
                    'category_name' => $first->category?->name ?? 'Uncategorized',
                    'collected' => round((float) $group->sum('collected_amount'), 2),
                ];
            })
            ->values()
            ->all();

        return [
            'lifetime_collected' => round($lifetimeCollected, 2),
            'current_financial_year_collected' => round($currentYearCollected, 2),
            'financial_year' => $fyPeriod->label,
            'financial_year_key' => $fyPeriod->key,
            'last_donation_date' => $lastDonationDate ? (string) $lastDonationDate : null,
            'pledged_outstanding' => round($pledgedOutstanding, 2),
            'entry_count' => (int) (clone $baseQuery)->count(),
            'by_category' => $byCategory,
            'recent_donations' => $recentDonations,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPaymentHistory(int $tenantId, string $familyId): array
    {
        $payments = DonationPayment::forTenant($tenantId)
            ->where('family_id', $familyId)
            ->with(['allocations', 'receipt'])
            ->orderByDesc('payment_date')
            ->limit(50)
            ->get();

        $ledger = $payments->map(function (DonationPayment $payment): array {
            return [
                'id' => $payment->id,
                'payment_number' => $payment->payment_number,
                'payment_date' => $payment->payment_date?->toDateString(),
                'payer_name' => $payment->is_anonymous ? 'Anonymous Donor' : $payment->payer_name,
                'amount' => (float) $payment->amount,
                'method' => $payment->method,
                'status' => $payment->status,
                'source_type' => $payment->source_type,
                'is_anonymous' => (bool) $payment->is_anonymous,
                'receipt_id' => $payment->receipt?->id,
                'receipt_number' => $payment->receipt?->receipt_number,
                'allocations' => $payment->allocations->map(fn ($allocation) => [
                    'allocatable_type' => $allocation->allocatable_type,
                    'allocatable_id' => $allocation->allocatable_id,
                    'amount' => (float) $allocation->amount,
                ])->values()->all(),
            ];
        })->values()->all();

        return [
            'recent' => array_slice($ledger, 0, 10),
            'ledger' => $ledger,
            'total_transactions' => DonationPayment::forTenant($tenantId)->where('family_id', $familyId)->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $mandatory
     * @param  array<string, mixed>  $projectSummary
     * @param  array<string, mixed>  $donationsOfferings
     * @return array<string, mixed>
     */
    private function buildOutstandingBalances(array $mandatory, array $projectSummary, array $donationsOfferings): array
    {
        return [
            'mandatory' => round($mandatory['totals']['pending'], 2),
            'mandatory_overdue' => round($mandatory['totals']['overdue_amount'], 2),
            'project' => round($projectSummary['totals']['outstanding'] ?? 0, 2),
            'project_installments' => round($projectSummary['totals']['installment_outstanding'] ?? 0, 2),
            'voluntary_pledged' => round($donationsOfferings['pledged_outstanding'], 2),
            'total' => round(
                $mandatory['totals']['pending']
                + ($projectSummary['totals']['installment_outstanding'] ?? 0)
                + $donationsOfferings['pledged_outstanding'],
                2
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $projectSummary
     * @return array<string, mixed>
     */
    private function enrichProjectInstallmentStatus(int $tenantId, string $familyId, array $projectSummary, string $businessDate): array
    {
        foreach ($projectSummary['projects'] as &$project) {
            $installments = ProjectInstallmentDue::forTenant($tenantId)
                ->where('family_id', $familyId)
                ->where('project_id', $project['project_id'])
                ->get();

            $project['installment_status'] = [
                'total' => $installments->count(),
                'paid' => $installments->where('status', 'paid')->count(),
                'pending' => $installments->whereIn('status', ['pending', 'partially_paid'])
                    ->filter(fn (ProjectInstallmentDue $due) => ($due->due_date?->toDateString() ?? '') >= $businessDate)
                    ->count(),
                'overdue' => $installments->whereIn('status', ['pending', 'partially_paid'])
                    ->filter(fn (ProjectInstallmentDue $due) => ($due->due_date?->toDateString() ?? '') < $businessDate)
                    ->count(),
            ];
        }
        unset($project);

        $allInstallments = ProjectInstallmentDue::forTenant($tenantId)
            ->where('family_id', $familyId)
            ->with('project:id,name,code')
            ->orderByDesc('due_date')
            ->limit(30)
            ->get()
            ->map(function (ProjectInstallmentDue $due) use ($businessDate): array {
                $outstanding = ContributionBalance::outstandingForDue($due);
                $dueDate = $due->due_date?->toDateString();

                return [
                    'id' => $due->id,
                    'project_id' => $due->project_id,
                    'project_name' => $due->project?->name,
                    'installment_label' => $due->installment_label,
                    'due_date' => $dueDate,
                    'amount_due' => (float) $due->amount_due,
                    'amount_paid' => (float) $due->amount_paid,
                    'outstanding_amount' => $outstanding,
                    'status' => $due->status,
                    'is_overdue' => in_array($due->status, ['pending', 'partially_paid'], true)
                        && $dueDate
                        && $dueDate < $businessDate,
                ];
            })
            ->values()
            ->all();

        $projectSummary['installment_ledger'] = $allInstallments;

        return $projectSummary;
    }

    private function mapDueRow(ContributionDue $due, string $asOfDate): array
    {
        $scheduleState = ContributionBalance::scheduleState($due, $asOfDate);

        return [
            'id' => $due->id,
            'plan_id' => $due->plan_id,
            'period_label' => $due->period_label,
            'period_start' => $due->period_start?->toDateString(),
            'period_end' => $due->period_end?->toDateString(),
            'due_date' => $due->due_date?->toDateString(),
            'amount_due' => (float) $due->amount_due,
            'amount_paid' => (float) $due->amount_paid,
            'outstanding_amount' => ContributionBalance::outstandingForDue($due),
            'status' => $due->status,
            'schedule_state' => $scheduleState,
            'is_overdue' => $scheduleState === 'overdue',
            'plan' => $due->plan ? [
                'id' => $due->plan->id,
                'name' => $due->plan->name,
                'code' => $due->plan->code,
                'frequency' => $due->plan->frequency,
            ] : null,
        ];
    }

    /**
     * @param  Collection<int, ContributionDue>  $collectableDues
     * @return array<int, array<string, mixed>>
     */
    private function buildCollectAllocationDues($collectableDues, string $asOfDate): array
    {
        $overdue = $collectableDues
            ->filter(fn (ContributionDue $due) => ContributionBalance::scheduleState($due, $asOfDate) === 'overdue')
            ->sortBy('due_date')
            ->take(2)
            ->values();

        $current = $collectableDues
            ->filter(fn (ContributionDue $due) => in_array(
                ContributionBalance::scheduleState($due, $asOfDate),
                ['current', 'due_today'],
                true
            ))
            ->sortBy('due_date')
            ->take(1)
            ->values();

        return $overdue
            ->concat($current)
            ->unique('id')
            ->map(fn (ContributionDue $due) => $this->mapDueRow($due, $asOfDate))
            ->values()
            ->all();
    }

    private function isDueOverdue(ContributionDue $due, string $asOfDate): bool
    {
        return ContributionBalance::scheduleState($due, $asOfDate) === 'overdue';
    }

    private function countOverdueInstallmentsForFamily(int $tenantId, string $familyId, string $businessDate): int
    {
        return ContributionBalance::scopeOverdue(
            ProjectInstallmentDue::forTenant($tenantId)->where('family_id', $familyId),
            $businessDate
        )->count();
    }

    private function sumOverdueInstallmentAmountForFamily(int $tenantId, string $familyId, string $businessDate): float
    {
        return ContributionBalance::sumFlooredOutstanding(
            ContributionBalance::scopeOverdue(
                ProjectInstallmentDue::forTenant($tenantId)->where('family_id', $familyId),
                $businessDate
            )
        );
    }

    /**
     * @return array<int, string>
     */
    private function legacyFinancialYearKeys(ChurchFinancialPeriod $fyPeriod): array
    {
        $startYear = (int) $fyPeriod->key;
        $endYear = (int) substr($fyPeriod->end, 0, 4);

        return array_values(array_unique([
            $fyPeriod->key,
            $fyPeriod->label,
            sprintf('%d-%d', $startYear, $endYear),
            sprintf('%d-%02d', $startYear, $endYear % 100),
            (string) $startYear,
        ]));
    }

    /**
     * @param  array<string, mixed>  $mandatory
     * @param  array<string, mixed>  $projectSummary
     * @param  array<string, mixed>  $donationsOfferings
     * @param  array<string, mixed>  $paymentHistory
     * @param  array{total_paid: float, mandatory_paid: float, project_paid: float, voluntary_paid: float}  $paymentBreakdown
     * @return array<string, mixed>
     */
    private function buildFamilyHealth(
        array $mandatory,
        array $projectSummary,
        array $donationsOfferings,
        array $paymentBreakdown,
        array $analytics
    ): array {
        $assigned = (float) ($mandatory['totals']['assigned'] ?? 0);
        $mandatoryPaid = (float) ($mandatory['totals']['paid'] ?? 0);
        $mandatoryCompliance = $assigned > 0 ? min(100, ($mandatoryPaid / $assigned) * 100) : 100.0;

        $projectTarget = (float) ($projectSummary['totals']['target_total'] ?? 0);
        $projectCollected = (float) ($projectSummary['totals']['collected'] ?? 0);
        $projectParticipation = $projectTarget > 0 ? min(100, ($projectCollected / $projectTarget) * 100) : 100.0;

        $lifetimeVoluntary = (float) ($donationsOfferings['lifetime_collected'] ?? 0);
        $voluntaryEngagement = $lifetimeVoluntary > 0
            ? min(100, 60 + min(40, ($donationsOfferings['entry_count'] ?? 1) * 10))
            : 0.0;

        $punctualityScore = (float) ($analytics['punctuality']['score'] ?? 100);

        $health = $this->healthService->buildFamilyScore([
            'punctuality_score' => $punctualityScore,
            'mandatory_compliance_pct' => $mandatoryCompliance,
            'project_participation_pct' => $projectParticipation,
            'voluntary_engagement_pct' => $voluntaryEngagement,
        ]);

        return array_merge($health, [
            'factors' => [
                'punctuality' => round($punctualityScore, 1),
                'mandatory_compliance' => round($mandatoryCompliance, 1),
                'project_participation' => round($projectParticipation, 1),
                'voluntary_engagement' => round($voluntaryEngagement, 1),
            ],
            'total_paid' => round($paymentBreakdown['total_paid'], 2),
            'outstanding' => round((float) ($mandatory['totals']['pending'] ?? 0) + (float) ($projectSummary['totals']['installment_outstanding'] ?? 0), 2),
        ]);
    }

    /**
     * @param  array<string, mixed>  $paymentHistory
     * @param  array<string, mixed>  $donationsOfferings
     * @param  array<string, mixed>  $mandatory
     * @return array<int, array<string, mixed>>
     */
    private function buildFinancialTimeline(array $paymentHistory, array $donationsOfferings, array $mandatory): array
    {
        $events = [];

        foreach ($paymentHistory['ledger'] ?? [] as $payment) {
            $events[] = [
                'type' => 'payment',
                'id' => $payment['id'],
                'date' => $payment['payment_date'],
                'title' => 'Payment received',
                'subtitle' => $payment['payer_name'] ?? 'Payer',
                'amount' => (float) $payment['amount'],
                'reference' => $payment['receipt_number'] ?? $payment['payment_number'],
                'status' => $payment['status'],
            ];
        }

        foreach ($donationsOfferings['recent_donations'] ?? [] as $donation) {
            if ((float) ($donation['collected_amount'] ?? 0) <= 0) {
                continue;
            }

            $events[] = [
                'type' => 'donation',
                'id' => $donation['id'],
                'date' => $donation['received_at'],
                'title' => $donation['title'] ?? 'Offering',
                'subtitle' => $donation['category'] ?? 'Voluntary gift',
                'amount' => (float) $donation['collected_amount'],
                'reference' => null,
                'status' => $donation['status'],
            ];
        }

        foreach ($mandatory['overdue_dues'] ?? [] as $due) {
            $events[] = [
                'type' => 'overdue',
                'id' => $due['id'],
                'date' => $due['due_date'],
                'title' => 'Overdue contribution',
                'subtitle' => $due['plan']['name'] ?? $due['period_label'] ?? 'Mandatory due',
                'amount' => (float) ($due['outstanding_amount'] ?? 0),
                'reference' => null,
                'status' => 'overdue',
            ];
        }

        usort($events, function (array $a, array $b): int {
            return strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''));
        });

        return array_slice($events, 0, 40);
    }

    /**
     * @param  array<string, mixed>  $analytics
     * @return array<int, array<string, mixed>>
     */
    private function buildEngagementHeatmap(array $analytics): array
    {
        return collect($analytics['trend'] ?? [])
            ->map(function (array $row): array {
                $total = (float) ($row['total_paid'] ?? 0);
                $mandatory = (float) ($row['mandatory_paid'] ?? 0);

                return [
                    'period' => $row['period'] ?? null,
                    'label' => $row['label'] ?? null,
                    'status' => $total > 0 ? ($mandatory > 0 ? 'paid' : 'partial') : 'empty',
                    'total_paid' => round($total, 2),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $mandatory
     * @param  array<string, mixed>  $projectSummary
     * @return array<int, array<string, mixed>>
     */
    private function buildFundBreakdown(array $mandatory, array $projectSummary): array
    {
        $rows = collect($mandatory['plan_summaries'] ?? [])->map(fn (array $plan) => [
            'label' => $plan['plan_name'] ?? $plan['plan_code'] ?? 'Mandatory Plan',
            'type' => 'mandatory',
            'assigned' => (float) ($plan['assigned_amount'] ?? 0),
            'settled' => (float) ($plan['amount_paid'] ?? 0),
            'remaining' => (float) ($plan['amount_pending'] ?? 0),
        ]);

        $projectRows = collect($projectSummary['projects'] ?? [])->map(fn (array $project) => [
            'label' => $project['project_name'] ?? 'Project',
            'type' => 'project',
            'assigned' => (float) ($project['target_amount'] ?? 0),
            'settled' => (float) ($project['amount_collected'] ?? 0),
            'remaining' => (float) ($project['outstanding_amount'] ?? 0),
        ]);

        return $rows->concat($projectRows)->values()->all();
    }

    /**
     * @param  array<string, mixed>  $mandatory
     * @param  array<string, mixed>  $projectSummary
     * @param  array<string, mixed>  $donationsOfferings
     * @param  array<string, mixed>  $analytics
     * @param  array<string, mixed>  $outstandingBalances
     * @return array<int, string>
     */
    private function buildAiInsights(
        int $tenantId,
        array $mandatory,
        array $projectSummary,
        array $donationsOfferings,
        array $analytics,
        array $outstandingBalances
    ): array {
        $insights = [];
        $overdueAmount = (float) ($mandatory['totals']['overdue_amount'] ?? 0);
        $pendingMandatory = (float) ($mandatory['totals']['pending'] ?? 0);
        $projectOutstanding = (float) ($projectSummary['totals']['installment_outstanding'] ?? 0);
        $punctuality = (int) ($analytics['punctuality']['score'] ?? 0);
        $rank = (int) ($analytics['ranking']['by_total_giving'] ?? 0);
        $percentile = (float) ($analytics['ranking']['percentile'] ?? 0);

        if ($overdueAmount > 0) {
            $insights[] = sprintf(
                'This family has %s overdue across mandatory contributions.',
                ChurchMoneyFormatter::formatForTenant($tenantId, $overdueAmount)
            );
        } elseif ($pendingMandatory <= 0) {
            $insights[] = 'Mandatory contributions are fully settled for the current period.';
        }

        if ($projectOutstanding > 0) {
            $insights[] = sprintf(
                'Project installments still need %s.',
                ChurchMoneyFormatter::formatForTenant($tenantId, $projectOutstanding)
            );
        }

        if (($donationsOfferings['lifetime_collected'] ?? 0) > 0) {
            $insights[] = sprintf(
                'The family has contributed %s in voluntary offerings over time.',
                ChurchMoneyFormatter::formatForTenant($tenantId, (float) $donationsOfferings['lifetime_collected'])
            );
        }

        $insights[] = sprintf('Punctuality score is %s%% across recent contribution periods.', $punctuality);

        if ($rank > 0) {
            $insights[] = sprintf('Giving rank is #%d (top %s%% of participating families).', $rank, round(max(0, 100 - $percentile), 1));
        }

        if ((float) ($outstandingBalances['total'] ?? 0) <= 0) {
            $insights[] = 'No outstanding balance remains across mandatory, project, and pledged voluntary giving.';
        }

        return array_values(array_unique($insights));
    }

    /**
     * @param  array<string, mixed>  $mandatory
     * @param  array<string, mixed>  $projectSummary
     * @param  array<string, mixed>  $outstandingBalances
     * @return array<int, string>
     */
    private function buildRecommendedActions(array $mandatory, array $projectSummary, array $outstandingBalances): array
    {
        $actions = [];

        if ((float) ($mandatory['totals']['overdue_amount'] ?? 0) > 0) {
            $actions[] = 'Use Quick Collect to settle overdue mandatory dues during the next parish visit.';
        }

        if ((float) ($projectSummary['totals']['installment_outstanding'] ?? 0) > 0) {
            $actions[] = 'Review open project installments and collect the next due installment.';
        }

        if ((float) ($outstandingBalances['voluntary_pledged'] ?? 0) > 0) {
            $actions[] = 'Follow up on pledged voluntary donations that remain outstanding.';
        }

        if (empty($actions)) {
            $actions[] = 'Stewardship is healthy. Send a gratitude note and invite the family to upcoming parish projects.';
        }

        return $actions;
    }
}
