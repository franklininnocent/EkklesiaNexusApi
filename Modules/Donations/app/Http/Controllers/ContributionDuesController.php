<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Http\Requests\BulkUpdateContributionDuesRequest;
use Modules\Donations\Http\Requests\IndexContributionDuesRequest;
use Modules\Donations\Http\Requests\StoreDueRequest;
use Modules\Donations\Http\Requests\UpdateDueStatusRequest;
use Modules\Donations\Jobs\SendContributionReminderJob;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\ContributionPlan;
use Modules\Donations\Services\ContributionDueService;
use Modules\Donations\Services\DonationAuditService;
use Modules\Donations\Support\ContributionBalance;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Family\Models\Family;
use Modules\Tenants\Support\TenantContext;

class ContributionDuesController extends Controller
{
    public function __construct(
        private readonly DonationAuditService $auditService,
        private readonly ContributionDueService $dueService
    ) {}

    public function index(IndexContributionDuesRequest $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $table = (new ContributionDue)->getTable();

        $query = ContributionDue::forTenant($tenantId)
            ->select("{$table}.*")
            ->with(['family', 'plan']);

        if ($request->filled('status')) {
            $query->where("{$table}.status", $request->string('status'));
        }
        if ($request->filled('family_id')) {
            $query->where("{$table}.family_id", $request->string('family_id'));
        }
        if ($request->filled('plan_id')) {
            $query->where("{$table}.plan_id", $request->string('plan_id'));
        }
        $businessDate = DonationBusinessDate::today($tenantId);
        $overdueOnly = $request->boolean('overdue_only');
        $remainingOnly = $request->boolean('remaining_only');
        $actionable = $request->boolean('actionable');
        $dueSchedule = $request->filled('due_schedule')
            ? $request->string('due_schedule')->toString()
            : null;

        if ($dueSchedule !== null) {
            switch ($dueSchedule) {
                case 'overdue':
                    ContributionBalance::scopeOverdue($query, $businessDate);
                    break;
                case 'next_14_days':
                    ContributionBalance::scopeDueNextDays($query, $businessDate, 14);
                    break;
                case 'later':
                    ContributionBalance::scopeDueAfterDays($query, $businessDate, 14);
                    break;
            }
        } elseif ($overdueOnly) {
            ContributionBalance::scopeOverdue($query, $businessDate);
        } elseif ($remainingOnly) {
            ContributionBalance::scopeRemainingCollectable($query, $businessDate);
        } elseif ($actionable) {
            ContributionBalance::scopeCollectable($query, $businessDate);
        }

        $meta = null;
        if ($overdueOnly || $dueSchedule === 'overdue') {
            $countQuery = ContributionDue::forTenant($tenantId);
            if ($request->filled('status')) {
                $countQuery->where('status', $request->string('status'));
            }
            if ($request->filled('family_id')) {
                $countQuery->where('family_id', $request->string('family_id'));
            }
            if ($request->filled('plan_id')) {
                $countQuery->where('plan_id', $request->string('plan_id'));
            }
            ContributionBalance::scopeOverdue($countQuery, $businessDate);
            $meta = [
                'overdue_family_count' => (int) (clone $countQuery)
                    ->selectRaw('COUNT(DISTINCT family_id) as aggregate')
                    ->value('aggregate'),
            ];
        }

        $this->applyDueListSort($query, $request->sortColumn(), $request->sortDirection());

        $paginator = $query->paginate((int) $request->input('per_page', 20));
        $paginator->getCollection()->transform(function (ContributionDue $due) use ($businessDate): ContributionDue {
            $due->setAttribute('outstanding_amount', ContributionBalance::outstandingForDue($due));
            $due->setAttribute('is_overdue', ContributionBalance::scheduleState($due, $businessDate) === 'overdue');
            $due->setAttribute('schedule_state', ContributionBalance::scheduleState($due, $businessDate));

            return $due;
        });

        $payload = [
            'success' => true,
            'data' => $paginator,
        ];
        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload);
    }

    /**
     * @param  Builder<ContributionDue>  $query
     */
    private function applyDueListSort(Builder $query, string $sort, string $direction): void
    {
        $table = (new ContributionDue)->getTable();
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        if ($sort === 'due_date') {
            $query->orderBy("{$table}.due_date", $direction)->orderBy("{$table}.id");

            return;
        }

        if ($sort === 'period_label') {
            $query->orderBy("{$table}.period_label", $direction)
                ->orderBy("{$table}.due_date");

            return;
        }

        if ($sort === 'status') {
            $query->orderBy("{$table}.status", $direction)
                ->orderBy("{$table}.due_date");

            return;
        }

        if ($sort === 'outstanding') {
            $query->orderByRaw(
                "GREATEST({$table}.amount_due - {$table}.amount_paid, 0) {$direction}"
            )->orderBy("{$table}.due_date");

            return;
        }

        if ($sort === 'family_name') {
            $query->orderBy(
                Family::query()
                    ->select('family_name')
                    ->whereColumn('families.id', "{$table}.family_id")
                    ->limit(1),
                $direction
            )->orderBy("{$table}.due_date");

            return;
        }

        if ($sort === 'plan_name') {
            $query->orderBy(
                ContributionPlan::query()
                    ->select('name')
                    ->whereColumn('contribution_plans.id', "{$table}.plan_id")
                    ->limit(1),
                $direction
            )->orderBy("{$table}.due_date");

            return;
        }

        $query->orderBy("{$table}.due_date", 'asc')->orderBy("{$table}.id");
    }

    public function store(StoreDueRequest $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $payload = $request->validated();
        $payload['tenant_id'] = $tenantId;
        $payload['amount_paid'] = 0;
        $payload['status'] = 'pending';

        $due = ContributionDue::create($payload);
        $this->auditService->log($tenantId, 'due.created', 'due', $due->id, null, $due->toArray());
        SendContributionReminderJob::dispatch($tenantId, $due->id);

        return response()->json([
            'success' => true,
            'message' => 'Due created successfully.',
            'data' => $due,
        ], 201);
    }

    public function waive(string $id, UpdateDueStatusRequest $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();
        $due = ContributionDue::forTenant($tenantId)->findOrFail($id);

        if (! in_array($due->status, ['pending', 'partially_paid'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending or partially paid dues can be waived.',
            ], 422);
        }

        $due = $this->dueService->waiveDue($tenantId, $userId, $due, $request->validated()['reason'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'Due waived successfully.',
            'data' => $due,
        ]);
    }

    public function cancel(string $id, UpdateDueStatusRequest $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();
        $due = ContributionDue::forTenant($tenantId)->findOrFail($id);

        if (! in_array($due->status, ['pending', 'partially_paid'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending or partially paid dues can be cancelled.',
            ], 422);
        }

        $due = $this->dueService->cancelDue($tenantId, $userId, $due, $request->validated()['reason'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'Due cancelled successfully.',
            'data' => $due,
        ]);
    }

    public function sendReminder(string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $due = ContributionDue::forTenant($tenantId)->findOrFail($id);
        if (! \Modules\Donations\Support\ContributionBalance::isCollectableOverdue($due, \Modules\Donations\Support\DonationBusinessDate::today($tenantId))) {
            return response()->json([
                'success' => false,
                'message' => 'Reminders are sent only for collectable overdue dues.',
            ], 422);
        }

        SendContributionReminderJob::dispatch($tenantId, $due->id);

        return response()->json([
            'success' => true,
            'message' => 'Contribution reminder queued.',
        ]);
    }

    public function bulkWaive(BulkUpdateContributionDuesRequest $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();
        $result = $this->dueService->bulkWaive(
            $tenantId,
            $userId,
            $request->dueIds(),
            $request->validated()['reason'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => $this->bulkActionMessage('waived', $result),
            'data' => $result,
        ]);
    }

    public function bulkRemind(BulkUpdateContributionDuesRequest $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $result = $this->dueService->bulkRemind($tenantId, $request->dueIds());

        return response()->json([
            'success' => true,
            'message' => $this->bulkActionMessage('reminded', $result),
            'data' => $result,
        ]);
    }

    /**
     * @param  array{processed_count: int, skipped_count: int, skipped: array<int, array{family_name: string|null, reason: string}>}  $result
     */
    private function bulkActionMessage(string $verb, array $result): string
    {
        $processed = (int) $result['processed_count'];
        $skipped = (int) $result['skipped_count'];
        $noun = $processed === 1 ? 'due' : 'dues';
        $message = sprintf('%d %s %s.', $processed, $noun, $verb);

        if ($skipped === 0) {
            return $message;
        }

        $details = [];
        foreach ($result['skipped'] as $row) {
            $label = $row['family_name'] ?: 'a selected due';
            $details[] = $label.': '.$row['reason'];
        }

        return $message.' '.$skipped.' could not be processed. '.implode(' ', $details);
    }
}
