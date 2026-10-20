<?php

namespace Modules\Subscriptions\Services\Entitlements;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\Subscriptions\Models\PlanVersion;
use Modules\Subscriptions\Models\TenantEntitlementOverride;
use Modules\Subscriptions\Models\TenantSubscription;
use Modules\Subscriptions\Support\EntitlementCacheVersion;
use Modules\Tenants\Models\Tenant;

/**
 * Resolves effective tenant entitlements.
 *
 * Order: plan version entitlements → subscription custom limits → tenant overrides in effect
 * → dependency enforcement → core features forced on (boolean only for limit features).
 * Tenants without a CURRENT subscription fall back to LegacyEntitlementSource.
 */
class EntitlementResolver
{
    private const LEGACY_MARKER = ['source' => ResolvedEntitlements::SOURCE_LEGACY];

    /** @var array<int, array<string, mixed>> */
    private array $memo = [];

    public function __construct(
        private readonly EntitlementCatalog $catalog,
        private readonly LegacyEntitlementSource $legacySource,
    ) {}

    public function resolve(Tenant $tenant): ResolvedEntitlements
    {
        $tenantId = (int) ($tenant->getKey() ?? 0);
        if ($tenantId <= 0) {
            return $this->legacySource->resolve($tenant);
        }

        $payload = $this->memo[$tenantId] ?? null;
        if ($payload === null || $this->expired($payload)) {
            $key = EntitlementCacheVersion::entitlementsKey($tenantId);
            $payload = Cache::get($key);
            if (! is_array($payload) || $this->expired($payload)) {
                $payload = $this->resolveFromSubscription($tenantId) ?? self::LEGACY_MARKER;
                Cache::put($key, $payload, $this->ttlFor($payload));
            }
            $this->memo[$tenantId] = $payload;
        }

        // Legacy results derive from the live tenant model, so they are never cached.
        return ($payload['source'] ?? null) === ResolvedEntitlements::SOURCE_SUBSCRIPTION
            ? ResolvedEntitlements::fromArray($payload)
            : $this->legacySource->resolve($tenant);
    }

    public function forget(int $tenantId): void
    {
        unset($this->memo[$tenantId]);
        EntitlementCacheVersion::bumpTenant($tenantId);
    }

    public function flushMemo(): void
    {
        $this->memo = [];
    }

    /**
     * Uncached subscription-based resolution; null when the tenant has no CURRENT subscription.
     *
     * @return array<string, mixed>|null
     */
    public function resolveFromSubscription(int $tenantId): ?array
    {
        $subscription = TenantSubscription::query()
            ->forTenant($tenantId)
            ->current()
            ->with(['version.entitlements', 'plan'])
            ->first();

        if (! $subscription || ! $subscription->version) {
            return null;
        }

        $overrides = TenantEntitlementOverride::query()
            ->where('tenant_id', $tenantId)
            ->open()
            ->get();

        return $this->compose($tenantId, $subscription, $subscription->version, (array) ($subscription->custom_limits ?? []), $overrides)->toArray();
    }

    /**
     * Entitlements the tenant would have on $version (plan-change impact preview).
     *
     * @param  array<string, int|null>  $customLimits
     * @param  list<int>  $excludeOverrideIds  overrides that the change would revoke
     */
    public function simulate(Tenant $tenant, PlanVersion $version, array $customLimits = [], array $excludeOverrideIds = []): ResolvedEntitlements
    {
        $version->loadMissing(['entitlements', 'plan']);
        $overrides = TenantEntitlementOverride::query()
            ->where('tenant_id', (int) $tenant->getKey())
            ->open()
            ->when($excludeOverrideIds !== [], fn ($q) => $q->whereNotIn('id', $excludeOverrideIds))
            ->get();

        return $this->compose((int) $tenant->getKey(), null, $version, $customLimits, $overrides);
    }

    /**
     * @param  array<string, int|null>  $customLimits
     * @param  Collection<int, TenantEntitlementOverride>  $overrides
     */
    private function compose(int $tenantId, ?TenantSubscription $subscription, PlanVersion $version, array $customLimits, Collection $overrides): ResolvedEntitlements
    {
        $features = [];
        foreach ($this->catalog->features() as $code => $feature) {
            $features[$code] = [
                'enabled' => false,
                'value' => null,
                'tier' => null,
                'type' => (string) $feature['type'],
                'source' => 'plan',
            ];
        }

        foreach ($version->entitlements as $entitlement) {
            $code = $this->catalog->codeForId((int) $entitlement->feature_id);
            if ($code === null) {
                continue;
            }
            $features[$code]['enabled'] = (bool) $entitlement->is_enabled;
            $features[$code]['value'] = $entitlement->numeric_value;
            $features[$code]['tier'] = $entitlement->tier_value;
        }

        foreach ($customLimits as $code => $value) {
            $code = strtoupper((string) $code);
            if (! isset($features[$code]) || ! in_array($features[$code]['type'], ['LIMIT', 'QUOTA', 'USAGE'], true)) {
                continue;
            }
            $features[$code]['enabled'] = true;
            $features[$code]['value'] = $value === null ? null : max(0, (int) $value);
            $features[$code]['source'] = 'custom_limit';
        }

        $validUntil = null;
        $now = CarbonImmutable::now();

        foreach ($overrides as $override) {
            if ($override->effective_from && $override->effective_from->greaterThan($now)) {
                $validUntil = $this->earliest($validUntil, $override->effective_from->toIso8601String());

                continue;
            }
            if ($override->effective_until && $override->effective_until->lessThanOrEqualTo($now)) {
                continue;
            }
            if ($override->effective_until) {
                $validUntil = $this->earliest($validUntil, $override->effective_until->toIso8601String());
            }

            $code = $this->catalog->codeForId((int) $override->feature_id);
            if ($code === null || ! isset($features[$code])) {
                continue;
            }

            $features[$code] = $this->applyOverride($features[$code], $override);
        }

        $features = DependencyEnforcer::apply($features, $this->catalog);

        foreach ($features as $code => $entry) {
            if ($this->catalog->feature($code)['is_core'] ?? false) {
                $features[$code]['enabled'] = true;
                if (! in_array($entry['type'], ['LIMIT', 'QUOTA', 'USAGE'], true)) {
                    $features[$code]['source'] = 'core';
                }
            }
        }

        $plan = $version->plan;

        return new ResolvedEntitlements(
            $tenantId,
            ResolvedEntitlements::SOURCE_SUBSCRIPTION,
            [
                'subscription_id' => $subscription ? (int) $subscription->id : null,
                'id' => $plan ? (int) $plan->id : null,
                'code' => $plan?->code,
                'key' => $plan?->key,
                'name' => $plan?->name,
                'pricing_type' => $plan?->pricing_type,
                'is_legacy' => (bool) ($plan?->is_legacy ?? false),
                'version_id' => (int) $version->id,
                'version_number' => (int) $version->version_number,
                'version_status' => $version->status,
                'billing_interval' => $subscription?->billing_interval,
            ],
            $features,
            false,
            $validUntil,
        );
    }

    /**
     * @param  array{enabled: bool, value: int|null, tier: string|null, type: string, source: string}  $entry
     * @return array{enabled: bool, value: int|null, tier: string|null, type: string, source: string}
     */
    private function applyOverride(array $entry, TenantEntitlementOverride $override): array
    {
        switch ($override->mode) {
            case TenantEntitlementOverride::MODE_ENABLE:
                $entry['enabled'] = true;
                break;
            case TenantEntitlementOverride::MODE_DISABLE:
                $entry['enabled'] = false;
                break;
            case TenantEntitlementOverride::MODE_SET_LIMIT:
                $entry['enabled'] = true;
                $entry['value'] = max(0, (int) $override->numeric_value);
                break;
            case TenantEntitlementOverride::MODE_UNLIMITED:
                $entry['enabled'] = true;
                $entry['value'] = null;
                break;
            case TenantEntitlementOverride::MODE_SET_TIER:
                $entry['enabled'] = true;
                $entry['tier'] = $override->tier_value;
                break;
            default:
                return $entry;
        }
        $entry['source'] = 'override';

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function expired(array $payload): bool
    {
        $until = $payload['valid_until'] ?? null;

        return is_string($until) && CarbonImmutable::parse($until)->lessThanOrEqualTo(CarbonImmutable::now());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ttlFor(array $payload): int
    {
        $ttl = max(30, (int) config('subscriptions.cache.ttl_seconds', 900));
        $until = $payload['valid_until'] ?? null;
        if (is_string($until)) {
            $seconds = CarbonImmutable::now()->diffInSeconds(CarbonImmutable::parse($until), false);
            $ttl = (int) max(1, min($ttl, $seconds));
        }

        return $ttl;
    }

    private function earliest(?string $current, string $candidate): string
    {
        if ($current === null) {
            return $candidate;
        }

        return CarbonImmutable::parse($candidate)->lessThan(CarbonImmutable::parse($current)) ? $candidate : $current;
    }
}
