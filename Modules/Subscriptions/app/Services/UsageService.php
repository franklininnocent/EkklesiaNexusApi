<?php

namespace Modules\Subscriptions\Services;

use Modules\Subscriptions\Services\Entitlements\EntitlementCatalog;
use Modules\Subscriptions\Services\Entitlements\ResolvedEntitlements;
use Modules\Tenants\Support\Usage\StorageUsageProvider;
use Modules\Tenants\Support\UsageMetricRegistry;

/**
 * Current tenant usage for limit features, measured by providers registered by owning modules.
 */
class UsageService
{
    public const LEVEL_OK = 'ok';

    public const LEVEL_NOTICE = 'notice';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_CRITICAL = 'critical';

    public const LEVEL_AT_LIMIT = 'at_limit';

    public const LEVEL_OVER_LIMIT = 'over_limit';

    public function __construct(
        private readonly UsageMetricRegistry $registry,
        private readonly EntitlementCatalog $catalog,
        private readonly SubscriptionPolicyService $policies,
    ) {}

    public function isMeasurable(string $code): bool
    {
        return $this->registry->has($code);
    }

    public function current(int $tenantId, string $code): ?int
    {
        $provider = $this->registry->get($code);

        return $provider ? max(0, $provider->currentUsage($tenantId)) : null;
    }

    public function currentFresh(int $tenantId, string $code): ?int
    {
        $provider = $this->registry->get($code);
        if (! $provider) {
            return null;
        }
        if ($provider instanceof StorageUsageProvider) {
            return max(0, $provider->currentUsageUncached($tenantId));
        }

        return max(0, $provider->currentUsage($tenantId));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function summary(ResolvedEntitlements $resolved, ?ResolvedEntitlements $planResolved = null): array
    {
        $rows = [];
        foreach ($this->catalog->features() as $code => $feature) {
            if (! in_array($feature['type'], ['LIMIT', 'QUOTA', 'USAGE'], true) || ! $this->isMeasurable($code)) {
                continue;
            }
            $limit = $resolved->limit($code);
            $usage = (int) $this->current($resolved->tenantId, $code);
            $rows[] = $this->row($code, $feature, $usage, $limit, $resolved, $planResolved);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $feature
     * @return array<string, mixed>
     */
    public function row(
        string $code,
        array $feature,
        int $usage,
        ?int $limit,
        ?ResolvedEntitlements $resolved = null,
        ?ResolvedEntitlements $planResolved = null,
    ): array {
        $unlimited = $resolved?->limitIsUnlimited($code) ?? ($limit === null);
        $planLimit = $planResolved?->limit($code);
        $planUnlimited = $planResolved?->limitIsUnlimited($code) ?? false;
        $effectiveLimit = $unlimited ? null : $limit;
        $percent = $effectiveLimit !== null && $effectiveLimit > 0
            ? round(($usage / $effectiveLimit) * 100, 1)
            : ($effectiveLimit === 0 && $usage > 0 ? 100.0 : null);

        $hasException = $planResolved !== null
            && ($planUnlimited !== $unlimited || $planLimit !== $effectiveLimit);

        return [
            'code' => $code,
            'name' => $feature['name'],
            'unit' => $feature['unit'],
            'usage' => $usage,
            'limit' => $effectiveLimit,
            'plan_limit' => $planUnlimited ? null : $planLimit,
            'plan_unlimited' => $planUnlimited,
            'effective_source' => $resolved?->limitSource($code),
            'has_tenant_allowance_exception' => $hasException,
            'unlimited' => $unlimited,
            'remaining' => $unlimited ? null : max(0, (int) $effectiveLimit - $usage),
            'percent_used' => $percent,
            'level' => $this->level($usage, $effectiveLimit),
        ];
    }

    public function level(int $usage, ?int $limit): string
    {
        if ($limit === null) {
            return self::LEVEL_OK;
        }
        if ($usage > $limit) {
            return self::LEVEL_OVER_LIMIT;
        }
        if ($usage === $limit) {
            return self::LEVEL_AT_LIMIT;
        }
        if ($limit === 0) {
            return self::LEVEL_OK;
        }

        $percent = ($usage / $limit) * 100;
        $thresholds = array_values(array_filter(
            array_map('intval', (array) $this->policies->get('usage_thresholds', [70, 85, 95, 100])),
            static fn (int $t) => $t < 100
        ));
        sort($thresholds);
        $levels = [self::LEVEL_NOTICE, self::LEVEL_WARNING, self::LEVEL_CRITICAL];

        $reached = self::LEVEL_OK;
        foreach ($thresholds as $i => $threshold) {
            if ($percent >= $threshold) {
                $reached = $levels[min($i, count($levels) - 1)];
            }
        }

        return $reached;
    }
}
