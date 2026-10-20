<?php

namespace Modules\Tenants\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlatformHealthApiTest extends TestCase
{
    #[Test]
    public function platform_health_endpoint_returns_dependency_snapshot(): void
    {
        config([
            'tenants.platform.health.token' => null,
            'cache.default' => 'array',
        ]);

        $response = $this->getJson('/api/platform/health');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.checks.cache.driver', 'array')
            ->assertJsonStructure([
                'data' => [
                    'status',
                    'checks' => ['database', 'cache', 'queue', 'storage'],
                    'flags' => [
                        'tenant_rls_enabled',
                        'tenant_orm_global_scope',
                        'tenant_api_rate_limit_enabled',
                    ],
                    'version',
                ],
            ]);
    }

    #[Test]
    public function platform_health_endpoint_requires_token_when_configured(): void
    {
        config(['tenants.platform.health.token' => 'probe-token']);

        $this->getJson('/api/platform/health')->assertForbidden();

        $this->withHeader('X-Platform-Health-Token', 'probe-token')
            ->getJson('/api/platform/health')
            ->assertOk();
    }
}
