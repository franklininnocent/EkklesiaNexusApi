<?php

namespace Modules\Subscriptions\Services;

use App\Support\MoneyMath;
use Modules\Subscriptions\Models\Feature;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\PlanVersion;
use Modules\Subscriptions\Models\TenantEntitlementOverride;
use Modules\Subscriptions\Models\TenantSubscription;
use Modules\Subscriptions\Services\Catalog\CatalogListPriceResolver;
use Modules\Subscriptions\Services\Entitlements\EntitlementCatalog;
use Modules\Subscriptions\Services\Entitlements\EntitlementResolver;
use Modules\Subscriptions\Services\Entitlements\LegacyEntitlementSource;
use Modules\Subscriptions\Services\Entitlements\ResolvedEntitlements;
use Modules\Subscriptions\Support\EntitlementEngineMode;
use Modules\Subscriptions\Support\TaxCalculator;
use Modules\Subscriptions\Support\TaxPolicy;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\SubscriptionService;

/**
 * API shapes for plans, versions and tenant subscriptions. Money is always a decimal string.
 */
class SubscriptionPresenter
{
    public function __construct(
        private readonly EntitlementCatalog $catalog,
        private readonly EntitlementResolver $resolver,
        private readonly UsageService $usage,
        private readonly SubscriptionPolicyService $policies,
        private readonly SubscriptionService $lifecycle,
        private readonly PlanService $plans,
        private readonly CurrentPlanIdentityService $currentPlan,
        private readonly CatalogListPriceResolver $listPrices,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function adminPlan(Plan $plan, bool $withVersions = false): array
    {
        $counts = $this->plans->tenantCounts($plan);
        $active = $plan->relationLoaded('activeVersion') ? $plan->activeVersion : $plan->activeVersion()->first();
        $occupancy = $this->plans->occupancyCount($plan);
        $restriction = $this->plans->editRestriction($plan, $occupancy);
        $deleteRestriction = $this->plans->deleteRestriction($plan, $occupancy);

        $data = $this->plans->present($plan) + [
            'description' => $plan->description,
            'archived_at' => $plan->archived_at?->toIso8601String(),
            'is_default' => $this->policies->defaultPlanId() === (int) $plan->id,
            'tenant_count' => array_sum($counts),
            'is_editable' => $this->plans->isEditable($plan, $occupancy),
            'edit_restriction' => $restriction,
            'edit_policy' => $this->plans->editPolicy($plan, $occupancy),
            'can_delete' => $deleteRestriction === null,
            'delete_restriction' => $deleteRestriction,
            'active_version' => $active ? array_merge($this->version($active, false), [
                'limit_summary' => $this->limitSummary($active),
                'discovery' => $this->versionDiscovery($active),
            ]) : null,
            'created_at' => $plan->created_at?->toIso8601String(),
            'updated_at' => $plan->updated_at?->toIso8601String(),
        ];

        if ($withVersions) {
            $data['versions'] = $plan->versions()->with('entitlements.feature')->get()
                ->map(fn (PlanVersion $v) => $this->version($v, true) + ['tenant_count' => $counts[(string) $v->id] ?? 0])
                ->values()->all();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function version(PlanVersion $version, bool $withEntitlements = true): array
    {
        $data = [
            'id' => $version->id,
            'plan_id' => $version->plan_id,
            'version_number' => $version->version_number,
            'status' => $version->status,
            'currency_code' => $version->currency_code,
            'monthly_price' => $this->money($version->monthly_price),
            'annual_price' => $this->money($version->annual_price),
            'setup_fee' => $this->money($version->setup_fee),
            'tax_inclusive' => (bool) $version->tax_inclusive,
            'tax_rate_percent' => $this->money($version->tax_rate_percent),
            'tax_label' => $version->tax_label,
            'trial_days' => $version->trial_days,
            'billing_intervals' => array_values((array) $version->billing_intervals),
            'effective_from' => $version->effective_from?->toIso8601String(),
            'published_at' => $version->published_at?->toIso8601String(),
            'retired_at' => $version->retired_at?->toIso8601String(),
            'change_notes' => $version->change_notes,
            'is_editable' => $version->isEditable(),
            'effective_tax' => TaxPolicy::resolve($version, $this->policies->platformTax()),
        ];

        if ($withEntitlements) {
            $version->loadMissing('entitlements.feature');
            $data['entitlements'] = $version->entitlements
                ->filter(fn ($e) => $e->feature !== null)
                ->sortBy(fn ($e) => [$e->feature->category, $e->feature->display_order])
                ->map(fn ($e) => [
                    'feature_code' => $e->feature->code,
                    'feature_name' => $e->feature->name,
                    'description' => $e->feature->description,
                    'feature_type' => $e->feature->feature_type,
                    'category' => $e->feature->category,
                    'unit' => $e->feature->unit,
                    'is_core' => (bool) $e->feature->is_core,
                    'is_enabled' => (bool) $e->is_enabled,
                    'numeric_value' => $e->numeric_value,
                    'unlimited' => $e->feature->isNumeric() && $e->is_enabled && $e->numeric_value === null,
                    'tier_value' => $e->tier_value,
                ])->values()->all();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function feature(Feature $feature): array
    {
        $feature->loadMissing('dependencies');

        return [
            'id' => $feature->id,
            'code' => $feature->code,
            'name' => $feature->name,
            'description' => $feature->description,
            'category' => $feature->category,
            'module_key' => $feature->module_key,
            'feature_type' => $feature->feature_type,
            'unit' => $feature->unit,
            'legacy_key' => $feature->legacy_key,
            'is_core' => (bool) $feature->is_core,
            'is_public' => (bool) $feature->is_public,
            'is_active' => (bool) $feature->is_active,
            'display_order' => $feature->display_order,
            'tier_options' => $feature->tier_options,
            'dependencies' => $feature->dependencies->pluck('code')->values()->all(),
        ];
    }

    /**
     * Public pricing card (no internal ids beyond plan code, no tenant data).
     *
     * @return array<string, mixed>
     */
    public function publicPlan(Plan $plan, PlanVersion $version, ?Tenant $tenant = null): array
    {
        $version->loadMissing('entitlements.feature');
        $terms = $this->listPrices->billedTerms($plan, $version, $tenant);
        if ($terms['uses_regional_price']) {
            $taxRate = '0.00';
            $inclusive = false;
            $taxLabel = 'Tax';
            $resolved = [
                'label' => $taxLabel,
                'rate_percent' => $taxRate,
                'prices_include_tax' => $inclusive,
                'source' => 'catalog_country',
            ];
        } else {
            $resolved = TaxPolicy::resolve($version, $this->policies->platformTax());
            $taxRate = $resolved['rate_percent'];
            $inclusive = $resolved['prices_include_tax'];
            $taxLabel = $resolved['label'];
        }

        $features = [];
        $limits = [];
        foreach ($version->entitlements as $e) {
            $f = $e->feature;
            if (! $f || ! $f->is_active || ! $f->is_public) {
                continue;
            }
            if ($f->isNumeric()) {
                if ($e->is_enabled) {
                    $limits[] = ['code' => $f->code, 'name' => $f->name, 'unit' => $f->unit, 'value' => $e->numeric_value, 'unlimited' => $e->numeric_value === null];
                }

                continue;
            }
            if ($e->is_enabled) {
                $features[] = [
                    'code' => $f->code,
                    'name' => $f->name,
                    'description' => $f->description,
                    'category' => $f->category,
                    'display_order' => $f->display_order,
                    'is_core' => (bool) $f->is_core,
                    'included' => true,
                ];
            }
        }
        usort($features, static fn ($a, $b) => [$a['category'], $a['display_order']] <=> [$b['category'], $b['display_order']]);
        $coverage = $this->publicFeatureCoverage($version);

        return [
            'code' => $plan->code,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'short_description' => $plan->short_description,
            'badge_label' => $plan->badge_label,
            'is_featured' => (bool) $plan->is_featured,
            'pricing_type' => $plan->pricing_type,
            'display_order' => $plan->display_order,
            'currency_code' => $terms['currency_code'],
            'monthly_price' => $this->money($terms['monthly_price']),
            'annual_price' => $this->money($terms['annual_price']),
            'setup_fee' => $this->money($version->setup_fee),
            'billing_intervals' => array_values((array) $version->billing_intervals),
            'trial_days' => $version->trial_days,
            'tax' => [
                'label' => $taxLabel,
                'rate_percent' => $this->money($taxRate),
                'prices_include_tax' => $inclusive,
                'source' => $resolved['source'],
                'monthly' => TaxCalculator::breakdown($this->money($terms['monthly_price']), $taxRate, $inclusive),
                'annual' => TaxCalculator::breakdown($this->money($terms['annual_price']), $taxRate, $inclusive),
            ],
            'features' => array_map(static fn ($f) => [
                'code' => $f['code'],
                'name' => $f['name'],
                'category' => $f['category'],
                'description' => $f['description'],
                'is_core' => $f['is_core'],
                'included' => true,
            ], $features),
            'limits' => $limits,
            'coverage' => $coverage,
            'version_number' => $version->version_number,
        ];
    }

    /**
     * What a tenant on this version would get: the pricing card plus every active feature
     * marked included or not (the UI maps codes to navigation with its feature map).
     *
     * @return array<string, mixed>
     */
    /**
     * Full catalog join for one plan version (admin discovery / preview).
     *
     * @return array{modules: list<array<string, mixed>>, limits: list<array<string, mixed>>}
     */
    public function versionDiscovery(PlanVersion $version): array
    {
        $version->loadMissing('entitlements.feature');
        $byFeature = $version->entitlements->keyBy('feature_id');
        $modules = [];
        $limits = [];
        foreach (Feature::query()->where('is_active', true)->ordered()->get() as $feature) {
            $e = $byFeature[$feature->id] ?? null;
            $enabled = $feature->is_core || ($e && $e->is_enabled);
            if ($feature->isNumeric()) {
                $limits[] = [
                    'code' => $feature->code,
                    'name' => $feature->name,
                    'description' => $feature->description,
                    'unit' => $feature->unit,
                    'value' => $enabled ? $e?->numeric_value : 0,
                    'unlimited' => $enabled && $e?->numeric_value === null,
                    'included' => $enabled,
                ];

                continue;
            }
            $modules[] = [
                'code' => $feature->code,
                'name' => $feature->name,
                'description' => $feature->description,
                'category' => $feature->category,
                'module_key' => $feature->module_key,
                'is_core' => (bool) $feature->is_core,
                'enabled' => $enabled,
                'included' => $enabled,
                'tier' => $enabled ? $e?->tier_value : null,
            ];
        }

        return ['modules' => $modules, 'limits' => $limits];
    }

    public function versionPreview(Plan $plan, PlanVersion $version): array
    {
        $discovery = $this->versionDiscovery($version);

        return [
            'card' => $this->publicPlan($plan, $version),
            'version' => ['id' => $version->id, 'version_number' => $version->version_number, 'status' => $version->status],
            'modules' => $discovery['modules'],
            'limits' => $discovery['limits'],
        ];
    }

    /**
     * Compact entitlement map for UI gating (UX only — the API enforces server-side).
     *
     * @return array<string, mixed>
     */
    public function entitlementMap(Tenant $tenant): array
    {
        $resolved = $this->resolver->resolve($tenant);
        $effective = $this->effective($tenant, $resolved);
        $features = [];
        $names = [];
        $limits = [];
        foreach ($this->catalog->features() as $code => $feature) {
            if (in_array($feature['type'], ['LIMIT', 'QUOTA', 'USAGE'], true)) {
                $limits[$code] = $resolved->limit($code);
            } else {
                $features[$code] = $effective->allows($code);
                $names[$code] = (string) $feature['name'];
            }
        }

        return [
            'source' => $effective->source,
            'engine_mode' => EntitlementEngineMode::current(),
            'plan' => $this->authoritativePlanSummary($tenant, $resolved),
            'features' => $features,
            'feature_names' => $names,
            'limits' => $limits,
            'limits_enforced' => EntitlementEngineMode::isEnforcing(),
            'hash' => $this->entitlementsVersion($effective),
        ];
    }

    /**
     * Additive fields for the existing /tenant/subscription-access and /tenant/my-subscription
     * payloads. entitlements_version changes whenever the tenant's effective entitlements do.
     *
     * @return array<string, mixed>
     */
    public function accessSummary(Tenant $tenant, bool $withUsage = false): array
    {
        $resolved = $this->resolver->resolve($tenant);
        $data = [
            'plan' => $this->authoritativePlanSummary($tenant, $resolved),
            'entitlements_version' => $this->entitlementsVersion($this->effective($tenant, $resolved)),
            'engine_mode' => EntitlementEngineMode::current(),
        ];
        if ($withUsage) {
            $data['limits'] = $this->usage->summary($resolved);
        }

        return $data;
    }

    /**
     * Tenant-facing "My plan" overview.
     *
     * @return array<string, mixed>
     */
    public function tenantOverview(Tenant $tenant, bool $includeCommercialTerms = true): array
    {
        $resolved = $this->resolver->resolve($tenant);
        $current = TenantSubscription::query()->forTenant((int) $tenant->id)->current()->with(['plan', 'version.entitlements.feature'])->first();
        $pending = TenantSubscription::query()->forTenant((int) $tenant->id)
            ->where('record_status', TenantSubscription::RECORD_PENDING)
            ->with('plan')
            ->first();

        $descriptions = Feature::query()->where('is_active', true)->pluck('description', 'code');
        $entitlements = [];
        foreach ($this->catalog->features() as $code => $feature) {
            if (! $feature['is_active'] || in_array($feature['type'], ['LIMIT', 'QUOTA', 'USAGE'], true)) {
                continue;
            }
            $entitlements[] = [
                'code' => $code,
                'name' => $feature['name'],
                'description' => $descriptions[$code] ?? null,
                'category' => $feature['category'],
                'enabled' => $resolved->allows($code),
                'is_core' => (bool) $feature['is_core'],
            ];
        }

        $terms = null;
        if ($includeCommercialTerms && $current && $current->version) {
            $version = $current->version;
            $tax = TaxPolicy::resolve($version, $this->policies->platformTax());
            $taxRate = $tax['rate_percent'];
            $inclusive = $tax['prices_include_tax'];
            $terms = [
                'billing_interval' => $current->billing_interval,
                'currency_code' => $current->currency_code,
                'contracted_price' => $this->money($current->contracted_price),
                'tax_label' => $tax['label'],
                'tax' => TaxCalculator::breakdown($this->money($current->contracted_price), $taxRate, $inclusive),
                'version_number' => $version->version_number,
                'starts_at' => $current->starts_at?->toIso8601String(),
            ];
        }

        $identity = $this->currentPlan->resolve($tenant);
        $planResolved = ($current && $current->version)
            ? $this->resolver->simulate($tenant, $current->version, [], [])
            : null;

        return [
            'plan' => $this->authoritativePlanSummary($tenant, $resolved, $identity),
            'lifecycle' => $this->lifecycle->buildAccessSnapshot($tenant) + [
                'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
                'subscription_suspended_at' => $tenant->subscription_suspended_at?->toIso8601String(),
            ],
            'terms' => $terms,
            'pending_change' => $pending ? [
                'plan_code' => $pending->plan?->code,
                'plan_name' => $pending->plan?->name,
                'scheduled_for' => $pending->scheduled_for?->toIso8601String(),
            ] : null,
            'entitlements' => $entitlements,
            'usage' => $this->usage->summary($resolved, $planResolved),
            'engine_mode' => EntitlementEngineMode::current(),
            'comparison' => $this->tenantComparison($tenant, $identity),
        ];
    }

    /**
     * Authenticated comparison catalog with the current plan flagged by the server.
     *
     * @param  array<string, mixed>|null  $identity
     * @return array<string, mixed>
     */
    public function tenantComparison(Tenant $tenant, ?array $identity = null): array
    {
        $identity ??= $this->currentPlan->resolve($tenant);
        $catalog = Plan::query()->publiclyListed()
            ->with('activeVersion.entitlements.feature')
            ->orderBy('display_order')
            ->get();

        $cards = [];
        $currentListed = false;
        foreach ($catalog as $plan) {
            if ($plan->activeVersion === null) {
                continue;
            }
            $isCurrent = $this->currentPlan->matches($plan, $identity);
            if ($isCurrent) {
                $currentListed = true;
            }
            $version = $this->comparisonVersion($plan, $identity, $isCurrent);
            $cards[] = $this->comparisonCard($plan, $version, $identity, $isCurrent, true, $tenant);
        }

        if (($identity['matched'] ?? false) && ! $currentListed && ($identity['id'] ?? null)) {
            $plan = Plan::withTrashed()->with('activeVersion.entitlements.feature')->find((int) $identity['id']);
            $version = $plan ? $this->comparisonVersion($plan, $identity, true) : null;
            if ($plan && $version) {
                $cards[] = $this->comparisonCard($plan, $version, $identity, true, false, $tenant);
            }
        }

        $currentCard = collect($cards)->firstWhere('is_current', true);
        $listedPlan = $catalog->firstWhere('id', (int) ($identity['id'] ?? 0));

        return [
            'current_plan' => $identity + [
                'using_subscribed_version' => (bool) ($currentCard['using_subscribed_version'] ?? false),
                'subscribed_version_number' => $currentCard['version_number'] ?? ($identity['version_number'] ?? null),
                'catalog_version_number' => ($currentCard['using_subscribed_version'] ?? false)
                    ? $listedPlan?->activeVersion?->version_number
                    : null,
            ],
            'current_plan_listed' => $currentListed,
            'plans' => $cards,
            'rbac_capability_comparison' => \Modules\Subscriptions\Support\SubscriptionRbacCapabilityCatalog::rows(),
            'feature_capability_sections' => \Modules\Subscriptions\Support\SubscriptionFeatureCapabilityCatalog::sections(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminTenantSubscription(Tenant $tenant, bool $includeOverrideHistory = false): array
    {
        $overview = $this->tenantOverview($tenant);
        $history = TenantSubscription::query()->forTenant((int) $tenant->id)
            ->with(['plan', 'version'])
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (TenantSubscription $s) => [
                'id' => $s->id,
                'plan_code' => $s->plan?->code,
                'plan_name' => $s->plan?->name,
                'version_number' => $s->version?->version_number,
                'record_status' => $s->record_status,
                'billing_interval' => $s->billing_interval,
                'contracted_price' => $this->money($s->contracted_price),
                'currency_code' => $s->currency_code,
                'custom_limits' => $s->custom_limits,
                'source' => $s->source,
                'reason' => $s->reason,
                'starts_at' => $s->starts_at?->toIso8601String(),
                'scheduled_for' => $s->scheduled_for?->toIso8601String(),
                'superseded_at' => $s->superseded_at?->toIso8601String(),
                'created_at' => $s->created_at?->toIso8601String(),
            ])->all();

        $payload = [
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name, 'plan_key' => $tenant->plan],
            'history' => $history,
        ];

        if ($includeOverrideHistory) {
            $payload['overrides'] = TenantEntitlementOverride::query()
                ->where('tenant_id', $tenant->id)
                ->with('feature')
                ->orderByDesc('id')
                ->limit(100)
                ->get()
                ->map(fn (TenantEntitlementOverride $o) => app(EntitlementOverrideService::class)->present($o))
                ->all();
        }

        return $overview + $payload;
    }

    /**
     * @param  array<string, mixed>|null  $identity
     * @return array<string, mixed>|null
     */
    private function authoritativePlanSummary(Tenant $tenant, ResolvedEntitlements $resolved, ?array $identity = null): ?array
    {
        $identity ??= $this->currentPlan->resolve($tenant);
        $fromIdentity = $this->currentPlan->planSummary($identity);
        $fromResolver = $this->planSummary($resolved);
        if (($identity['matched'] ?? false) && $fromIdentity) {
            return $fromIdentity;
        }

        if (! empty($fromIdentity['code'])) {
            return $fromIdentity;
        }

        return $fromResolver ?? $fromIdentity;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function planSummary(ResolvedEntitlements $resolved): ?array
    {
        $plan = $resolved->plan;
        if (! $plan) {
            return null;
        }

        return [
            'id' => isset($plan['id']) ? (int) $plan['id'] : null,
            'code' => $plan['code'] ?? null,
            'key' => $plan['key'] ?? null,
            'name' => $plan['name'] ?? ($plan['key'] ?? null),
            'pricing_type' => $plan['pricing_type'] ?? null,
            'is_legacy' => (bool) ($plan['is_legacy'] ?? ($plan['legacy'] ?? false)),
            'version_number' => $plan['version_number'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    private function comparisonVersion(Plan $plan, array $identity, bool $isCurrent): ?PlanVersion
    {
        if ($isCurrent && ($identity['version_id'] ?? null)) {
            $subscribed = PlanVersion::query()->with('entitlements.feature')->find((int) $identity['version_id']);
            if ($subscribed && (int) $subscribed->plan_id === (int) $plan->id) {
                return $subscribed;
            }
        }

        $plan->loadMissing('activeVersion.entitlements.feature');

        return $plan->activeVersion;
    }

    /**
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>
     */
    private function comparisonCard(Plan $plan, PlanVersion $version, array $identity, bool $isCurrent, bool $listedInCatalog, ?Tenant $tenant = null): array
    {
        $isLifetime = $isCurrent && (bool) ($identity['is_lifetime'] ?? false);
        $primaryAction = 'request';
        if ($isCurrent) {
            $primaryAction = 'current';
        } elseif ($plan->pricing_type === Plan::PRICING_CUSTOM) {
            $primaryAction = 'quote';
        }

        $subscribedVersionId = $isCurrent ? ($identity['version_id'] ?? null) : null;
        $activeId = $plan->activeVersion?->id;

        return $this->publicPlan($plan, $version, $tenant) + [
            'id' => (int) $plan->id,
            'is_current' => $isCurrent,
            'listed_in_catalog' => $listedInCatalog,
            'using_subscribed_version' => $isCurrent && $subscribedVersionId && $activeId && (int) $subscribedVersionId !== (int) $activeId,
            'primary_action' => $primaryAction,
            'subscription_status' => $isCurrent ? ($identity['subscription_status'] ?? null) : null,
            'subscription_ends_at' => $isCurrent ? ($identity['subscription_ends_at'] ?? null) : null,
            'is_lifetime' => $isLifetime,
        ];
    }

    /**
     * The decisions the API actually applies in the current rollout mode (plan-driven only when enforcing).
     */
    private function effective(Tenant $tenant, ResolvedEntitlements $resolved): ResolvedEntitlements
    {
        return EntitlementEngineMode::isEnforcing() ? $resolved : app(LegacyEntitlementSource::class)->resolve($tenant);
    }

    private function entitlementsVersion(ResolvedEntitlements $effective): string
    {
        return substr(hash('sha256', EntitlementEngineMode::current().'|'.$effective->hash()), 0, 16);
    }

    private function money(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : MoneyMath::normalize((string) $value);
    }

    /**
     * Public, active features for a plan version with included / limit state (discovery only).
     *
     * @return list<array<string, mixed>>
     */
    private function publicFeatureCoverage(PlanVersion $version): array
    {
        $version->loadMissing('entitlements.feature');
        $byFeature = $version->entitlements->keyBy('feature_id');
        $coverage = [];
        foreach (Feature::query()->active()->where('is_public', true)->ordered()->get() as $feature) {
            $e = $byFeature[$feature->id] ?? null;
            $enabled = $feature->is_core || ($e && $e->is_enabled);
            $row = [
                'code' => $feature->code,
                'name' => $feature->name,
                'description' => $feature->description,
                'category' => $feature->category,
                'feature_type' => $feature->feature_type,
                'unit' => $feature->unit,
                'display_order' => $feature->display_order,
                'is_core' => (bool) $feature->is_core,
                'included' => $enabled,
            ];
            if ($feature->isNumeric()) {
                $row['value'] = $enabled ? $e?->numeric_value : null;
                $row['unlimited'] = $enabled && $e && $e->numeric_value === null;
            } else {
                $row['value'] = null;
                $row['unlimited'] = false;
            }
            $coverage[] = $row;
        }

        return $coverage;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function limitSummary(PlanVersion $version): array
    {
        $version->loadMissing('entitlements.feature');
        $rows = [];
        foreach ($version->entitlements as $e) {
            $f = $e->feature;
            if (! $f || ! $f->is_active || ! $f->is_public || ! $f->isNumeric() || ! $e->is_enabled) {
                continue;
            }
            $rows[] = [
                'code' => $f->code,
                'name' => $f->name,
                'unit' => $f->unit,
                'value' => $e->numeric_value,
                'unlimited' => $e->numeric_value === null,
                'display_order' => $f->display_order,
            ];
        }
        usort($rows, static fn ($a, $b) => ($a['display_order'] ?? 0) <=> ($b['display_order'] ?? 0));

        return array_values(array_slice($rows, 0, 4));
    }
}
