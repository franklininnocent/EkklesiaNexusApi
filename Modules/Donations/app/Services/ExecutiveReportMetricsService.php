<?php

namespace Modules\Donations\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\PaymentAllocation;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Donations\Support\ContributionBalance;
use Modules\Donations\Support\DashboardBccFilter;
use Modules\Donations\Support\DashboardDateRange;
use Modules\Donations\Support\DashboardProjectFilter;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Support\MoneyMath;
use Modules\Family\Models\Family;

/**
 * Single source of truth for Leadership Report / executive graph metrics and drill-down scopes.
 */
class ExecutiveReportMetricsService
{
    /** Prior-period collections below this share of current are treated as too small for growth health. */
    private const GROWTH_PRIOR_MIN_SHARE_OF_CURRENT = 0.05;

    /**
     * @return array{start: string, end: string, timezone: string, business_date: string}
     */
    public function parishClock(int $tenantId): array
    {
        $timezone = DonationBusinessDate::timezoneForTenant($tenantId);
        $businessDate = DonationBusinessDate::today($tenantId);

        return [
            'start' => DonationBusinessDate::monthStart($tenantId),
            'end' => $businessDate,
            'timezone' => $timezone,
            'business_date' => $businessDate,
        ];
    }

    /**
     * Current month collections: month-to-date through business today (excludes future payment_date).
     *
     * @return array{start: string, end: string}
     */
    public function currentMonthCollectionRange(int $tenantId): array
    {
        $clock = $this->parishClock($tenantId);

        return ['start' => $clock['start'], 'end' => $clock['end']];
    }

    /**
     * @return array{start: string, end: string}
     */
    public function previousMonthCollectionRange(int $tenantId): array
    {
        $timezone = DonationBusinessDate::timezoneForTenant($tenantId);
        $today = DonationBusinessDate::today($tenantId);
        $currentMonth = Carbon::parse($today, $timezone);

        return [
            'start' => $currentMonth->copy()->subMonth()->startOfMonth()->toDateString(),
            'end' => $currentMonth->copy()->subMonth()->endOfMonth()->toDateString(),
        ];
    }

    /**
     * Same elapsed calendar days in the prior month as MTD through parish business today.
     *
     * @return array{start: string, end: string}
     */
    public function comparablePreviousMonthCollectionRange(int $tenantId): array
    {
        $timezone = DonationBusinessDate::timezoneForTenant($tenantId);
        $today = DonationBusinessDate::today($tenantId);
        $current = Carbon::parse($today, $timezone);
        $previousMonth = $current->copy()->subMonth();
        $endDay = min($current->day, $previousMonth->daysInMonth);

        return [
            'start' => $previousMonth->copy()->startOfMonth()->toDateString(),
            'end' => $previousMonth->copy()->day($endDay)->toDateString(),
        ];
    }

    /**
     * @return array{start: string, end: string}
     */
    public function participationWindow(int $tenantId, ?DashboardDateRange $range = null): array
    {
        if ($range !== null) {
            return [
                'start' => $range->dateFrom,
                'end' => $range->collectionEnd,
            ];
        }

        return [
            'start' => DonationBusinessDate::subDays($tenantId, 89),
            'end' => DonationBusinessDate::today($tenantId),
        ];
    }

    public function sumSucceededPayments(
        int $tenantId,
        string $startDate,
        string $endDate,
        ?DashboardBccFilter $bccFilter = null,
        ?DashboardProjectFilter $projectFilter = null
    ): float {
        $bccFilter ??= DashboardBccFilter::none();
        $projectFilter ??= DashboardProjectFilter::none();

        if ($projectFilter->isActive) {
            return $this->sumProjectFundingAllocations($tenantId, $startDate, $endDate, $bccFilter, $projectFilter);
        }

        $query = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereDate('payment_date', '>=', $startDate)
            ->whereDate('payment_date', '<=', $endDate);
        $bccFilter->applyToDonationPaymentQuery($query, $tenantId);
        $sum = $query->sum('amount');

        return MoneyMath::toApiNumber($sum);
    }

    /**
     * @return array<string, float>
     */
    public function sumProjectFundingAllocationsByMonth(
        int $tenantId,
        string $firstStart,
        string $lastEnd,
        ?DashboardBccFilter $bccFilter = null,
        ?DashboardProjectFilter $projectFilter = null
    ): array {
        $bccFilter ??= DashboardBccFilter::none();
        $projectFilter ??= DashboardProjectFilter::none();
        if (! $projectFilter->isActive) {
            return [];
        }

        $monthExpr = $this->sqlYearMonthExpression('donation_payments.payment_date');
        $query = PaymentAllocation::query()
            ->where('payment_allocations.tenant_id', $tenantId)
            ->whereNull('payment_allocations.deleted_at')
            ->join('donation_payments', 'donation_payments.id', '=', 'payment_allocations.payment_id')
            ->where('donation_payments.tenant_id', $tenantId)
            ->where('donation_payments.status', 'succeeded')
            ->whereNull('donation_payments.deleted_at')
            ->whereBetween('donation_payments.payment_date', [$firstStart, $lastEnd]);
        $projectFilter->applyToPaymentAllocationQuery($query, $tenantId);
        $paymentScope = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereBetween('payment_date', [$firstStart, $lastEnd]);
        $bccFilter->applyToDonationPaymentQuery($paymentScope, $tenantId);
        $query->whereIn('payment_allocations.payment_id', $paymentScope->select('id'));

        $rows = $query
            ->selectRaw("{$monthExpr} as period, COALESCE(SUM(payment_allocations.amount), 0) as collected")
            ->groupByRaw($monthExpr)
            ->get();

        $collectedByMonth = [];
        foreach ($rows as $row) {
            $key = trim((string) $row->period);
            if ($key === '') {
                continue;
            }
            $collectedByMonth[$key] = MoneyMath::toApiNumber($row->collected);
        }

        return $collectedByMonth;
    }

    public function sumProjectFundingAllocations(
        int $tenantId,
        string $startDate,
        string $endDate,
        ?DashboardBccFilter $bccFilter = null,
        ?DashboardProjectFilter $projectFilter = null
    ): float {
        $bccFilter ??= DashboardBccFilter::none();
        $projectFilter ??= DashboardProjectFilter::none();

        $query = PaymentAllocation::query()
            ->where('payment_allocations.tenant_id', $tenantId)
            ->whereNull('payment_allocations.deleted_at')
            ->join('donation_payments', 'donation_payments.id', '=', 'payment_allocations.payment_id')
            ->where('donation_payments.tenant_id', $tenantId)
            ->where('donation_payments.status', 'succeeded')
            ->whereNull('donation_payments.deleted_at')
            ->whereDate('donation_payments.payment_date', '>=', $startDate)
            ->whereDate('donation_payments.payment_date', '<=', $endDate);
        $projectFilter->applyToPaymentAllocationQuery($query, $tenantId);

        $paymentScope = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereDate('payment_date', '>=', $startDate)
            ->whereDate('payment_date', '<=', $endDate);
        $bccFilter->applyToDonationPaymentQuery($paymentScope, $tenantId);
        $query->whereIn('payment_allocations.payment_id', $paymentScope->select('id'));

        return MoneyMath::toApiNumber($query->sum('payment_allocations.amount'));
    }

    /**
     * @return array<string, float>
     */
    public function sumProjectFundingAllocationsByMethod(
        int $tenantId,
        string $startDate,
        string $endDate,
        ?DashboardBccFilter $bccFilter = null,
        ?DashboardProjectFilter $projectFilter = null
    ): array {
        $bccFilter ??= DashboardBccFilter::none();
        $projectFilter ??= DashboardProjectFilter::none();
        if (! $projectFilter->isActive) {
            return [];
        }

        $query = PaymentAllocation::query()
            ->where('payment_allocations.tenant_id', $tenantId)
            ->whereNull('payment_allocations.deleted_at')
            ->join('donation_payments', 'donation_payments.id', '=', 'payment_allocations.payment_id')
            ->where('donation_payments.tenant_id', $tenantId)
            ->where('donation_payments.status', 'succeeded')
            ->whereNull('donation_payments.deleted_at')
            ->whereDate('donation_payments.payment_date', '>=', $startDate)
            ->whereDate('donation_payments.payment_date', '<=', $endDate);
        $projectFilter->applyToPaymentAllocationQuery($query, $tenantId);
        $paymentScope = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereDate('payment_date', '>=', $startDate)
            ->whereDate('payment_date', '<=', $endDate);
        $bccFilter->applyToDonationPaymentQuery($paymentScope, $tenantId);
        $query->whereIn('payment_allocations.payment_id', $paymentScope->select('id'));

        return $query
            ->selectRaw('donation_payments.method as method, COALESCE(SUM(payment_allocations.amount), 0) as total')
            ->groupBy('donation_payments.method')
            ->pluck('total', 'method')
            ->map(fn ($sum) => MoneyMath::toApiNumber($sum))
            ->all();
    }

    public function countDistinctPaymentsWithProjectAllocationsInRange(
        int $tenantId,
        string $startDate,
        string $endDate,
        ?DashboardBccFilter $bccFilter = null,
        ?DashboardProjectFilter $projectFilter = null
    ): int {
        $bccFilter ??= DashboardBccFilter::none();
        $projectFilter ??= DashboardProjectFilter::none();
        if (! $projectFilter->isActive) {
            return 0;
        }

        $paymentScope = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereDate('payment_date', '>=', $startDate)
            ->whereDate('payment_date', '<=', $endDate);
        $bccFilter->applyToDonationPaymentQuery($paymentScope, $tenantId);
        $projectFilter->applyToDonationPaymentQuery($paymentScope, $tenantId);

        return (int) $paymentScope->count();
    }

    /**
     * @return Builder<DonationPayment>
     */
    public function succeededPaymentsInRangeScoped(
        int $tenantId,
        string $start,
        string $end,
        ?DashboardBccFilter $bccFilter = null,
        ?DashboardProjectFilter $projectFilter = null
    ): Builder {
        $bccFilter ??= DashboardBccFilter::none();
        $projectFilter ??= DashboardProjectFilter::none();
        $query = $this->succeededPaymentsInRange($tenantId, $start, $end);
        $bccFilter->applyToDonationPaymentQuery($query, $tenantId);
        $projectFilter->applyToDonationPaymentQuery($query, $tenantId);

        return $query;
    }

    /**
     * @return array<int, array{period: string, label: string, start: string, end: string, is_current: bool}>
     */
    public function trendMonthBuckets(int $tenantId, ?DashboardDateRange $range = null): array
    {
        $timezone = DonationBusinessDate::timezoneForTenant($tenantId);
        $today = DonationBusinessDate::today($tenantId);
        $currentKey = Carbon::parse($today, $timezone)->format('Y-m');

        if ($range !== null) {
            $cursor = Carbon::parse($range->dateFrom, $timezone)->startOfMonth();
            $last = Carbon::parse($range->collectionEnd, $timezone)->startOfMonth();
            $buckets = [];
            while ($cursor->lte($last)) {
                $key = $cursor->format('Y-m');
                $monthStart = $cursor->copy()->startOfMonth()->toDateString();
                $monthEnd = $cursor->copy()->endOfMonth()->toDateString();
                $start = $monthStart < $range->dateFrom ? $range->dateFrom : $monthStart;
                $end = $monthEnd > $range->collectionEnd ? $range->collectionEnd : $monthEnd;
                $isCurrent = $key === $currentKey;
                $buckets[] = [
                    'period' => $key,
                    'label' => $cursor->format('M Y'),
                    'start' => $start,
                    'end' => $end,
                    'is_current' => $isCurrent,
                ];
                $cursor->addMonth();
            }

            return $buckets;
        }

        $start = Carbon::parse($today, $timezone)->subMonths(11)->startOfMonth();
        $buckets = [];

        for ($i = 0; $i < 12; $i++) {
            $month = $start->copy()->addMonths($i);
            $key = $month->format('Y-m');
            $isCurrent = $key === $currentKey;
            $buckets[] = [
                'period' => $key,
                'label' => $month->format('M Y'),
                'start' => $isCurrent
                    ? DonationBusinessDate::monthStart($tenantId)
                    : $month->copy()->startOfMonth()->toDateString(),
                'end' => $isCurrent
                    ? $today
                    : $month->copy()->endOfMonth()->toDateString(),
                'is_current' => $isCurrent,
            ];
        }

        return $buckets;
    }

    public function sqlYearMonthExpression(string $column): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? "TO_CHAR({$column}, 'YYYY-MM')"
            : "strftime('%Y-%m', {$column})";
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function executiveProjectSummaries(
        int $tenantId,
        ?DashboardProjectFilter $projectFilter = null
    ): array {
        $projectFilter ??= DashboardProjectFilter::none();
        $query = DonationProject::forTenant($tenantId)->where('status', 'active');
        if ($projectFilter->isActive && $projectFilter->projectId !== null) {
            $query->where('id', $projectFilter->projectId);
        }

        return $query
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get()
            ->map(function (DonationProject $project): array {
                $target = max((float) $project->target_amount, 1);
                $raised = (float) $project->raised_amount;

                return [
                    'project_id' => $project->id,
                    'name' => $project->name,
                    'code' => $project->code,
                    'target_amount' => round($target, 2),
                    'collected' => round($raised, 2),
                    'remaining' => round(max(0, $target - $raised), 2),
                    'funding_percentage' => round(min(100, ($raised / $target) * 100), 1),
                ];
            })
            ->values()
            ->all();
    }

    public function averageProjectFundingPct(array $projectSummaries): float
    {
        if ($projectSummaries === []) {
            return 0.0;
        }

        return (float) (collect($projectSummaries)->avg('funding_percentage') ?? 0);
    }

    public function remainingCollectableRecordAmount(
        int $tenantId,
        ?string $businessDate = null,
        ?DashboardBccFilter $bccFilter = null,
        ?DashboardProjectFilter $projectFilter = null
    ): float {
        $bccFilter ??= DashboardBccFilter::none();
        $projectFilter ??= DashboardProjectFilter::none();
        $businessDate = $businessDate ?? DonationBusinessDate::today($tenantId);

        if ($projectFilter->isActive) {
            $query = ProjectInstallmentDue::forTenant($tenantId);
            $projectFilter->applyToProjectInstallmentDueQuery($query, $tenantId);
            $this->applyDashboardFamilyScope($query, $tenantId, $bccFilter, $projectFilter);

            return ContributionBalance::sumFlooredOutstanding(
                ContributionBalance::scopeProjectInstallmentRemainingCollectable($query, $businessDate)
            );
        }

        $query = ContributionDue::forTenant($tenantId);
        $bccFilter->applyToContributionDueQuery($query, $tenantId);

        return ContributionBalance::sumFlooredOutstanding(
            ContributionBalance::scopeRemainingCollectable(
                $query,
                $businessDate
            )
        );
    }

    /**
     * Residual formula (diagnostic only): max(0, pending - overdue).
     */
    public function remainingCollectableResidual(float $pendingDues, float $overdueAmount): float
    {
        return max(0.0, $pendingDues - $overdueAmount);
    }

    /**
     * @return array<string, float>
     */
    /**
     * @return array<string, float>
     */
    public function healthScoreFactorDisplay(
        float $participationRate,
        float $overdueRatioPct,
        float $projectMomentumPct,
        float $growthHealthScore,
        bool $projectApplicable = true
    ): array {
        $factors = [
            'family_engagement' => min(100, max(0, $participationRate)),
            'overdue_health' => min(100, max(0, 100 - $overdueRatioPct)),
            'growth_health' => min(100, max(0, $growthHealthScore)),
        ];

        if ($projectApplicable) {
            $factors['project_funding'] = min(100, max(0, $projectMomentumPct));
        }

        return $factors;
    }

    /**
     * @return array<int, array{key: string, weight_pct: float}>
     */
    public function stewardshipHealthWeightBreakdown(bool $projectApplicable): array
    {
        if ($projectApplicable) {
            return [
                ['key' => 'family_engagement', 'weight_pct' => 35.0],
                ['key' => 'overdue_health', 'weight_pct' => 30.0],
                ['key' => 'project_funding', 'weight_pct' => 20.0],
                ['key' => 'growth_health', 'weight_pct' => 15.0],
            ];
        }

        return [
            ['key' => 'family_engagement', 'weight_pct' => 43.75],
            ['key' => 'overdue_health', 'weight_pct' => 37.5],
            ['key' => 'growth_health', 'weight_pct' => 18.75],
        ];
    }

    public function growthPct(float $currentMonth, float $previousMonth): float
    {
        if ($previousMonth <= 0) {
            return 0.0;
        }

        return round((($currentMonth - $previousMonth) / $previousMonth) * 100, 1);
    }

    /**
     * @return array{
     *     current_collected: float,
     *     previous_collected: float,
     *     growth_pct: float,
     *     comparison_available: bool,
     *     tiny_base: bool,
     *     growth_health_score: float
     * }
     */
    public function collectionGrowthAnalysis(
        int $tenantId,
        ?DashboardDateRange $range = null,
        ?DashboardBccFilter $bccFilter = null,
        ?DashboardProjectFilter $projectFilter = null
    ): array {
        if ($range !== null) {
            $current = $this->sumSucceededPayments($tenantId, $range->dateFrom, $range->collectionEnd, $bccFilter, $projectFilter);
            $previous = $this->sumSucceededPayments($tenantId, $range->comparisonStart, $range->comparisonEnd, $bccFilter, $projectFilter);

            return $this->growthHealthFromCollections($current, $previous);
        }

        $currentRange = $this->currentMonthCollectionRange($tenantId);
        $previousRange = $this->comparablePreviousMonthCollectionRange($tenantId);
        $current = $this->sumSucceededPayments($tenantId, $currentRange['start'], $currentRange['end'], $bccFilter, $projectFilter);
        $previous = $this->sumSucceededPayments($tenantId, $previousRange['start'], $previousRange['end'], $bccFilter, $projectFilter);

        return $this->growthHealthFromCollections($current, $previous);
    }

    /**
     * @param  array<int>  $tenantIds
     * @return array{
     *     current_collected: float,
     *     previous_collected: float,
     *     growth_pct: float,
     *     comparison_available: bool,
     *     tiny_base: bool,
     *     growth_health_score: float
     * }
     */
    public function consolidatedCollectionGrowthAnalysis(array $tenantIds): array
    {
        $current = 0.0;
        $previous = 0.0;

        foreach ($tenantIds as $tenantId) {
            $tenantId = (int) $tenantId;
            $currentRange = $this->currentMonthCollectionRange($tenantId);
            $previousRange = $this->comparablePreviousMonthCollectionRange($tenantId);
            $current += $this->sumSucceededPayments($tenantId, $currentRange['start'], $currentRange['end']);
            $previous += $this->sumSucceededPayments($tenantId, $previousRange['start'], $previousRange['end']);
        }

        return $this->growthHealthFromCollections($current, $previous);
    }

    /**
     * @return array{
     *     current_collected: float,
     *     previous_collected: float,
     *     growth_pct: float,
     *     comparison_available: bool,
     *     tiny_base: bool,
     *     growth_health_score: float
     * }
     */
    public function growthHealthFromCollections(float $currentCollected, float $previousCollected): array
    {
        $comparisonAvailable = $previousCollected > 0;
        $growthPct = $comparisonAvailable
            ? $this->growthPct($currentCollected, $previousCollected)
            : 0.0;
        $tinyBase = $comparisonAvailable
            && $currentCollected > 0
            && $previousCollected < max(1.0, $currentCollected * self::GROWTH_PRIOR_MIN_SHARE_OF_CURRENT);
        $growthHealthScore = ($comparisonAvailable && ! $tinyBase)
            ? min(100.0, max(0.0, 50 + ($growthPct / 2)))
            : 50.0;

        return [
            'current_collected' => MoneyMath::toApiNumber($currentCollected),
            'previous_collected' => MoneyMath::toApiNumber($previousCollected),
            'growth_pct' => $growthPct,
            'comparison_available' => $comparisonAvailable,
            'tiny_base' => $tinyBase,
            'growth_health_score' => $growthHealthScore,
        ];
    }

    public function sumPendingDuesCollectable(
        int $tenantId,
        ?string $businessDate = null,
        ?DashboardBccFilter $bccFilter = null,
        ?DashboardProjectFilter $projectFilter = null
    ): float {
        $bccFilter ??= DashboardBccFilter::none();
        $projectFilter ??= DashboardProjectFilter::none();
        $businessDate = $businessDate ?? DonationBusinessDate::today($tenantId);

        if ($projectFilter->isActive) {
            $query = ProjectInstallmentDue::forTenant($tenantId);
            $projectFilter->applyToProjectInstallmentDueQuery($query, $tenantId);
            $this->applyDashboardFamilyScope($query, $tenantId, $bccFilter, $projectFilter);
            $query->whereDate('due_date', '<=', $businessDate);

            return MoneyMath::toApiNumber(
                ContributionBalance::sumOutstanding($query)
            );
        }

        $query = ContributionDue::forTenant($tenantId);
        $bccFilter->applyToContributionDueQuery($query, $tenantId);

        return MoneyMath::toApiNumber(
            ContributionBalance::sumCollectable(
                $query,
                $businessDate
            )
        );
    }

    /**
     * @return array{count: int, total_overdue_amount: float}
     */
    public function overdueAttentionTotals(
        int $tenantId,
        ?string $businessDate = null,
        ?DashboardBccFilter $bccFilter = null,
        ?DashboardProjectFilter $projectFilter = null
    ): array {
        $bccFilter ??= DashboardBccFilter::none();
        $projectFilter ??= DashboardProjectFilter::none();
        $businessDate = $businessDate ?? DonationBusinessDate::today($tenantId);

        if ($projectFilter->isActive) {
            $overdueQuery = ProjectInstallmentDue::forTenant($tenantId);
            $projectFilter->applyToProjectInstallmentDueQuery($overdueQuery, $tenantId);
            $this->applyDashboardFamilyScope($overdueQuery, $tenantId, $bccFilter, $projectFilter);
            ContributionBalance::scopeOverdue($overdueQuery, $businessDate);
        } else {
            $overdueQuery = ContributionDue::forTenant($tenantId);
            $bccFilter->applyToContributionDueQuery($overdueQuery, $tenantId);
            ContributionBalance::scopeOverdue($overdueQuery, $businessDate);
        }

        $count = (int) (clone $overdueQuery)
            ->selectRaw('COUNT(DISTINCT family_id) as aggregate')
            ->value('aggregate');

        return [
            'count' => $count,
            'total_overdue_amount' => MoneyMath::toApiNumber(
                ContributionBalance::sumFlooredOutstanding(clone $overdueQuery)
            ),
        ];
    }

    public function participationRatePct(int $tenantId): float
    {
        $activeFamilies = $this->countActiveFamilies($tenantId);
        if ($activeFamilies <= 0) {
            return 0.0;
        }

        $participatingFamilies = $this->countDistinctParticipatingFamilies($tenantId);

        return min(100.0, round(($participatingFamilies / $activeFamilies) * 100, 1));
    }

    public function collectionGrowthPct(int $tenantId): float
    {
        return (float) $this->collectionGrowthAnalysis($tenantId)['growth_pct'];
    }

    public function comparablePreviousMonthCollected(
        int $tenantId,
        ?DashboardDateRange $range = null,
        ?DashboardBccFilter $bccFilter = null,
        ?DashboardProjectFilter $projectFilter = null
    ): float {
        if ($range !== null) {
            return $this->sumSucceededPayments($tenantId, $range->comparisonStart, $range->comparisonEnd, $bccFilter, $projectFilter);
        }

        $window = $this->comparablePreviousMonthCollectionRange($tenantId);

        return $this->sumSucceededPayments($tenantId, $window['start'], $window['end'], $bccFilter, $projectFilter);
    }

    public function sumDueAllocatedCollected(
        int $tenantId,
        string $startDate,
        string $endDate,
        ?DashboardBccFilter $bccFilter = null
    ): float {
        $bccFilter ??= DashboardBccFilter::none();
        $sum = PaymentAllocation::query()
            ->where('tenant_id', $tenantId)
            ->where('allocatable_type', 'due')
            ->whereHas('payment', function (Builder $query) use ($tenantId, $startDate, $endDate, $bccFilter): void {
                $query->where('tenant_id', $tenantId)
                    ->where('status', 'succeeded')
                    ->whereDate('payment_date', '>=', $startDate)
                    ->whereDate('payment_date', '<=', $endDate);
                $bccFilter->applyToDonationPaymentQuery($query, $tenantId);
            })
            ->sum('amount');

        return MoneyMath::toApiNumber($sum);
    }

    public function overdueRatioPct(float $pendingDues, float $overdueAmount): float
    {
        if ($pendingDues <= 0) {
            return 0.0;
        }

        return min(100.0, round(($overdueAmount / $pendingDues) * 100, 1));
    }

    public function planCompliancePct(int $tenantId): float
    {
        $monthStart = DonationBusinessDate::monthStart($tenantId);
        $monthEnd = DonationBusinessDate::monthEnd($tenantId);

        $assessed = (float) ContributionDue::forTenant($tenantId)
            ->whereBetween('due_date', [$monthStart, $monthEnd])
            ->sum('amount_due');

        if ($assessed <= 0) {
            return 100.0;
        }

        $collected = (float) DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereBetween('payment_date', [$monthStart, $monthEnd])
            ->sum('amount');

        return round(min(100, ($collected / $assessed) * 100), 1);
    }

    public function collectionPerformanceScore(int $tenantId): float
    {
        $currentRange = $this->currentMonthCollectionRange($tenantId);
        $previousRange = $this->previousMonthCollectionRange($tenantId);
        $currentMonth = $this->sumSucceededPayments($tenantId, $currentRange['start'], $currentRange['end']);
        $previousMonth = $this->sumSucceededPayments($tenantId, $previousRange['start'], $previousRange['end']);
        $planCompliance = $this->planCompliancePct($tenantId);

        $growthScore = $previousMonth > 0
            ? min(100, max(0, 50 + ((($currentMonth - $previousMonth) / $previousMonth) * 50)))
            : ($currentMonth > 0 ? 85.0 : 40.0);

        return round(($growthScore * 0.6) + ($planCompliance * 0.4), 1);
    }

    /**
     * Inputs for financial health drill-down KPIs (matches dashboard stewardship math).
     *
     * @return array{
     *     participation_rate: float,
     *     collection_growth_pct: float,
     *     overdue_ratio_pct: float,
     *     project_momentum_pct: float,
     *     pending_dues: float,
     *     overdue_amount: float,
     *     overdue_family_count: int,
     *     factors: array<string, float>,
     *     collection_performance_score: float
     * }
     */
    public function executiveHealthDrillContext(int $tenantId): array
    {
        $pendingDues = $this->sumPendingDuesCollectable($tenantId);
        $overdue = $this->overdueAttentionTotals($tenantId);
        $overdueAmount = (float) $overdue['total_overdue_amount'];
        $participationRate = $this->participationRatePct($tenantId);
        $growthAnalysis = $this->collectionGrowthAnalysis($tenantId);
        $growthPct = (float) $growthAnalysis['growth_pct'];
        $projects = $this->executiveProjectSummaries($tenantId);
        $projectApplicable = $projects !== [];
        $projectMomentumPct = $this->averageProjectFundingPct($projects);
        $overdueRatioPct = $this->overdueRatioPct($pendingDues, $overdueAmount);
        $growthHealthScore = (float) $growthAnalysis['growth_health_score'];

        return [
            'participation_rate' => $participationRate,
            'collection_growth_pct' => $growthPct,
            'collection_growth_analysis' => $growthAnalysis,
            'overdue_ratio_pct' => $overdueRatioPct,
            'project_momentum_pct' => $projectMomentumPct,
            'project_applicable' => $projectApplicable,
            'growth_health_score' => $growthHealthScore,
            'pending_dues' => $pendingDues,
            'overdue_amount' => $overdueAmount,
            'overdue_family_count' => (int) $overdue['count'],
            'current_month_collected' => (float) ($growthAnalysis['current_collected'] ?? 0),
            'factors' => $this->healthScoreFactorDisplay(
                $participationRate,
                $overdueRatioPct,
                $projectMomentumPct,
                $growthHealthScore,
                $projectApplicable
            ),
            'collection_performance_score' => $this->collectionPerformanceScore($tenantId),
        ];
    }

    /**
     * @return Builder<DonationPayment>
     */
    public function succeededPaymentsInRange(int $tenantId, string $start, string $end): Builder
    {
        return DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereDate('payment_date', '>=', $start)
            ->whereDate('payment_date', '<=', $end);
    }

    public function countDistinctParticipatingFamilies(
        int $tenantId,
        ?DashboardDateRange $range = null,
        ?DashboardBccFilter $bccFilter = null,
        ?DashboardProjectFilter $projectFilter = null
    ): int {
        $bccFilter ??= DashboardBccFilter::none();
        $projectFilter ??= DashboardProjectFilter::none();
        $window = $this->participationWindow($tenantId, $range);
        $activeFamilyIds = Family::query()
            ->select('id')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active');
        $bccFilter->applyToFamilyQuery($activeFamilyIds, $tenantId);
        $projectFilter->applyToFamilyQuery($activeFamilyIds, $tenantId);

        if ($projectFilter->isActive) {
            $paymentScope = DonationPayment::forTenant($tenantId)
                ->where('status', 'succeeded')
                ->whereNotNull('family_id')
                ->whereIn('family_id', $activeFamilyIds)
                ->whereDate('payment_date', '>=', $window['start'])
                ->whereDate('payment_date', '<=', $window['end']);
            $bccFilter->applyToDonationPaymentQuery($paymentScope, $tenantId);
            $projectFilter->applyToDonationPaymentQuery($paymentScope, $tenantId);

            $allocationQuery = PaymentAllocation::query()
                ->where('payment_allocations.tenant_id', $tenantId)
                ->whereNull('payment_allocations.deleted_at')
                ->whereIn('payment_allocations.payment_id', $paymentScope->select('id'));
            $projectFilter->applyToPaymentAllocationQuery($allocationQuery, $tenantId);

            return (int) $allocationQuery
                ->join('donation_payments', 'donation_payments.id', '=', 'payment_allocations.payment_id')
                ->selectRaw('COUNT(DISTINCT donation_payments.family_id) as aggregate')
                ->value('aggregate');
        }

        $query = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereNotNull('family_id')
            ->whereIn('family_id', $activeFamilyIds)
            ->whereDate('payment_date', '>=', $window['start'])
            ->whereDate('payment_date', '<=', $window['end']);
        $bccFilter->applyToDonationPaymentQuery($query, $tenantId);

        return (int) $query->selectRaw('COUNT(DISTINCT family_id) as aggregate')->value('aggregate');
    }

    public function countActiveFamilies(
        int $tenantId,
        ?DashboardBccFilter $bccFilter = null,
        ?DashboardProjectFilter $projectFilter = null
    ): int {
        $bccFilter ??= DashboardBccFilter::none();
        $projectFilter ??= DashboardProjectFilter::none();
        $query = Family::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active');
        $bccFilter->applyToFamilyQuery($query, $tenantId);
        $projectFilter->applyToFamilyQuery($query, $tenantId);

        return (int) $query->count();
    }

    /**
     * @return array{start: string, end: string}|null
     */
    public function trendBucketRange(int $tenantId, string $periodYm, ?DashboardDateRange $range = null): ?array
    {
        foreach ($this->trendMonthBuckets($tenantId, $range) as $bucket) {
            if ($bucket['period'] === $periodYm) {
                return ['start' => $bucket['start'], 'end' => $bucket['end']];
            }
        }

        return null;
    }

    /**
     * @return array{start: string, end: string}
     */
    public function collectionsSliceRange(int $tenantId, string $sliceId, ?DashboardDateRange $range = null): array
    {
        if ($range !== null) {
            return match ($sliceId) {
                'current_month' => ['start' => $range->dateFrom, 'end' => $range->collectionEnd],
                'previous_month' => ['start' => $range->comparisonStart, 'end' => $range->comparisonEnd],
                default => throw new \InvalidArgumentException('Invalid collections slice.'),
            };
        }

        return match ($sliceId) {
            'current_month' => $this->currentMonthCollectionRange($tenantId),
            'previous_month' => $this->comparablePreviousMonthCollectionRange($tenantId),
            default => throw new \InvalidArgumentException('Invalid collections slice.'),
        };
    }

    /**
     * Disjoint due-date partitions for leadership due-schedule chart.
     *
     * @return array{
     *     overdue_amount: float,
     *     overdue_family_count: int,
     *     next_14_days_amount: float,
     *     next_14_days_family_count: int,
     *     later_remaining_amount: float,
     *     later_remaining_family_count: int,
     *     pending_dues: float
     * }
     */
    public function dueSchedulePartition(
        int $tenantId,
        ?DashboardDateRange $range = null,
        ?DashboardBccFilter $bccFilter = null,
        ?DashboardProjectFilter $projectFilter = null
    ): array {
        $bccFilter ??= DashboardBccFilter::none();
        $projectFilter ??= DashboardProjectFilter::none();
        $parishToday = DonationBusinessDate::today($tenantId);
        $asOf = $range?->asOf ?? $parishToday;
        $overdue = $this->overdueAttentionTotals($tenantId, $asOf, $bccFilter, $projectFilter);
        $pendingDues = $this->sumPendingDuesCollectable($tenantId, $asOf, $bccFilter, $projectFilter);

        if ($projectFilter->isActive) {
            $next14Query = ProjectInstallmentDue::forTenant($tenantId);
            $projectFilter->applyToProjectInstallmentDueQuery($next14Query, $tenantId);
            $this->applyDashboardFamilyScope($next14Query, $tenantId, $bccFilter, $projectFilter);
            ContributionBalance::scopeProjectInstallmentDueNextDays($next14Query, $parishToday, 14);
            $laterQuery = ProjectInstallmentDue::forTenant($tenantId);
            $projectFilter->applyToProjectInstallmentDueQuery($laterQuery, $tenantId);
            $this->applyDashboardFamilyScope($laterQuery, $tenantId, $bccFilter, $projectFilter);
            ContributionBalance::scopeProjectInstallmentDueAfterDays($laterQuery, $parishToday, 14);
        } else {
            $next14Query = ContributionDue::forTenant($tenantId);
            $bccFilter->applyToContributionDueQuery($next14Query, $tenantId);
            ContributionBalance::scopeDueNextDays($next14Query, $parishToday, 14);
            $laterQuery = ContributionDue::forTenant($tenantId);
            $bccFilter->applyToContributionDueQuery($laterQuery, $tenantId);
            ContributionBalance::scopeDueAfterDays($laterQuery, $parishToday, 14);
        }

        return [
            'overdue_amount' => (float) $overdue['total_overdue_amount'],
            'overdue_family_count' => (int) $overdue['count'],
            'next_14_days_amount' => ContributionBalance::sumFlooredOutstanding(clone $next14Query),
            'next_14_days_family_count' => $this->countDistinctFamiliesInDueQuery(clone $next14Query),
            'later_remaining_amount' => ContributionBalance::sumFlooredOutstanding(clone $laterQuery),
            'later_remaining_family_count' => $this->countDistinctFamiliesInDueQuery(clone $laterQuery),
            'pending_dues' => $pendingDues,
        ];
    }

    /**
     * Expected dues through business today vs due-allocated MTD collections.
     *
     * @return array{
     *     expected: float,
     *     collected: float,
     *     outstanding: float,
     *     collection_rate_pct: float|null,
     *     has_assessment: bool,
     *     collected_label: string
     * }
     */
    public function collectionSnapshot(
        int $tenantId,
        float $outstanding,
        ?DashboardDateRange $range = null,
        ?DashboardBccFilter $bccFilter = null,
        ?DashboardProjectFilter $projectFilter = null
    ): array {
        $bccFilter ??= DashboardBccFilter::none();
        $projectFilter ??= DashboardProjectFilter::none();
        $monthStart = $range?->dateFrom ?? DonationBusinessDate::monthStart($tenantId);
        $businessDate = $range?->collectionEnd ?? DonationBusinessDate::today($tenantId);

        if ($projectFilter->isActive) {
            $expectedQuery = ProjectInstallmentDue::forTenant($tenantId)
                ->whereBetween('due_date', [$monthStart, $businessDate]);
            $projectFilter->applyToProjectInstallmentDueQuery($expectedQuery, $tenantId);
            $this->applyDashboardFamilyScope($expectedQuery, $tenantId, $bccFilter, $projectFilter);
            $expected = (float) $expectedQuery->sum('amount_due');
            $collected = $this->sumProjectFundingAllocations($tenantId, $monthStart, $businessDate, $bccFilter, $projectFilter);
            $collectedLabel = 'project_funding';
        } else {
            $expectedQuery = ContributionDue::forTenant($tenantId)
                ->whereBetween('due_date', [$monthStart, $businessDate]);
            $bccFilter->applyToContributionDueQuery($expectedQuery, $tenantId);
            $expected = (float) $expectedQuery->sum('amount_due');
            $collected = $this->sumDueAllocatedCollected($tenantId, $monthStart, $businessDate, $bccFilter);
            $collectedLabel = 'due_allocated';
        }

        $rate = $expected > 0
            ? round(min(100.0, ($collected / $expected) * 100), 1)
            : null;

        return [
            'expected' => MoneyMath::toApiNumber($expected),
            'collected' => $collected,
            'outstanding' => MoneyMath::toApiNumber($outstanding),
            'collection_rate_pct' => $rate,
            'has_assessment' => $expected > 0,
            'collected_label' => $collectedLabel,
        ];
    }

    /**
     * @param  Builder<ContributionDue>|Builder<ProjectInstallmentDue>  $query
     */
    public function applyDashboardFamilyScope(
        Builder $query,
        int $tenantId,
        DashboardBccFilter $bccFilter,
        DashboardProjectFilter $projectFilter
    ): void {
        if (! $bccFilter->isActive && ! $projectFilter->isActive) {
            return;
        }

        $families = Family::query()
            ->select('id')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active');
        $bccFilter->applyToFamilyQuery($families, $tenantId);
        $projectFilter->applyToFamilyQuery($families, $tenantId);
        $query->whereIn('family_id', $families);
    }

    private function countDistinctFamiliesInDueQuery(Builder $query): int
    {
        return (int) (clone $query)
            ->whereIn('status', ['pending', 'partially_paid'])
            ->selectRaw('COUNT(DISTINCT family_id) as aggregate')
            ->value('aggregate');
    }
}
