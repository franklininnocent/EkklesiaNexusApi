<?php

namespace Modules\Marketing\Support\SeoMonitoring;

final class MarketingSeoMonitoringConfig
{
    public function schemaVersion(): int
    {
        return (int) config('marketing.seo-monitoring.schema_version', 1);
    }

    public function apexHost(): string
    {
        return (string) config('marketing.seo-monitoring.apex_host');
    }

    public function baseUrl(): string
    {
        return rtrim((string) config('marketing.seo-monitoring.base_url'), '/');
    }

    /** @return list<string> */
    public function primaryPaths(): array
    {
        return array_values(config('marketing.seo-monitoring.primary_paths', []));
    }

    /** @return list<string> */
    public function leakUrls(): array
    {
        return array_values(config('marketing.seo-monitoring.leak_urls', []));
    }

    public function gscVerificationUrl(): string
    {
        $path = (string) config('marketing.seo-monitoring.gsc_verification_path');

        return $this->baseUrl().$path;
    }

    public function expectedSitemapLocCount(): int
    {
        return (int) config('marketing.seo-monitoring.expected_sitemap_loc_count');
    }

    public function userAgent(): string
    {
        return (string) config('marketing.seo-monitoring.user_agent');
    }

    public function configFingerprint(): string
    {
        $payload = json_encode([
            'apex' => $this->apexHost(),
            'paths' => $this->primaryPaths(),
            'leaks' => $this->leakUrls(),
            'sitemap_count' => $this->expectedSitemapLocCount(),
        ]);

        return hash('sha256', $payload !== false ? $payload : '');
    }

    public function urlForPath(string $path): string
    {
        if ($path === '/') {
            return $this->baseUrl().'/';
        }

        $normalized = str_starts_with($path, '/') ? $path : '/'.$path;

        return $this->baseUrl().$normalized;
    }

    public function cacheKey(string $name): string
    {
        return (string) config('marketing.seo-monitoring.cache_keys.'.$name);
    }

    public function snapshotTtlSeconds(): int
    {
        return (int) config('marketing.seo-monitoring.snapshot_ttl_seconds');
    }

    public function staleAfterSeconds(): int
    {
        return (int) config('marketing.seo-monitoring.stale_after_seconds');
    }

    public function lockLeaseSeconds(): int
    {
        return (int) config('marketing.seo-monitoring.lock_lease_seconds');
    }

    public function scanMaxWallSeconds(): int
    {
        return (int) config('marketing.seo-monitoring.scan_max_wall_seconds');
    }

    /** @return list<array{check_id: string, message: string}> */
    public function productionCheckStubs(): array
    {
        return array_values(config('marketing.seo-monitoring.production_check_stubs', []));
    }
}
