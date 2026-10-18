<?php

namespace Modules\Marketing\Support\SeoMonitoring;

final class MarketingSeoCheckRecord
{
    /**
     * @return array<string, mixed>
     */
    public static function make(
        string $checkId,
        string $source,
        string $severity,
        string $status,
        string $message,
        ?string $checkedAt = null,
        string $evidence = '',
        string $remediation = '',
        ?string $url = null,
        string $freshness = 'unknown',
    ): array {
        return [
            'check_id' => $checkId,
            'source' => $source,
            'severity' => $severity,
            'status' => $status,
            'checked_at' => $checkedAt,
            'message' => $message,
            'evidence' => $evidence,
            'remediation' => $remediation,
            'url' => $url,
            'freshness' => $freshness,
        ];
    }

    /**
     * @param  array<string, mixed>  $check
     */
    public static function findingId(array $check): string
    {
        $url = isset($check['url']) && is_string($check['url']) ? $check['url'] : '';

        return hash('sha256', ($check['check_id'] ?? '').'|'.($check['source'] ?? '').'|'.$url);
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     * @return list<array<string, mixed>>
     */
    public static function toFindings(array $checks): array
    {
        $findings = [];
        foreach ($checks as $check) {
            $status = (string) ($check['status'] ?? '');
            if (! in_array($status, ['failed', 'error'], true)) {
                continue;
            }
            $findings[] = array_merge($check, [
                'finding_id' => self::findingId($check),
            ]);
        }

        return $findings;
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     * @return array<string, int>
     */
    public static function summarizeBySource(array $checks, string $source): array
    {
        $summary = [
            'passed' => 0,
            'failed' => 0,
            'not_run' => 0,
            'error' => 0,
        ];

        foreach ($checks as $check) {
            if (($check['source'] ?? '') !== $source) {
                continue;
            }
            $status = (string) ($check['status'] ?? '');
            if (array_key_exists($status, $summary)) {
                $summary[$status]++;
            }
        }

        return $summary;
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @return array{critical: int, warning: int, info: int}
     */
    public static function openFindingsBySeverity(array $findings): array
    {
        $counts = ['critical' => 0, 'warning' => 0, 'info' => 0];
        foreach ($findings as $finding) {
            $severity = (string) ($finding['severity'] ?? 'info');
            if (isset($counts[$severity])) {
                $counts[$severity]++;
            }
        }

        return $counts;
    }
}
