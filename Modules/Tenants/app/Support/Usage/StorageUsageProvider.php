<?php

namespace Modules\Tenants\Support\Usage;

use Illuminate\Support\Facades\Cache;
use Modules\Tenants\Contracts\UsageMetricProvider;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\TenantUsageService;

/**
 * Storage used in MB (rounded up). The filesystem scan is cached briefly because it is expensive.
 */
final class StorageUsageProvider implements UsageMetricProvider
{
    public function metricCode(): string
    {
        return 'STORAGE_MB';
    }

    public function currentUsage(int $tenantId): int
    {
        return (int) Cache::remember("tenant:{$tenantId}:storage_used_mb", 600, fn (): int => $this->measureMb($tenantId));
    }

    public function currentUsageUncached(int $tenantId): int
    {
        return $this->measureMb($tenantId);
    }

    private function measureMb(int $tenantId): int
    {
        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant) {
            return 0;
        }
        $measured = app(TenantUsageService::class)->measureStorage($tenant);

        return (int) ceil((float) ($measured['used_mb'] ?? 0));
    }

    public static function forget(int $tenantId): void
    {
        Cache::forget("tenant:{$tenantId}:storage_used_mb");
    }
}
