<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantTimezone;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Setup the test environment
     */
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'queue.default' => 'sync',
            'applicationaccess.telemetry_enabled' => filter_var(
                env('APPLICATION_ACCESS_TELEMETRY_ENABLED', false),
                FILTER_VALIDATE_BOOLEAN
            ),
        ]);
        Cache::flush();
        User::flushRequestPermissionCache();
        TenantTimezone::flush();
    }

    /**
     * Authenticate as a user for API testing
     */
    protected function actingAsUser(?User $user = null): self
    {
        $user = $user ?? User::factory()->create();
        Passport::actingAs($user, ['*']);

        return $this;
    }

    /**
     * Authenticate as super admin
     */
    protected function actingAsSuperAdmin(): self
    {
        $admin = User::factory()->create([
            'user_type' => null, // Super Admin (null = system admin)
            'is_primary_admin' => true,
            'tenant_id' => null,
        ]);

        Passport::actingAs($admin, ['*']);

        return $this;
    }

    /**
     * Authenticate as tenant admin
     */
    protected function actingAsTenantAdmin($tenantId = null): self
    {
        $admin = User::factory()->create([
            'user_type' => 2, // Tenant Admin
            'tenant_id' => $tenantId ?? Tenant::factory()->create()->id,
            'is_primary_admin' => true,
        ]);

        Passport::actingAs($admin, ['*']);

        return $this;
    }

    /**
     * Get authentication headers with bearer token
     */
    protected function getAuthHeaders(?User $user = null): array
    {
        if ($user) {
            Passport::actingAs($user, ['*']);
        }

        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Assert API response structure
     */
    protected function assertApiResponse($response, array $structure, int $status = 200): void
    {
        $response->assertStatus($status);
        $response->assertJsonStructure($structure);
    }

    /**
     * Assert API success response
     */
    protected function assertApiSuccess($response, int $status = 200): void
    {
        $response->assertStatus($status);
        $response->assertJson(['success' => true]);
    }

    /**
     * Assert API error response
     */
    protected function assertApiError($response, int $status = 422): void
    {
        $response->assertStatus($status);
        $response->assertJson(['success' => false]);
    }

    /**
     * Assert validation error
     */
    protected function assertValidationError($response, string $field): void
    {
        $response->assertStatus(422);
        $response->assertJsonValidationErrors([$field]);
    }

    /**
     * Assert unauthorized
     */
    protected function assertUnauthorized($response): void
    {
        $response->assertStatus(401);
    }

    /**
     * Assert forbidden
     */
    protected function assertForbidden($response): void
    {
        $response->assertStatus(403);
    }
}
