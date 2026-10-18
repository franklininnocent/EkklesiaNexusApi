<?php

namespace Modules\Donations\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Donations\Support\ContributionBalance;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Support\MoneyMath;
use Modules\Family\Models\Family;
use Modules\Tenants\Services\ChurchCurrencyResolver;

class FamilyCollectDuesService
{
    public function __construct(
        private readonly DonationProjectService $projectService,
        private readonly FamilyFinancialProfileService $profileService,
    ) {}

    /**
     * @param  array{project_id?: string|null, page?: int, per_page?: int, scope?: string, bulk_select?: bool}  $query
     * @return array<string, mixed>
     */
    public function build(int $tenantId, string $familyId, array $query = []): array
    {
        $this->assertFamilyBelongsToTenant($tenantId, $familyId);

        $asOfDate = DonationBusinessDate::today($tenantId);
        $profile = $this->profileService->build($tenantId, $familyId);
        $projectSummary = $profile['project_contributions'] ?? ['projects' => []];

        $projects = [];
        foreach ($projectSummary['projects'] ?? [] as $project) {
            $projects[] = [
                'project_id' => $project['project_id'],
                'project_name' => $project['project_name'],
                'project_code' => $project['project_code'] ?? null,
                'fund_id' => $this->resolveProjectFundId($tenantId, (string) $project['project_id']),
                'target_amount' => $project['target_amount'],
                'amount_collected' => $project['amount_collected'],
                'target_gap' => $project['outstanding_amount'],
                'installment_outstanding' => $project['installment_outstanding'],
                'installment_status' => $project['installment_status'] ?? null,
                'status' => $project['status'] ?? null,
            ];
        }

        $currency = app(ChurchCurrencyResolver::class)->currencyCodeForTenantId($tenantId);

        $payload = [
            'family_id' => $familyId,
            'currency_code' => $currency,
            'as_of_date' => $asOfDate,
            'summary' => [
                'collectible_due' => $profile['totals']['pending_due'] ?? 0,
                'overdue_amount' => $profile['totals']['overdue_amount'] ?? 0,
                'pending_mandatory_due' => $profile['totals']['pending_mandatory_due'] ?? 0,
                'pending_project_due' => $profile['totals']['pending_project_due'] ?? 0,
                'total_paid' => $profile['totals']['total_paid'] ?? 0,
            ],
            'projects' => $projects,
        ];

        $projectId = isset($query['project_id']) ? trim((string) $query['project_id']) : '';
        if ($projectId !== '') {
            $payload['installments'] = $this->paginateInstallments(
                $tenantId,
                $familyId,
                $projectId,
                $asOfDate,
                $query
            );

            if (! empty($query['bulk_select'])) {
                $scope = $this->normalizeScope($query['scope'] ?? 'all');
                $payload['bulk_select'] = $this->bulkSelectMetadata(
                    $tenantId,
                    $familyId,
                    $projectId,
                    $asOfDate,
                    $scope
                );
            }
        }

        return $payload;
    }

    /**
     * @param  array{page?: int, per_page?: int, scope?: string}  $query
     * @return array<string, mixed>
     */
    private function paginateInstallments(
        int $tenantId,
        string $familyId,
        string $projectId,
        string $businessDate,
        array $query
    ): array {
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 25)));
        $scope = $this->normalizeScope($query['scope'] ?? 'all');

        $base = ProjectInstallmentDue::forTenant($tenantId)
            ->where('family_id', $familyId)
            ->where('project_id', $projectId)
            ->whereIn('status', ['pending', 'partially_paid']);

        $scoped = match ($scope) {
            'overdue' => ContributionBalance::scopeOverdue($base, $businessDate),
            'outstanding' => $base,
            default => $base,
        };

        /** @var LengthAwarePaginator $paginator */
        $paginator = $scoped
            ->orderBy('due_date')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', (int) ($query['page'] ?? 1));

        $rows = collect($paginator->items())->map(function (ProjectInstallmentDue $due) use ($businessDate): array {
            $dueDate = $due->due_date?->toDateString();
            $remaining = ContributionBalance::outstandingForDue($due);

            return [
                'id' => $due->id,
                'project_id' => $due->project_id,
                'installment_label' => $due->installment_label,
                'due_date' => $dueDate,
                'amount_due' => (float) $due->amount_due,
                'amount_paid' => (float) $due->amount_paid,
                'remaining' => $remaining,
                'outstanding_amount' => $remaining,
                'status' => $due->status,
                'is_overdue' => $dueDate && $dueDate < $businessDate,
            ];
        })->values()->all();

        return [
            'data' => $rows,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'scope' => $scope,
            ],
        ];
    }

    /**
     * @return array{scope: string, project_id: string, installment_ids: array<int, string>, total_remaining: float, count: int}
     */
    private function bulkSelectMetadata(
        int $tenantId,
        string $familyId,
        string $projectId,
        string $businessDate,
        string $scope
    ): array {
        $base = ProjectInstallmentDue::forTenant($tenantId)
            ->where('family_id', $familyId)
            ->where('project_id', $projectId)
            ->whereIn('status', ['pending', 'partially_paid']);

        $scoped = match ($scope) {
            'overdue' => ContributionBalance::scopeOverdue($base, $businessDate),
            'outstanding' => $base,
            default => $base,
        };

        $rows = $scoped->orderBy('due_date')->orderBy('id')->get(['id', 'amount_due', 'amount_paid']);
        $totalRemaining = '0.00';
        $ids = [];
        foreach ($rows as $row) {
            $remaining = ContributionBalance::outstandingString($row);
            if (! MoneyMath::isPositive($remaining)) {
                continue;
            }
            $ids[] = (string) $row->id;
            $totalRemaining = MoneyMath::add($totalRemaining, $remaining);
        }

        return [
            'scope' => $scope,
            'project_id' => $projectId,
            'installment_ids' => $ids,
            'total_remaining' => (float) $totalRemaining,
            'count' => count($ids),
        ];
    }

    private function normalizeScope(string $scope): string
    {
        return in_array($scope, ['all', 'outstanding', 'overdue'], true) ? $scope : 'all';
    }

    private function resolveProjectFundId(int $tenantId, string $projectId): ?string
    {
        $project = DonationProject::forTenant($tenantId)->find($projectId);

        return $project?->fund_id ? (string) $project->fund_id : null;
    }

    private function assertFamilyBelongsToTenant(int $tenantId, string $familyId): void
    {
        $family = Family::query()->find($familyId);

        if (! $family || (int) $family->tenant_id !== (int) $tenantId) {
            throw new \RuntimeException('Family does not belong to the tenant.');
        }
    }
}
