<?php

namespace Modules\Marketing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Marketing\Services\MarketingContentService;
use Modules\Marketing\Support\SeoMonitoring\MarketingSeoMonitoringConfig;
use Modules\Marketing\Tests\Support\MarketingContentPayload;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class MarketingSeoMonitoringTest extends TestCase
{
    use ActsAsTenantRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
        $config = new MarketingSeoMonitoringConfig;
        Cache::forget($config->cacheKey('snapshot_cms'));
        Cache::forget($config->cacheKey('snapshot_production'));
        Cache::forget($config->cacheKey('last_failed'));
        Cache::forget($config->cacheKey('active_scan'));
        Cache::forget($config->cacheKey('lock'));
    }

    /**
     * @return array<string, mixed>
     */
    private function seoBlock(): array
    {
        return [
            'schemaVersion' => 1,
            'canonicalOrigin' => 'https://ekklesianexus.com',
            'defaultLocale' => 'en',
            'ogLocale' => 'en_US',
            'defaultOgImage' => '/images/og/og-preview.png',
            'organizationName' => 'EkklesiaNexus',
            'organizationDescription' => 'Church management.',
            'robots' => ['allowRoot' => true],
            'pages' => [
                'home' => [
                    'id' => 'home',
                    'name' => 'Homepage',
                    'path' => '/',
                    'seoTitle' => 'EkklesiaNexus — ChMS',
                    'seoDescription' => 'Church management software for parishes.',
                    'robotsIndex' => true,
                    'robotsFollow' => true,
                ],
                'privacy' => [
                    'id' => 'privacy',
                    'name' => 'Privacy',
                    'path' => '/privacy',
                    'seoTitle' => 'Privacy — EkklesiaNexus',
                    'seoDescription' => 'Privacy information.',
                    'robotsIndex' => false,
                    'robotsFollow' => false,
                ],
                'terms' => [
                    'id' => 'terms',
                    'name' => 'Terms',
                    'path' => '/terms',
                    'seoTitle' => 'Terms — EkklesiaNexus',
                    'seoDescription' => 'Terms information.',
                    'robotsIndex' => false,
                    'robotsFollow' => false,
                ],
            ],
        ];
    }

    private function publishMarketing(): void
    {
        $payload = MarketingContentPayload::minimal();
        $payload['seo'] = $this->seoBlock();
        $this->asSuperAdmin();
        $this->postJson('/api/admin/marketing/content/publish', ['payload' => $payload])->assertCreated();
    }

    public function test_unauthenticated_cannot_access_snapshot(): void
    {
        $this->getJson('/api/admin/marketing/seo-monitoring/snapshot')->assertUnauthorized();
    }

    public function test_non_super_admin_forbidden(): void
    {
        $this->asTenantAdmin();
        $this->getJson('/api/admin/marketing/seo-monitoring/snapshot')->assertForbidden();
    }

    public function test_cms_only_scan_does_not_http_fetch_production(): void
    {
        Http::fake();
        $this->publishMarketing();

        $this->postJson('/api/admin/marketing/seo-monitoring/scans', [
            'include_production' => false,
        ])->assertOk();

        Http::assertNothingSent();
    }

    public function test_cms_scan_persists_snapshot_with_not_run_production_checks(): void
    {
        Http::fake();
        $this->publishMarketing();

        $this->postJson('/api/admin/marketing/seo-monitoring/scans', [
            'include_production' => false,
        ])->assertOk();

        $snapshot = $this->getJson('/api/admin/marketing/seo-monitoring/snapshot')
            ->assertOk()
            ->json('data');

        $this->assertFalse($snapshot['snapshot_unavailable']);
        $this->assertNotNull($snapshot['cms']['completed_at']);
        $this->assertTrue($snapshot['production']['never_run'] ?? false);
        $this->assertSame([], $snapshot['production']['checks'] ?? null);

        $notRun = collect($snapshot['cms']['checks'])->contains(
            fn (array $c) => ($c['source'] ?? '') === 'production_http' && ($c['status'] ?? '') === 'not_run',
        );
        $this->assertFalse($notRun);
    }

    public function test_rejects_unknown_scan_fields(): void
    {
        $this->publishMarketing();
        $this->postJson('/api/admin/marketing/seo-monitoring/scans', [
            'include_production' => false,
            'url' => 'https://evil.test',
        ])->assertUnprocessable();
    }

    public function test_redirect_to_localhost_is_security_rejection(): void
    {
        $this->publishMarketing();
        Http::fake([
            'https://ekklesianexus.com/*' => Http::sequence()
                ->push('', 302, ['Location' => 'http://127.0.0.1/'])
                ->whenEmpty(Http::response('ok', 200)),
        ]);

        $response = $this->postJson('/api/admin/marketing/seo-monitoring/scans', [
            'include_production' => true,
        ]);

        $response->assertOk();
        $checks = collect($response->json('data.checks'));
        $home = $checks->firstWhere('check_id', 'http-/');
        $this->assertNotNull($home);
        $this->assertSame('error', $home['status']);
        Http::assertSent(fn ($request) => str_starts_with((string) $request->url(), 'https://ekklesianexus.com'));
        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), '127.0.0.1'));
    }

    public function test_not_run_checks_are_not_counted_as_passed(): void
    {
        Http::fake();
        $this->publishMarketing();
        $this->postJson('/api/admin/marketing/seo-monitoring/scans', [
            'include_production' => false,
        ])->assertOk();

        $summary = $this->getJson('/api/admin/marketing/seo-monitoring/snapshot')
            ->json('data.cms.summary.production_http');

        $this->assertSame(0, $summary['passed'] ?? -1);
    }

    public function test_duplicate_cms_titles_produce_finding(): void
    {
        Http::fake();
        $payload = MarketingContentPayload::minimal();
        $seo = $this->seoBlock();
        $seo['pages']['privacy']['seoTitle'] = 'Same Title Here';
        $seo['pages']['terms']['seoTitle'] = 'Same Title Here';
        $payload['seo'] = $seo;
        $this->asSuperAdmin();
        $response = $this->postJson('/api/admin/marketing/content/publish', ['payload' => $payload])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertStringContainsString(
            'Duplicate seoTitle',
            (string) $response->json('message'),
        );
    }
}
