<?php

namespace Modules\Marketing\Support\SeoMonitoring;

/**
 * Parsing helpers aligned with EkklesiaNexusWeb/src/lib/seo-monitoring/checks.ts
 */
final class MarketingSeoCheckParser
{
    public static function parseRobotsSitemapUrl(string $robotsText): ?string
    {
        $lines = preg_split('/\r\n|\r|\n/', $robotsText) ?: [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (! preg_match('/^sitemap:/i', $trimmed)) {
                continue;
            }
            $url = trim((string) preg_replace('/^sitemap:\s*/i', '', $trimmed));
            if ($url !== '') {
                return $url;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function extractLocsFromSitemapXml(string $xml): array
    {
        $locs = [];
        if (preg_match_all('/<loc>([^<]+)<\/loc>/i', $xml, $matches)) {
            foreach ($matches[1] as $loc) {
                $locs[] = trim($loc);
            }
        }

        return $locs;
    }

    /**
     * @return list<array{check_id: string, passed: bool, severity: string, message: string, evidence: string}>
     */
    public static function validateSitemapXml(
        string $xml,
        MarketingSeoMonitoringConfig $config,
        MarketingSeoUrlPolicy $urlPolicy,
    ): array {
        $results = [];
        $baseUrl = $config->baseUrl();
        $expected = $config->expectedSitemapLocCount();
        $apex = $config->apexHost();

        if (! str_contains($xml, '<urlset') && ! str_contains($xml, ':urlset')) {
            $results[] = [
                'check_id' => 'sitemap-xml',
                'passed' => false,
                'severity' => 'critical',
                'message' => 'Sitemap XML structure',
                'evidence' => 'Response does not look like a sitemap urlset',
            ];

            return $results;
        }

        $locs = self::extractLocsFromSitemapXml($xml);
        $countOk = count($locs) === $expected;
        $results[] = [
            'check_id' => 'sitemap-loc-count',
            'passed' => $countOk,
            'severity' => 'critical',
            'message' => 'Sitemap loc count',
            'evidence' => $countOk
                ? 'loc_count='.count($locs)
                : "expected {$expected}, got ".count($locs),
        ];

        $hostBad = [];
        $forbidden = [];
        foreach ($locs as $u) {
            if (! str_starts_with($u, $baseUrl.'/') && $u !== $baseUrl.'/' && ! str_starts_with($u, $baseUrl)) {
                $hostBad[] = $u;
            }
            try {
                $host = parse_url($u, PHP_URL_HOST);
                if (is_string($host) && $host !== $apex) {
                    $hostBad[] = $u;
                }
            } catch (\Throwable) {
                $hostBad[] = $u;
            }
            if (preg_match('/\/es\b|localhost|127\.0\.0\.1|\/api\/|\/preview\//i', $u)) {
                $forbidden[] = $u;
            }
        }

        $allHostOk = $hostBad === [] && $forbidden === [];
        $invalid = array_slice(array_merge($hostBad, $forbidden), 0, 3);
        $results[] = [
            'check_id' => 'sitemap-loc-hosts',
            'passed' => $allHostOk,
            'severity' => 'critical',
            'message' => 'Sitemap URL host policy',
            'evidence' => $allHostOk
                ? 'All loc URLs use apex HTTPS'
                : 'invalid: '.implode(', ', $invalid),
        ];

        return $results;
    }

    /**
     * @return list<array{check_id: string, passed: bool, severity: string, message: string, evidence: string, url: string}>
     */
    public static function sniffHtmlSeo(
        string $html,
        string $url,
        MarketingSeoMonitoringConfig $config,
        MarketingSeoUrlPolicy $urlPolicy,
    ): array {
        $results = [];
        $baseUrl = $config->baseUrl();

        $canon = '';
        if (preg_match('/rel="canonical"\s+href="([^"]+)"/i', $html, $m)) {
            $canon = $m[1];
        } elseif (preg_match('/href="([^"]+)"\s+rel="canonical"/i', $html, $m)) {
            $canon = $m[1];
        }

        $robots = '';
        if (preg_match('/name="robots"\s+content="([^"]+)"/i', $html, $m)) {
            $robots = $m[1];
        }

        $title = '';
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $m)) {
            $title = trim($m[1]);
        }

        if ($canon !== '' && ! $urlPolicy->isAllowedMonitoringUrl($canon) && ! str_starts_with($canon, $baseUrl)) {
            $results[] = [
                'check_id' => 'canonical-'.$url,
                'passed' => false,
                'severity' => 'warning',
                'message' => "Canonical host ({$url})",
                'evidence' => 'canonical='.$canon,
                'url' => $url,
            ];
        } elseif ($canon !== '') {
            $results[] = [
                'check_id' => 'canonical-'.$url,
                'passed' => true,
                'severity' => 'info',
                'message' => "Canonical present ({$url})",
                'evidence' => $canon,
                'url' => $url,
            ];
        }

        if (preg_match('/noindex/i', $robots)) {
            $isHome = $url === $baseUrl.'/' || str_ends_with($url, '/pt-br');
            if ($isHome) {
                $results[] = [
                    'check_id' => 'robots-'.$url,
                    'passed' => false,
                    'severity' => 'critical',
                    'message' => "Indexability ({$url})",
                    'evidence' => 'robots='.$robots,
                    'url' => $url,
                ];
            }
        }

        if ($title !== '') {
            $results[] = [
                'check_id' => 'title-'.$url,
                'passed' => true,
                'severity' => 'info',
                'message' => "Document title ({$url})",
                'evidence' => mb_substr($title, 0, 120),
                'url' => $url,
            ];
        }

        return $results;
    }

    public static function isMarketingLeakBody(string $htmlSnippet): bool
    {
        return str_contains($htmlSnippet, 'EkklesiaNexus');
    }
}
