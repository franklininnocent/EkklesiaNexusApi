<?php

namespace Modules\Subscriptions\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Subscriptions\Http\Requests\Admin\AssignTenantPlanRequest;
use Modules\Subscriptions\Http\Requests\Admin\GrantOverrideRequest;
use Modules\Subscriptions\Http\Requests\Admin\PreviewPlanChangeRequest;
use Modules\Subscriptions\Http\Requests\Admin\ReasonRequest;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\TenantEntitlementOverride;
use Modules\Subscriptions\Services\EntitlementOverrideService;
use Modules\Subscriptions\Services\PlanImpactService;
use Modules\Subscriptions\Services\SubscriptionPlanChangeService;
use Modules\Subscriptions\Services\SubscriptionPresenter;
use Modules\Tenants\Models\Tenant;

/**
 * Platform operations on one tenant's subscription. The tenant id comes from the route and
 * the caller is a platform administrator (middleware), so cross-tenant access is by design.
 */
class TenantSubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionPlanChangeService $changes,
        private readonly PlanImpactService $impact,
        private readonly EntitlementOverrideService $overrides,
        private readonly SubscriptionPresenter $presenter,
    ) {}

    public function show(int $tenant): JsonResponse
    {
        $actor = request()->user();
        $includeOverrides = $actor !== null && $actor->isSuperAdmin();

        return response()->json([
            'success' => true,
            'data' => $this->presenter->adminTenantSubscription($this->tenant($tenant), $includeOverrides),
        ]);
    }

    public function preview(PreviewPlanChangeRequest $request, int $tenant): JsonResponse
    {
        $tenantModel = $this->tenant($tenant);
        $plan = Plan::query()->catalog()->findOrFail((int) $request->validated('plan_id'));
        $version = $this->changes->assertAssignable($plan);

        return response()->json([
            'success' => true,
            'data' => $this->impact->compare($tenantModel, $version, (array) ($request->validated('custom_limits') ?? [])),
        ]);
    }

    public function assign(AssignTenantPlanRequest $request, int $tenant): JsonResponse
    {
        $tenantModel = $this->tenant($tenant);
        $plan = Plan::query()->catalog()->findOrFail((int) $request->validated('plan_id'));

        $subscription = $this->changes->assign($tenantModel, $plan, [
            'billing_interval' => $request->validated('billing_interval'),
            'contracted_price' => $request->validated('contracted_price'),
            'custom_limits' => $request->validated('custom_limits'),
            'duration_months' => $request->validated('duration_months'),
            'start_trial' => $request->boolean('start_trial'),
            'scheduled_for' => $request->validated('scheduled_for'),
            'reason' => $request->validated('reason'),
            'confirm_impact' => $request->boolean('confirm_impact'),
            'source' => 'ASSIGNMENT',
        ], $request->user());

        return response()->json([
            'success' => true,
            'message' => $subscription->record_status === 'PENDING' ? 'Plan change scheduled.' : 'Plan updated.',
            'data' => $this->presenter->adminTenantSubscription(
                $tenantModel->fresh(),
                $request->user()?->isSuperAdmin() ?? false
            ),
        ]);
    }

    public function cancelPending(ReasonRequest $request, int $tenant): JsonResponse
    {
        $cancelled = $this->changes->cancelPending($this->tenant($tenant), $request->user(), $request->validated('reason'));

        return response()->json(['success' => true, 'cancelled' => $cancelled]);
    }

    public function grantOverride(GrantOverrideRequest $request, int $tenant): JsonResponse
    {
        $override = $this->overrides->grant($this->tenant($tenant), $request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $this->overrides->present($override)], 201);
    }

    public function revokeOverride(ReasonRequest $request, int $tenant, int $override): JsonResponse
    {
        $tenantModel = $this->tenant($tenant);
        $model = TenantEntitlementOverride::query()->where('tenant_id', $tenantModel->id)->findOrFail($override);
        $revoked = $this->overrides->revoke($tenantModel, $model, $request->user(), (string) ($request->validated('reason') ?? 'Revoked by administrator'));

        return response()->json(['success' => true, 'data' => $this->overrides->present($revoked)]);
    }

    private function tenant(int $id): Tenant
    {
        return Tenant::query()->findOrFail($id);
    }
}
