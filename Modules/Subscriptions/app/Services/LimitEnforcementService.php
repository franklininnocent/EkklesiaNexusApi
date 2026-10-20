<?php

namespace Modules\Subscriptions\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Subscriptions\Exceptions\SubscriptionException;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\PlanVersion;
use Modules\Subscriptions\Services\Entitlements\EntitlementCatalog;
use Modules\Subscriptions\Services\Entitlements\EntitlementResolver;
use Modules\Subscriptions\Support\EntitlementEngineMode;
use Modules\Tenants\Contracts\TenantLimitGuard;
use Modules\Tenants\Models\Tenant;

/**
 * Server-side limit checks for creation flows (people, families, staff users, storage).
 *
 * Only new records are blocked; existing data is never removed or hidden when a tenant is
 * over a limit (e.g. after a downgrade).
 */
class LimitEnforcementService implements TenantLimitGuard
{
    public function __construct(
        private readonly EntitlementResolver $resolver,
        private readonly EntitlementCatalog $catalog,
        private readonly UsageService $usage,
        private readonly SubscriptionPolicyService $policies,
        private readonly SubscriptionAuditService $audit,
    ) {}

    public function assertTenantCanAdd(int $tenantId, string $metricCode, int $adding = 1, ?string $flow = null): void
    {
        if ($tenantId <= 0 || EntitlementEngineMode::isLegacy()) {
            return;
        }
        $tenant = Tenant::query()->find($tenantId);
        if ($tenant) {
            $this->assertCanAdd($tenant, $metricCode, $adding, $flow);
        }
    }

    /**
     * @return array{allowed: bool, limit: int|null, usage: int|null, remaining: int|null, enforced: bool}
     */
    public function check(Tenant $tenant, string $code, int $adding = 1): array
    {
        $code = strtoupper($code);
        $feature = $this->catalog->feature($code);
        $resolved = $this->resolver->resolve($tenant);
        $enforced = EntitlementEngineMode::isEnforcing() && $this->policies->blocksOverLimit();

        if (! $feature || ! in_array($feature['type'], ['LIMIT', 'QUOTA', 'USAGE'], true)) {
            return [
                'allowed' => ! $enforced,
                'limit' => null,
                'usage' => null,
                'remaining' => null,
                'enforced' => $enforced,
                'configuration_missing' => true,
            ];
        }

        if (! $resolved->hasEntitlement($code)) {
            return [
                'allowed' => ! $enforced,
                'limit' => null,
                'usage' => null,
                'remaining' => null,
                'enforced' => $enforced,
                'configuration_missing' => true,
            ];
        }

        if ($resolved->limitIsUnlimited($code)) {
            return ['allowed' => true, 'limit' => null, 'usage' => null, 'remaining' => null, 'enforced' => false];
        }

        $limit = $resolved->limit($code);
        if ($limit === 0) {
            return [
                'allowed' => max(0, $adding) === 0,
                'limit' => 0,
                'usage' => $this->usage->current((int) $tenant->getKey(), $code),
                'remaining' => 0,
                'enforced' => $enforced,
            ];
        }

        $usage = strtoupper($code) === 'STORAGE_MB'
            ? $this->usage->currentFresh((int) $tenant->getKey(), $code)
            : $this->usage->current((int) $tenant->getKey(), $code);
        if ($usage === null) {
            return [
                'allowed' => ! $enforced,
                'limit' => $limit,
                'usage' => null,
                'remaining' => null,
                'enforced' => $enforced,
                'configuration_missing' => true,
            ];
        }

        return [
            'allowed' => $usage + max(0, $adding) <= $limit,
            'limit' => $limit,
            'usage' => $usage,
            'remaining' => max(0, $limit - $usage),
            'enforced' => $enforced,
        ];
    }

    /**
     * Throws ENTITLEMENT_LIMIT_REACHED when adding $adding units would exceed the plan limit.
     * Call inside the creating transaction so the tenant row lock serialises concurrent creates.
     */
    public function assertCanAdd(Tenant $tenant, string $code, int $adding = 1, ?string $flow = null): void
    {
        if (! $tenant->getKey() || EntitlementEngineMode::isLegacy()) {
            return;
        }
        if ($adding <= 0) {
            return;
        }

        if ($flow !== null && $this->policies->isFlowExempt($flow)) {
            return;
        }

        if (EntitlementEngineMode::isEnforcing() && DB::transactionLevel() === 0) {
            throw SubscriptionException::invalidState('Plan limit checks must run inside a database transaction.');
        }

        if (DB::transactionLevel() > 0) {
            DB::table('tenants')->where('id', $tenant->getKey())->lockForUpdate()->first(['id']);
        }

        $result = $this->check($tenant, $code, $adding);
        if ($result['allowed']) {
            return;
        }

        $context = [
            'feature_code' => strtoupper($code),
            'limit' => $result['limit'],
            'current_usage' => $result['usage'],
            'adding' => $adding,
        ];

        if (! empty($result['configuration_missing'])) {
            if (! $result['enforced']) {
                Log::warning('subscriptions.limit_configuration_missing', $context + ['tenant_id' => (int) $tenant->getKey()]);

                return;
            }

            $this->audit->denial('limit_configuration_missing', (int) $tenant->getKey(), $context);
            throw SubscriptionException::entitlementConfigurationMissing(strtoupper($code));
        }

        if (! $result['enforced']) {
            Log::info('subscriptions.limit_would_block', $context + ['tenant_id' => (int) $tenant->getKey()]);

            return;
        }

        $this->audit->denial('limit_reached', (int) $tenant->getKey(), $context);

        $feature = $this->catalog->feature($code);
        throw SubscriptionException::limitReached(
            strtoupper($code),
            (string) ($feature['unit'] ?? strtolower((string) ($feature['name'] ?? $code))),
            (int) $result['limit'],
            (int) $result['usage'],
            $this->upgradeAvailable($code, (int) $result['limit'])
        );
    }

    /**
     * Whether any publicly listed plan offers a higher limit for this feature.
     */
    public function upgradeAvailable(string $code, int $currentLimit): bool
    {
        $feature = $this->catalog->feature($code);
        if (! $feature || ! $feature['id']) {
            return false;
        }

        return PlanVersion::query()
            ->where('status', PlanVersion::STATUS_ACTIVE)
            ->whereIn('plan_id', Plan::query()->publiclyListed()->where('is_assignable', true)->select('id'))
            ->whereHas('entitlements', function ($q) use ($feature, $currentLimit): void {
                $q->where('feature_id', $feature['id'])
                    ->where('is_enabled', true)
                    ->where(fn ($inner) => $inner->whereNull('numeric_value')->orWhere('numeric_value', '>', $currentLimit));
            })
            ->exists();
    }
}
