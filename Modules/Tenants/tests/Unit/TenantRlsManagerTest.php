<?php

namespace Modules\Tenants\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Tenants\Support\TenantContextBinder;
use Modules\Tenants\Support\TenantRlsManager;
use Tests\TestCase;

class TenantRlsManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenants.isolation.rls_enabled' => true]);
    }

    public function test_it_is_disabled_on_non_pgsql_drivers(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->assertTrue(TenantRlsManager::isEnabled());

            return;
        }

        $this->assertFalse(TenantRlsManager::isEnabled());
    }

    public function test_it_is_disabled_when_config_flag_is_off(): void
    {
        config(['tenants.isolation.rls_enabled' => false]);

        $this->assertFalse(TenantRlsManager::isEnabled());
    }

    public function test_wave_one_policy_inventory_is_incomplete_on_non_pgsql(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->markTestSkipped('Non-pgsql inventory assertion only.');
        }

        $this->assertFalse(TenantRlsManager::waveOnePoliciesPresent());
        $this->assertSame(TenantRlsManager::WAVE_ONE_TABLES, TenantRlsManager::missingWaveOnePolicyTables());
    }

    public function test_policy_sql_uses_fail_closed_tenant_guc(): void
    {
        $sql = TenantRlsManager::policySql('families');

        $this->assertStringContainsString("current_setting('app.current_tenant', true)", $sql);
        $this->assertStringContainsString('tenant_id =', $sql);
    }

    public function test_run_with_tenant_sets_transaction_local_guc_on_pgsql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL required for RLS GUC tests.');

            return;
        }

        TenantRlsManager::runWithTenant(42, function (): void {
            $setting = DB::selectOne("SELECT current_setting('app.current_tenant', true) AS tenant");

            $this->assertSame('42', $setting->tenant);
        });
    }

    public function test_before_starting_transaction_applies_context_tenant_on_pgsql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL required for RLS GUC tests.');

            return;
        }

        TenantContextBinder::bind(77);

        DB::transaction(function (): void {
            $setting = DB::selectOne("SELECT current_setting('app.current_tenant', true) AS tenant");

            $this->assertSame('77', $setting->tenant);
        });
    }
}
