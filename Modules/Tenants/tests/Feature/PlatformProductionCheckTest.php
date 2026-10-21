<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Tenants\Support\TenantRlsManager;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlatformProductionCheckTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('FRONTEND_URL');
        putenv('MARKETING_URL');
        unset($_ENV['FRONTEND_URL'], $_SERVER['FRONTEND_URL'], $_ENV['MARKETING_URL'], $_SERVER['MARKETING_URL']);

        parent::tearDown();
    }

    #[Test]
    public function production_check_command_runs_in_testing_environment(): void
    {
        $this->bindPublicCorsUrls();
        $this->productionCheckConfig([
            'tenants.isolation.rls_enabled' => DB::getDriverName() === 'pgsql',
        ]);

        $this->artisan('platform:production-check')
            ->expectsOutputToContain('Cache store is redis')
            ->assertExitCode(0);
    }

    #[Test]
    public function production_check_fails_when_view_only_locked_enforcement_is_off(): void
    {
        $this->bindPublicCorsUrls();
        $this->productionCheckConfig([
            'tenants.subscription.view_only_locked_enforcement' => false,
        ]);

        $this->artisan('platform:production-check')
            ->assertExitCode(1);
    }

    #[Test]
    public function production_check_fails_when_frontend_url_is_loopback(): void
    {
        putenv('FRONTEND_URL=http://localhost:4200');
        $_ENV['FRONTEND_URL'] = 'http://localhost:4200';
        putenv('MARKETING_URL=https://www.example.test');
        $_ENV['MARKETING_URL'] = 'https://www.example.test';
        $this->productionCheckConfig();

        $this->artisan('platform:production-check')
            ->expectsOutputToContain('CORS origins exclude localhost')
            ->assertExitCode(1);
    }

    #[Test]
    public function production_check_warns_when_rls_is_not_pgsql(): void
    {
        $this->bindPublicCorsUrls();
        $this->productionCheckConfig();

        if (DB::getDriverName() === 'pgsql') {
            $this->markTestSkipped('This assertion covers the SQLite/non-pgsql WARN path.');
        }

        $this->artisan('platform:production-check')
            ->expectsOutputToContain('TENANT_RLS_ENABLED on PostgreSQL')
            ->expectsOutputToContain('WARN')
            ->assertExitCode(0);
    }

    #[Test]
    public function production_check_fails_when_pgsql_rls_flag_is_off(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL required for RLS fail-closed command test.');
        }

        $this->bindPublicCorsUrls();
        $this->productionCheckConfig([
            'tenants.isolation.rls_enabled' => false,
        ]);

        $this->artisan('platform:production-check')
            ->assertExitCode(1);
    }

    #[Test]
    public function production_check_fails_when_wave_one_policies_are_missing(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL required for RLS policy presence command test.');
        }

        $this->bindPublicCorsUrls();
        $this->productionCheckConfig([
            'tenants.isolation.rls_enabled' => true,
        ]);

        if (TenantRlsManager::waveOnePoliciesPresent()) {
            $this->markTestSkipped('Wave-1 policies are present; missing-policy fail path is not exercised.');
        }

        $this->artisan('platform:production-check')
            ->assertExitCode(1);
    }

    #[Test]
    public function production_check_fails_when_mail_still_uses_smtp(): void
    {
        $this->bindPublicCorsUrls();
        $this->productionCheckConfig([
            'mail.default' => 'smtp',
        ]);

        $this->artisan('platform:production-check')
            ->expectsOutputToContain('Transactional mail uses Resend over HTTPS')
            ->assertExitCode(1);
    }

    #[Test]
    public function production_check_fails_when_resend_key_is_missing(): void
    {
        $this->bindPublicCorsUrls();
        $this->productionCheckConfig([
            'services.resend.key' => '',
        ]);

        $this->artisan('platform:production-check')
            ->expectsOutputToContain('Transactional mail uses Resend over HTTPS')
            ->assertExitCode(1);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function productionCheckConfig(array $overrides = []): void
    {
        config(array_merge([
            'app.env' => 'production',
            'app.debug' => false,
            'tenants.isolation.orm_global_scope' => true,
            'tenants.platform.health.token' => 'test-token',
            'tenants.subscription.view_only_locked_enforcement' => true,
            'mail.default' => 'resend',
            'services.resend.key' => 're_test',
            'mail.from.address' => 'contact@example.test',
        ], $overrides));
    }

    private function bindPublicCorsUrls(): void
    {
        putenv('FRONTEND_URL=https://app.example.test');
        $_ENV['FRONTEND_URL'] = 'https://app.example.test';
        putenv('MARKETING_URL=https://www.example.test');
        $_ENV['MARKETING_URL'] = 'https://www.example.test';
    }
}
