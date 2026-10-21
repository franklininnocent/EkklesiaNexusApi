<?php

namespace Modules\Donations\Services\Reports;

use Illuminate\Support\Facades\DB;
use Modules\Donations\Support\MoneyMath;
use Modules\Donations\Support\Reports\DonationReportCatalog;
use Modules\Donations\Support\Reports\ReportFilter;
use Modules\Donations\Support\Reports\ReportTableSort;
use Modules\Donations\Support\Reports\SafeCsvWriter;

final class DuesByPlanReportBuilder extends AbstractDonationReportBuilder
{
    public function reportType(): string
    {
        return DonationReportCatalog::TYPE_DUES_BY_PLAN;
    }

    public function preview(int $tenantId, ReportFilter $filter): array
    {
        $rows = ReportTableSort::sortArrayRows(
            $this->aggregateRows($tenantId, $filter),
            $filter,
            ['plan_name', 'period_label', 'amount_due', 'amount_paid', 'outstanding'],
        );
        $total = count($rows);
        $page = $filter->page();
        $perPage = $filter->perPage();
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);
        $money = $this->sumMoneyColumns($rows, ['amount_due', 'amount_paid', 'outstanding']);
        $waived = 0;
        foreach ($rows as $row) {
            $waived += (int) ($row['waived_count'] ?? 0);
        }

        return [
            'columns' => $this->columns(),
            'rows' => $slice,
            'totals' => [
                'period_count' => $total,
                'amount_due' => $money['amount_due'],
                'amount_paid' => $money['amount_paid'],
                'outstanding' => $money['outstanding'],
                'waived_count' => $waived,
            ],
            'footer' => [
                'plan_name' => $total.' periods',
                'amount_due' => $money['amount_due'],
                'amount_paid' => $money['amount_paid'],
                'outstanding' => $money['outstanding'],
            ],
            'meta' => $this->meta('fiscal_year', 'balance'),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ];
    }

    public function csvHeaders(): array
    {
        return ['plan_name', 'period_label', 'amount_due', 'amount_paid', 'outstanding', 'waived_count', 'cancelled_count'];
    }

    public function streamCsv(int $tenantId, ReportFilter $filter, $handle): int
    {
        $count = 0;
        SafeCsvWriter::putRow($handle, $this->csvHeaders());
        foreach ($this->aggregateRows($tenantId, $filter) as $row) {
            SafeCsvWriter::putRow($handle, $this->mapRowToCsv($row));
            $count++;
        }

        $this->writeCsvSummary($handle, $this->preview($tenantId, $filter));

        return $count;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function aggregateRows(int $tenantId, ReportFilter $filter): array
    {
        $query = DB::table('contribution_dues as d')
            ->join('contribution_plans as p', 'p.id', '=', 'd.plan_id')
            ->where('d.tenant_id', $tenantId)
            ->whereNull('d.deleted_at')
            ->selectRaw(
                'p.name as plan_name, d.period_label, SUM(d.amount_due) as amount_due, SUM(d.amount_paid) as amount_paid, '
                .'SUM(CASE WHEN d.status IN (\'pending\', \'partially_paid\') THEN GREATEST(d.amount_due - d.amount_paid, 0) ELSE 0 END) as open_outstanding, '
                .'SUM(CASE WHEN d.status = ? THEN 1 ELSE 0 END) as waived_count, '
                .'SUM(CASE WHEN d.status = ? THEN 1 ELSE 0 END) as cancelled_count',
                ['waived', 'cancelled']
            )
            ->groupBy('p.name', 'd.period_label')
            ->orderBy('d.period_label');

        $period = $this->constrainedPeriod($tenantId, $filter);
        if ($period !== null) {
            $query->whereDate('d.due_date', '>=', $period['start'])
                ->whereDate('d.due_date', '<=', $period['end']);
        }

        $bcc = $this->resolveBcc($tenantId, $filter);
        if ($bcc->isActive) {
            $query->join('families as f', 'f.id', '=', 'd.family_id');
            if ($bcc->unassignedOnly) {
                $query->whereNull('f.bcc_id');
            } elseif ($bcc->bccId !== null) {
                $query->where('f.bcc_id', $bcc->bccId);
            }
        }

        $planId = $filter->get('plan_id');
        if (is_string($planId) && $planId !== '') {
            $query->where('d.plan_id', $planId);
        }

        return collect($query->get())->map(function ($row) {
            $due = (string) $row->amount_due;
            $paid = (string) $row->amount_paid;

            return [
                'plan_name' => $row->plan_name,
                'period_label' => $row->period_label,
                'amount_due' => MoneyMath::toApiNumber($due),
                'amount_paid' => MoneyMath::toApiNumber($paid),
                'outstanding' => MoneyMath::toApiNumber($row->open_outstanding ?? 0),
                'waived_count' => (int) $row->waived_count,
                'cancelled_count' => (int) ($row->cancelled_count ?? 0),
            ];
        })->all();
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function columns(): array
    {
        return [
            ['key' => 'plan_name', 'label' => 'Plan'],
            ['key' => 'period_label', 'label' => 'Period'],
            ['key' => 'amount_due', 'label' => 'Due'],
            ['key' => 'amount_paid', 'label' => 'Collected'],
            ['key' => 'outstanding', 'label' => 'Outstanding'],
            ['key' => 'waived_count', 'label' => 'Waived'],
            ['key' => 'cancelled_count', 'label' => 'Cancelled'],
        ];
    }

    protected function mapRowToCsv(array $row): array
    {
        return [
            $row['plan_name'],
            $row['period_label'],
            $row['amount_due'],
            $row['amount_paid'],
            $row['outstanding'],
            $row['waived_count'],
            $row['cancelled_count'],
        ];
    }
}
