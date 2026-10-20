<?php

namespace Modules\Subscriptions\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Subscriptions\Http\Requests\Admin\ArchivePlanRequest;
use Modules\Subscriptions\Http\Requests\Admin\DuplicatePlanRequest;
use Modules\Subscriptions\Http\Requests\Admin\ReasonRequest;
use Modules\Subscriptions\Http\Requests\Admin\StorePlanRequest;
use Modules\Subscriptions\Http\Requests\Admin\UpdatePlanRequest;
use Modules\Subscriptions\Models\Feature;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\PlanVersion;
use Modules\Subscriptions\Models\TenantSubscription;
use Modules\Subscriptions\Services\PlanService;
use Modules\Subscriptions\Services\SubscriptionPresenter;
use Modules\Subscriptions\Support\SubscriptionFeatureCapabilityCatalog;
use Modules\Subscriptions\Support\SubscriptionRbacCapabilityCatalog;
use Modules\Tenants\Services\SubscriptionService;

class PlanCatalogController extends Controller
{
    public function __construct(
        private readonly PlanService $plans,
        private readonly SubscriptionPresenter $presenter,
        private readonly SubscriptionService $lifecycle,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Plan::query()->catalog()->with('activeVersion.entitlements.feature')->orderBy('display_order')->orderBy('name');
        if (! $request->boolean('include_legacy')) {
            $query->where('is_legacy', false);
        }
        if (! $request->boolean('include_archived')) {
            $query->where('status', '!=', Plan::STATUS_ARCHIVED);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get()->map(fn (Plan $p) => $this->presenter->adminPlan($p))->values(),
        ]);
    }

    public function show(int $plan): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->presenter->adminPlan($this->find($plan), true),
        ]);
    }

    public function store(StorePlanRequest $request): JsonResponse
    {
        $plan = $this->plans->create($request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $this->presenter->adminPlan($plan, true)], 201);
    }

    public function update(UpdatePlanRequest $request, int $plan): JsonResponse
    {
        $updated = $this->plans->update($this->find($plan), $request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $this->presenter->adminPlan($updated, true)]);
    }

    public function duplicate(DuplicatePlanRequest $request, int $plan): JsonResponse
    {
        $copy = $this->plans->duplicate($this->find($plan), $request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $this->presenter->adminPlan($copy, true)], 201);
    }

    /**
     * Tenants currently on this plan (any version).
     */
    public function tenants(Request $request, int $plan): JsonResponse
    {
        $planModel = $this->find($plan);
        $filters = $request->validate([
            'version_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = TenantSubscription::query()
            ->where('plan_id', $planModel->id)
            ->current()
            ->when($filters['version_id'] ?? null, fn ($q, $id) => $q->where('plan_version_id', (int) $id))
            ->when($filters['search'] ?? null, function ($q, string $search) {
                $term = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
                $q->whereHas('tenant', fn ($t) => $t->whereRaw('LOWER(name) LIKE ?', [$term]));
            })
            ->with(['tenant', 'version'])
            ->orderBy('tenant_id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->filter(fn (TenantSubscription $s) => $s->tenant !== null)->map(fn (TenantSubscription $s) => [
                'tenant_id' => $s->tenant_id,
                'tenant_name' => $s->tenant->name,
                'tenant_tier' => $s->tenant->tenant_tier,
                'lifecycle_status' => $this->lifecycle->resolveStatus($s->tenant),
                'version_id' => $s->plan_version_id,
                'version_number' => $s->version?->version_number,
                'billing_interval' => $s->billing_interval,
                'contracted_price' => $s->contracted_price === null ? null : (string) $s->contracted_price,
                'currency_code' => $s->currency_code,
                'starts_at' => $s->starts_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function archive(ArchivePlanRequest $request, int $plan): JsonResponse
    {
        $archived = $this->plans->archive(
            $this->find($plan),
            $request->user(),
            $request->validated('reason'),
            (bool) $request->boolean('confirm_assigned_churches'),
        );

        return response()->json(['success' => true, 'data' => $this->presenter->adminPlan($archived)]);
    }

    public function restore(ReasonRequest $request, int $plan): JsonResponse
    {
        $restored = $this->plans->restore($this->find($plan), $request->user(), $request->validated('reason'));

        return response()->json(['success' => true, 'data' => $this->presenter->adminPlan($restored)]);
    }

    public function destroy(ReasonRequest $request, int $plan): JsonResponse
    {
        $this->plans->delete($this->find($plan), $request->user(), $request->validated('reason'));

        return response()->json(['success' => true]);
    }

    /**
     * Features × plans matrix of the ACTIVE version of each non-legacy plan.
     */
    public function matrix(): JsonResponse
    {
        $plans = Plan::query()->catalog()->where('is_legacy', false)->where('status', '!=', Plan::STATUS_ARCHIVED)
            ->orderBy('display_order')->get();
        $versions = PlanVersion::query()->whereIn('plan_id', $plans->pluck('id'))
            ->whereIn('status', [PlanVersion::STATUS_ACTIVE, PlanVersion::STATUS_DRAFT])
            ->with('entitlements')
            ->get()
            ->groupBy('plan_id');

        $columns = $plans->map(function (Plan $plan) use ($versions) {
            $candidates = $versions[$plan->id] ?? collect();
            $version = $candidates->firstWhere('status', PlanVersion::STATUS_ACTIVE) ?? $candidates->first();

            return [
                'plan' => ['id' => $plan->id, 'code' => $plan->code, 'name' => $plan->name, 'status' => $plan->status, 'is_featured' => (bool) $plan->is_featured],
                'version' => $version ? ['id' => $version->id, 'version_number' => $version->version_number, 'status' => $version->status] : null,
                'cells' => $version ? $version->entitlements->mapWithKeys(fn ($e) => [$e->feature_id => [
                    'is_enabled' => (bool) $e->is_enabled,
                    'numeric_value' => $e->numeric_value,
                    'tier_value' => $e->tier_value,
                ]])->all() : [],
            ];
        })->values();

        $features = Feature::query()->ordered()->get()->map(fn (Feature $f) => [
            'id' => $f->id,
            'code' => $f->code,
            'name' => $f->name,
            'description' => $f->description,
            'category' => $f->category,
            'feature_type' => $f->feature_type,
            'unit' => $f->unit,
            'is_core' => (bool) $f->is_core,
            'is_active' => (bool) $f->is_active,
        ])->values();

        return response()->json([
            'success' => true,
            'data' => [
                'features' => $features,
                'plans' => $columns,
                'rbac_capability_comparison' => SubscriptionRbacCapabilityCatalog::rows(),
                'feature_capability_sections' => SubscriptionFeatureCapabilityCatalog::sections(),
            ],
        ]);
    }

    private function find(int $id): Plan
    {
        return Plan::query()->catalog()->findOrFail($id);
    }
}
