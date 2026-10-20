<?php

namespace Modules\Marketing\Services;

use Modules\Marketing\Support\SeoMonitoring\MarketingSeoCheckParser;
use Modules\Marketing\Support\SeoMonitoring\MarketingSeoCheckRecord;
use Modules\Marketing\Support\SeoMonitoring\MarketingSeoMonitoringConfig;
use Modules\Marketing\Support\SeoMonitoring\MarketingSeoSafeHttpProbe;
use Modules\Marketing\Support\SeoMonitoring\MarketingSeoUrlPolicy;

class MarketingSeoProductionProbeService
{
    public function __construct(
        private readonly MarketingSeoMonitoringConfig $config,
        private readonly MarketingSeoUrlPolicy $urlPolicy,
        private readonly MarketingSeoSafeHttpProbe $probe,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function run(?string $checkedAt = null): array
    {
        $checkedAt ??= now()->toIso8601String();
        $checks = [];
        $maxSitemapBytes = (int) config('marketing.seo-monitoring.max_body_bytes_sitemap');

        foreach ($this->config->primaryPaths() as $path) {
            $url = $this->config->urlForPath($path);
            $result = $this->probe->fetch($url);
            $checks = array_merge($checks, $this->mapHttpPathCheck($path, $url, $result, $checkedAt));
            if ($result['status'] >= 200 && $result['status'] < 400 && $result['error'] === null) {
                $sniff = MarketingSeoCheckParser::sniffHtmlSeo(
                    substr($result['body'], 0, 120_000),
                    $url,
                    $this->config,
                    $this->urlPolicy,
                );
                foreach ($sniff as $row) {
                    $checks[] = $this->fromSniffRow($row, $checkedAt);
                }
            }
        }

        $robotsUrl = $this->config->baseUrl().'/robots.txt';
        $robots = $this->probe->fetch($robotsUrl);
        $checks[] = $this->mapSimpleHttpCheck(
            'robots-txt',
            'robots.txt',
            $robotsUrl,
            $robots,
            $checkedAt,
            true,
        );

        if ($robots['status'] === 200 && $robots['error'] === null) {
            $sitemapFromRobots = MarketingSeoCheckParser::parseRobotsSitemapUrl($robots['body']);
            $sitemapUrl = $sitemapFromRobots ?? $this->config->baseUrl().'/sitemap.xml';
            if ($sitemapFromRobots !== null && ! $this->urlPolicy->isAllowedMonitoringUrl($sitemapUrl)) {
                $checks[] = MarketingSeoCheckRecord::make(
                    checkId: 'sitemap-from-robots',
                    source: 'production_http',
                    severity: 'critical',
                    status: 'error',
                    message: 'robots.txt sitemap URL not allowlisted',
                    checkedAt: $checkedAt,
                    evidence: 'sitemap='.$sitemapUrl,
                    remediation: 'Point robots.txt Sitemap directive to the apex HTTPS sitemap.',
                    url: $robotsUrl,
                );
            } else {
                $sitemap = $this->probe->fetch($sitemapUrl, false, $maxSitemapBytes);
                $checks[] = $this->mapSimpleHttpCheck(
                    'sitemap-http',
                    'Sitemap HTTP',
                    $sitemapUrl,
                    $sitemap,
                    $checkedAt,
                    true,
                );
                if ($sitemap['status'] === 200 && $sitemap['error'] === null) {
                    foreach (MarketingSeoCheckParser::validateSitemapXml($sitemap['body'], $this->config, $this->urlPolicy) as $row) {
                        $checks[] = MarketingSeoCheckRecord::make(
                            checkId: (string) $row['check_id'],
                            source: 'production_http',
                            severity: (string) $row['severity'],
                            status: $row['passed'] ? 'passed' : 'failed',
                            message: (string) $row['message'],
                            checkedAt: $checkedAt,
                            evidence: (string) $row['evidence'],
                            remediation: 'Fix sitemap URLs on the live marketing site.',
                            url: $sitemapUrl,
                        );
                    }
                }
            }
        }

        $gscUrl = $this->config->gscVerificationUrl();
        $gsc = $this->probe->fetch($gscUrl);
        $bodyOk = str_contains($gsc['body'], 'google-site-verification:');
        $checks[] = MarketingSeoCheckRecord::make(
            checkId: 'gsc-file-live',
            source: 'production_http',
            severity: 'critical',
            status: $this->httpCheckStatus($gsc, $gsc['status'] === 200 && $bodyOk),
            message: 'GSC verification file (production)',
            checkedAt: $checkedAt,
            evidence: 'status='.$gsc['status'].($gsc['status'] !== 200 ? ' — deploy public/google161c1efd34d8a358.html' : ''),
            remediation: 'Deploy the GSC HTML verification file to the marketing site public folder.',
            url: $gscUrl,
        );

        foreach ($this->config->leakUrls() as $leakUrl) {
            $leak = $this->probe->fetch($leakUrl, true);
            $leaked = $leak['status'] === 200
                && $leak['error'] === null
                && MarketingSeoCheckParser::isMarketingLeakBody(substr($leak['body'], 0, 4000));
            $checks[] = MarketingSeoCheckRecord::make(
                checkId: 'leak-'.$leakUrl,
                source: 'production_http',
                severity: $leaked ? 'warning' : 'info',
                status: $leaked ? 'failed' : 'passed',
                message: 'Host isolation '.$leakUrl,
                checkedAt: $checkedAt,
                evidence: $leaked ? 'marketing HTML on non-canonical host' : 'status='.$leak['status'].' OK',
                remediation: 'Ensure alternate hosts do not serve marketing HTML (Apache catch-all).',
                url: $leakUrl,
            );
        }

        return $checks;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function notRunPlaceholders(?string $checkedAt = null): array
    {
        $checkedAt ??= now()->toIso8601String();
        $checks = [];

        foreach ($this->config->primaryPaths() as $path) {
            $url = $this->config->urlForPath($path);
            $checks[] = MarketingSeoCheckRecord::make(
                checkId: 'http-'.$path,
                source: 'production_http',
                severity: 'none',
                status: 'not_run',
                message: 'HTTP '.$path.' (live)',
                checkedAt: null,
                evidence: 'Enable live production HTTP checks to verify this endpoint.',
                url: $url,
            );
        }

        foreach ($this->config->productionCheckStubs() as $stub) {
            $checks[] = MarketingSeoCheckRecord::make(
                checkId: (string) $stub['check_id'],
                source: 'production_http',
                severity: 'none',
                status: 'not_run',
                message: (string) $stub['message'],
                checkedAt: null,
                evidence: 'Production HTTP checks were not included in this scan.',
            );
        }

        foreach ($this->config->leakUrls() as $leakUrl) {
            $checks[] = MarketingSeoCheckRecord::make(
                checkId: 'leak-'.$leakUrl,
                source: 'production_http',
                severity: 'none',
                status: 'not_run',
                message: 'Host isolation '.$leakUrl,
                checkedAt: null,
                url: $leakUrl,
            );
        }

        return $checks;
    }

    /**
     * @param  array{status: int, body: string, final_url: string, error: ?string, security_rejection: bool}  $result
     * @return list<array<string, mixed>>
     */
    private function mapHttpPathCheck(string $path, string $url, array $result, string $checkedAt): array
    {
        $ok = $result['status'] >= 200 && $result['status'] < 400 && $result['error'] === null;

        return [
            MarketingSeoCheckRecord::make(
                checkId: 'http-'.$path,
                source: 'production_http',
                severity: 'critical',
                status: $this->httpCheckStatus($result, $ok),
                message: 'HTTP '.$path,
                checkedAt: $checkedAt,
                evidence: 'status='.$result['status'].' final='.$result['final_url'],
                remediation: $result['status'] === 404
                    ? 'Ensure this public route returns a success response on production.'
                    : 'Investigate production availability for this URL.',
                url: $url,
            ),
        ];
    }

    /**
     * @param  array{status: int, body: string, final_url: string, error: ?string, security_rejection: bool}  $result
     */
    private function mapSimpleHttpCheck(
        string $checkId,
        string $label,
        string $url,
        array $result,
        string $checkedAt,
        bool $twoHundredRequired,
    ): array {
        $ok = $result['status'] === 200 && $result['error'] === null;

        return MarketingSeoCheckRecord::make(
            checkId: $checkId,
            source: 'production_http',
            severity: 'critical',
            status: $this->httpCheckStatus($result, ! $twoHundredRequired || $ok),
            message: $label,
            checkedAt: $checkedAt,
            evidence: 'url='.$url.' status='.$result['status'],
            remediation: 'Verify this asset is deployed and reachable on production.',
            url: $url,
        );
    }

    /**
     * @param  array{status: int, body: string, final_url: string, error: ?string, security_rejection: bool}  $result
     */
    private function httpCheckStatus(array $result, bool $passedCondition): string
    {
        if ($result['security_rejection'] ?? false) {
            return 'error';
        }
        if (($result['error'] ?? null) !== null && ($result['status'] ?? 0) === 0) {
            return 'error';
        }
        if (($result['status'] ?? 0) === 404) {
            return 'failed';
        }
        if (($result['status'] ?? 0) >= 500 || ($result['status'] ?? 0) === 429) {
            return 'error';
        }
        if ($passedCondition) {
            return 'passed';
        }

        return 'failed';
    }

    /**
     * @param  array{check_id: string, passed: bool, severity: string, message: string, evidence: string, url: string}  $row
     * @return array<string, mixed>
     */
    private function fromSniffRow(array $row, string $checkedAt): array
    {
        return MarketingSeoCheckRecord::make(
            checkId: (string) $row['check_id'],
            source: 'production_http',
            severity: (string) $row['severity'],
            status: $row['passed'] ? 'passed' : 'failed',
            message: (string) $row['message'],
            checkedAt: $checkedAt,
            evidence: (string) $row['evidence'],
            remediation: 'Update live page metadata on the marketing site deployment.',
            url: (string) $row['url'],
        );
    }
}
