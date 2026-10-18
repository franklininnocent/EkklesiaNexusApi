<?php

namespace Modules\Marketing\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Modules\Marketing\Support\SeoMonitoring\MarketingSeoCheckRecord;
use Modules\Marketing\Support\SeoMonitoring\MarketingSeoMonitoringConfig;

class MarketingSeoMonitoringService
{
    public function __construct(
        private readonly MarketingSeoMonitoringConfig $config,
        private readonly MarketingSeoCmsAuditService $cmsAudit,
        private readonly MarketingSeoProductionProbeService $productionProbe,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getSnapshot(): array
    {
        $cms = $this->readSnapshot('snapshot_cms');
        $production = $this->readSnapshot('snapshot_production');
        $lastFailed = Cache::get($this->config->cacheKey('last_failed'));

        return [
            'schema_version' => $this->config->schemaVersion(),
            'snapshot_label' => 'latest_snapshot',
            'domain' => $cms['domain'] ?? $production['domain'] ?? $this->config->baseUrl(),
            'config_fingerprint' => $this->config->configFingerprint(),
            'published_content_fingerprint' => $cms['published_content_fingerprint'] ?? null,
            'cms' => $this->presentSnapshotSection($cms),
            'production' => $production !== null
                ? $this->presentSnapshotSection($production, true)
                : [
                    'never_run' => true,
                    'last_successful_scan_at' => null,
                    'completed_at' => null,
                    'summary' => [
                        'passed' => 0,
                        'failed' => 0,
                        'not_run' => 0,
                        'error' => 0,
                    ],
                    'checks' => [],
                    'findings' => [],
                    'freshness' => 'unknown',
                ],
            'last_failed_attempt' => is_array($lastFailed) ? $this->sanitizeFailedAttempt($lastFailed) : null,
            'snapshot_unavailable' => $cms === null && $production === null,
        ];
    }

    /**
     * @return array{data: array<string, mixed>|null, conflict: bool, status: int}
     */
    public function runScan(User $actor, bool $includeProduction): array
    {
        $scanId = (string) Str::uuid();
        $lock = Cache::lock($this->config->cacheKey('lock'), $this->config->lockLeaseSeconds());

        if (! $lock->get()) {
            $active = Cache::get($this->config->cacheKey('active_scan'));

            return [
                'data' => is_array($active) ? $active : ['scan_id' => null, 'status' => 'running'],
                'conflict' => true,
                'status' => 409,
            ];
        }

        $startedAt = now()->toIso8601String();
        $scanKind = $includeProduction ? 'cms_and_production' : 'cms';

        Cache::put($this->config->cacheKey('active_scan'), [
            'scan_id' => $scanId,
            'status' => 'running',
            'scan_kind' => $scanKind,
            'started_at' => $startedAt,
            'triggered_by_user_id' => $actor->id,
        ], $this->config->lockLeaseSeconds());

        try {
            $deadline = microtime(true) + $this->config->scanMaxWallSeconds();

            $cmsResult = $this->cmsAudit->audit($startedAt);
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('Scan exceeded maximum wall time.');
            }

            $cmsChecks = $cmsResult['checks'];
            $productionChecks = $includeProduction
                ? $this->productionProbe->run($startedAt)
                : $this->productionProbe->notRunPlaceholders();

            $checks = array_merge($cmsChecks, $productionChecks);
            $findings = MarketingSeoCheckRecord::toFindings($checks);
            $completedAt = now()->toIso8601String();

            $cmsSnapshot = $this->buildSnapshotEnvelope(
                $scanId,
                'cms',
                $cmsChecks,
                $findings,
                $startedAt,
                $completedAt,
                $actor->id,
                $cmsResult['fingerprint'],
                $cmsResult['domain'],
                'completed',
            );

            $productionSnapshot = null;
            if ($includeProduction) {
                $productionFindings = MarketingSeoCheckRecord::toFindings($productionChecks);
                $prodStatus = $this->resolveScanStatus($productionFindings);
                $productionSnapshot = $this->buildSnapshotEnvelope(
                    $scanId,
                    'production_http',
                    $productionChecks,
                    $productionFindings,
                    $startedAt,
                    $completedAt,
                    $actor->id,
                    $cmsResult['fingerprint'],
                    $cmsResult['domain'],
                    $prodStatus,
                );
            }

            $this->writeSnapshot('snapshot_cms', $cmsSnapshot);
            if ($productionSnapshot !== null) {
                $this->writeSnapshot('snapshot_production', $productionSnapshot);
            }

            $combinedFindings = MarketingSeoCheckRecord::toFindings($checks);
            $response = [
                'scan_id' => $scanId,
                'scan_kind' => $scanKind,
                'status' => $this->resolveScanStatus($combinedFindings),
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
                'include_production' => $includeProduction,
                'checks' => $checks,
                'findings' => $combinedFindings,
                'summary' => $this->buildSummary($checks, $combinedFindings),
                'cms_snapshot' => $cmsSnapshot,
                'production_snapshot' => $productionSnapshot,
            ];

            Cache::forget($this->config->cacheKey('active_scan'));

            return ['data' => $response, 'conflict' => false, 'status' => 200];
        } catch (\Throwable $e) {
            $failed = [
                'scan_id' => $scanId,
                'scan_kind' => $scanKind,
                'status' => 'failed',
                'started_at' => $startedAt,
                'completed_at' => now()->toIso8601String(),
                'triggered_by_user_id' => $actor->id,
                'error_summary' => 'Scan did not complete. '.$e->getMessage(),
            ];
            Cache::put($this->config->cacheKey('last_failed'), $failed, $this->config->snapshotTtlSeconds());

            return [
                'data' => $failed,
                'conflict' => false,
                'status' => 500,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     * @param  list<array<string, mixed>>  $findings
     * @return array<string, mixed>
     */
    private function buildSnapshotEnvelope(
        string $scanId,
        string $section,
        array $checks,
        array $findings,
        string $startedAt,
        string $completedAt,
        int $userId,
        ?string $fingerprint,
        string $domain,
        string $status,
    ): array {
        $source = $section === 'cms' ? 'cms' : 'production_http';
        $filteredChecks = array_values(array_filter(
            $checks,
            static fn (array $c): bool => ($c['source'] ?? '') === $source,
        ));

        $sectionFindings = MarketingSeoCheckRecord::toFindings($filteredChecks);

        return [
            'scan_id' => $scanId,
            'scan_kind' => $section === 'cms' ? 'cms' : 'production_http',
            'status' => $status,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'triggered_by_user_id' => $userId,
            'config_fingerprint' => $this->config->configFingerprint(),
            'published_content_fingerprint' => $fingerprint,
            'domain' => $domain,
            'checks' => $this->applyFreshness($filteredChecks, $completedAt),
            'findings' => $this->applyFreshness($sectionFindings, $completedAt),
            'summary' => $this->buildSummary($filteredChecks, $sectionFindings),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     * @param  list<array<string, mixed>>  $findings
     * @return array<string, mixed>
     */
    private function buildSummary(array $checks, array $findings): array
    {
        return [
            'cms' => MarketingSeoCheckRecord::summarizeBySource($checks, 'cms'),
            'production_http' => MarketingSeoCheckRecord::summarizeBySource($checks, 'production_http'),
            'open_findings_by_severity' => MarketingSeoCheckRecord::openFindingsBySeverity($findings),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     */
    private function resolveScanStatus(array $findings): string
    {
        $hasError = false;
        foreach ($findings as $finding) {
            if (($finding['status'] ?? '') === 'error') {
                $hasError = true;
            }
        }

        if ($hasError) {
            return 'completed_with_errors';
        }

        return 'completed';
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function writeSnapshot(string $keyName, array $snapshot): void
    {
        Cache::put(
            $this->config->cacheKey($keyName),
            $snapshot,
            $this->config->snapshotTtlSeconds(),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readSnapshot(string $keyName): ?array
    {
        $value = Cache::get($this->config->cacheKey($keyName));

        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, mixed>|null
     */
    private function presentSnapshotSection(?array $snapshot, bool $production = false): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        $completedAt = (string) ($snapshot['completed_at'] ?? '');
        $checks = is_array($snapshot['checks'] ?? null) ? $snapshot['checks'] : [];
        $findings = is_array($snapshot['findings'] ?? null) ? $snapshot['findings'] : [];

        return [
            'scan_id' => $snapshot['scan_id'] ?? null,
            'status' => $snapshot['status'] ?? null,
            'completed_at' => $completedAt !== '' ? $completedAt : null,
            'last_successful_scan_at' => $completedAt !== '' ? $completedAt : null,
            'never_run' => false,
            'published_content_fingerprint' => $snapshot['published_content_fingerprint'] ?? null,
            'summary' => $snapshot['summary'] ?? $this->buildSummary($checks, $findings),
            'checks' => $this->applyFreshness($checks, $completedAt),
            'findings' => $this->applyFreshness($findings, $completedAt),
            'freshness' => $this->freshnessLabel($completedAt),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function applyFreshness(array $rows, string $completedAt): array
    {
        $freshness = $this->freshnessLabel($completedAt);
        foreach ($rows as &$row) {
            $row['freshness'] = $freshness;
        }

        return $rows;
    }

    private function freshnessLabel(string $completedAt): string
    {
        if ($completedAt === '') {
            return 'unknown';
        }
        $completed = strtotime($completedAt);
        if ($completed === false) {
            return 'unknown';
        }
        if (time() - $completed > $this->config->staleAfterSeconds()) {
            return 'stale';
        }

        return 'fresh';
    }

    /**
     * @param  array<string, mixed>  $failed
     * @return array<string, mixed>
     */
    private function sanitizeFailedAttempt(array $failed): array
    {
        return [
            'scan_id' => $failed['scan_id'] ?? null,
            'status' => $failed['status'] ?? 'failed',
            'started_at' => $failed['started_at'] ?? null,
            'completed_at' => $failed['completed_at'] ?? null,
            'error_summary' => $failed['error_summary'] ?? 'Scan failed.',
        ];
    }
}
