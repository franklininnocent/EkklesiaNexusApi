<?php

namespace Modules\Marketing\Services;

use Modules\Marketing\Support\SeoMonitoring\MarketingSeoCheckRecord;
use Modules\Marketing\Support\SeoMonitoring\MarketingSeoMonitoringConfig;

class MarketingSeoCmsAuditService
{
    /** @var list<string> */
    private const PAGE_IDS = [
        'home', 'privacy', 'terms', 'features', 'about', 'contact', 'solutionsCatholicParishes',
    ];

    /** @var array<string, array{path: string, index_locked: bool}> */
    private const PAGE_META = [
        'home' => ['path' => '/', 'index_locked' => false],
        'privacy' => ['path' => '/privacy', 'index_locked' => true],
        'terms' => ['path' => '/terms', 'index_locked' => true],
        'features' => ['path' => '/features', 'index_locked' => false],
        'about' => ['path' => '/about', 'index_locked' => false],
        'contact' => ['path' => '/contact', 'index_locked' => false],
        'solutionsCatholicParishes' => ['path' => '/solutions/catholic-parishes', 'index_locked' => false],
    ];

    public function __construct(
        private readonly MarketingSeoMonitoringConfig $monitoringConfig,
        private readonly MarketingContentService $contentService,
    ) {
    }

    /**
     * @return array{checks: list<array<string, mixed>>, fingerprint: ?string, domain: string, revision_id: ?int}
     */
    public function audit(?string $checkedAt = null): array
    {
        $checkedAt ??= now()->toIso8601String();
        $revision = $this->contentService->getPublishedRevision();
        $payload = $revision?->payload;
        $domain = MarketingSeoValidator::PRODUCTION_CANONICAL_ORIGIN;

        if (! is_array($payload)) {
            return [
                'checks' => [
                    MarketingSeoCheckRecord::make(
                        checkId: 'cms-published-content',
                        source: 'cms',
                        severity: 'critical',
                        status: 'failed',
                        message: 'No published marketing content',
                        checkedAt: $checkedAt,
                        evidence: 'Publish content before running SEO monitoring.',
                        remediation: 'Open Content & SEO and publish the marketing website payload.',
                    ),
                ],
                'fingerprint' => null,
                'domain' => $domain,
                'revision_id' => null,
            ];
        }

        $seo = $payload['seo'] ?? null;
        if (! is_array($seo)) {
            return [
                'checks' => [
                    MarketingSeoCheckRecord::make(
                        checkId: 'cms-seo-block',
                        source: 'cms',
                        severity: 'critical',
                        status: 'failed',
                        message: 'Published payload has no SEO block',
                        checkedAt: $checkedAt,
                        remediation: 'Add seo configuration in Content & SEO and republish.',
                    ),
                ],
                'fingerprint' => $this->fingerprint($revision->id, $payload),
                'domain' => $domain,
                'revision_id' => $revision->id,
            ];
        }

        $origin = rtrim((string) ($seo['canonicalOrigin'] ?? MarketingSeoValidator::PRODUCTION_CANONICAL_ORIGIN), '/');
        $domain = $origin !== '' ? $origin : $domain;

        $checks = [];
        $pages = is_array($seo['pages'] ?? null) ? $seo['pages'] : [];

        $indexableUrls = [];
        foreach (self::PAGE_IDS as $pageId) {
            $meta = self::PAGE_META[$pageId];
            $page = is_array($pages[$pageId] ?? null) ? $pages[$pageId] : null;
            if ($page === null) {
                continue;
            }

            $title = trim((string) ($page['seoTitle'] ?? ''));
            $description = trim((string) ($page['seoDescription'] ?? ''));
            $robotsIndex = (bool) ($page['robotsIndex'] ?? false);

            if ($title === '') {
                $checks[] = MarketingSeoCheckRecord::make(
                    checkId: 'cms-meta-title-'.$pageId,
                    source: 'cms',
                    severity: 'critical',
                    status: 'failed',
                    message: "Missing page title ({$pageId})",
                    checkedAt: $checkedAt,
                    evidence: 'seoTitle is empty after trim',
                    remediation: 'Set a unique SEO title for this page in Content & SEO.',
                    url: $this->pageUrl($origin, $meta['path']),
                );
            }

            if ($description === '') {
                $checks[] = MarketingSeoCheckRecord::make(
                    checkId: 'cms-meta-description-'.$pageId,
                    source: 'cms',
                    severity: 'warning',
                    status: 'failed',
                    message: "Missing meta description ({$pageId})",
                    checkedAt: $checkedAt,
                    evidence: 'seoDescription is empty after trim',
                    remediation: 'Add a meta description for this page in Content & SEO.',
                    url: $this->pageUrl($origin, $meta['path']),
                );
            }

            if ($meta['index_locked'] && $robotsIndex) {
                $checks[] = MarketingSeoCheckRecord::make(
                    checkId: 'cms-noindex-lock-'.$pageId,
                    source: 'cms',
                    severity: 'critical',
                    status: 'failed',
                    message: "Noindex policy violation ({$pageId})",
                    checkedAt: $checkedAt,
                    evidence: 'robotsIndex must remain false for this page',
                    remediation: 'Keep privacy and terms as noindex in the CMS.',
                    url: $this->pageUrl($origin, $meta['path']),
                );
            }

            if ($robotsIndex && ! $meta['index_locked']) {
                $indexableUrls[] = $this->pageUrl($origin, $meta['path']);
            }
        }

        foreach ($this->duplicateTitleFindings($pages, $origin, $checkedAt) as $finding) {
            $checks[] = $finding;
        }

        $count = count($indexableUrls);
        $checks[] = MarketingSeoCheckRecord::make(
            checkId: 'cms-sitemap-url-count',
            source: 'cms',
            severity: 'info',
            status: 'passed',
            message: 'CMS sitemap URLs (configuration)',
            checkedAt: $checkedAt,
            evidence: 'indexable_url_count='.$count,
            remediation: 'Compare with live sitemap when production HTTP checks are enabled.',
        );

        if ($count === 0) {
            $checks[] = MarketingSeoCheckRecord::make(
                checkId: 'cms-sitemap-empty',
                source: 'cms',
                severity: 'warning',
                status: 'failed',
                message: 'No indexable URLs in CMS configuration',
                checkedAt: $checkedAt,
                evidence: 'All pages are noindex or missing',
                remediation: 'Enable robotsIndex on public marketing pages that should appear in the sitemap.',
            );
        }

        $verification = is_array($seo['verification'] ?? null) ? $seo['verification'] : [];
        $googleToken = trim((string) ($verification['google'] ?? ''));
        $checks[] = MarketingSeoCheckRecord::make(
            checkId: 'cms-gsc-token-configured',
            source: 'cms',
            severity: $googleToken === '' ? 'warning' : 'info',
            status: $googleToken === '' ? 'failed' : 'passed',
            message: 'Google Search Console verification token (CMS configuration)',
            checkedAt: $checkedAt,
            evidence: $googleToken === '' ? 'not configured' : 'token present (not proof of Google verification)',
            remediation: 'Add verification token in Content & SEO if using meta-tag verification; complete verification in Search Console separately.',
        );

        return [
            'checks' => $checks,
            'fingerprint' => $this->fingerprint($revision->id, $payload),
            'domain' => $domain,
            'revision_id' => $revision->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $pages
     * @return list<array<string, mixed>>
     */
    private function duplicateTitleFindings(array $pages, string $origin, string $checkedAt): array
    {
        $byTitle = [];
        foreach (self::PAGE_IDS as $pageId) {
            $page = is_array($pages[$pageId] ?? null) ? $pages[$pageId] : null;
            if ($page === null) {
                continue;
            }
            $normalized = mb_strtolower(trim((string) ($page['seoTitle'] ?? '')));
            if ($normalized === '') {
                continue;
            }
            $byTitle[$normalized] ??= [];
            $byTitle[$normalized][] = $pageId;
        }

        $checks = [];
        foreach ($byTitle as $title => $ids) {
            if (count($ids) < 2) {
                continue;
            }
            $checks[] = MarketingSeoCheckRecord::make(
                checkId: 'cms-duplicate-title-'.hash('crc32', $title),
                source: 'cms',
                severity: 'warning',
                status: 'failed',
                message: 'Duplicate SEO titles in CMS',
                checkedAt: $checkedAt,
                evidence: 'title="'.$title.'" on pages: '.implode(', ', $ids),
                remediation: 'Give each public page a unique SEO title in Content & SEO.',
            );
        }

        return $checks;
    }

    private function pageUrl(string $origin, string $path): string
    {
        $base = rtrim($origin, '/');

        return $path === '/' ? $base.'/' : $base.$path;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fingerprint(int $revisionId, array $payload): string
    {
        $seo = is_array($payload['seo'] ?? null) ? $payload['seo'] : [];
        $publishedAt = is_string($seo['publishedAt'] ?? null) ? $seo['publishedAt'] : '';

        return hash('sha256', $revisionId.'|'.$publishedAt);
    }
}
