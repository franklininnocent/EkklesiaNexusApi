<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Authentication\Models\User;
use Modules\BCC\Models\BCC;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\ContributionPlan;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Notifications\Database\Seeders\NotificationDefinitionsSeeder;
use Modules\Sacraments\Database\Seeders\SacramentTypesSeeder;
use Modules\Subscriptions\Models\TenantEntitlementOverride;
use Modules\Tenants\Database\Seeders\Support\CompactDemoMarkers;
use Modules\Tenants\Database\Seeders\Support\CompactDemoValidator;
use Modules\Tenants\Database\Seeders\Support\TenantDemoMarkers;
use Modules\Tenants\Database\Seeders\TenantDemoDataOrchestratorSeeder;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BindsDemoTenantEnv;
use Tests\TestCase;

class TenantCompactDemoDataSeederTest extends TestCase
{
    use BindsDemoTenantEnv;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        foreach ([
            TenantDemoMarkers::ENV_TENANT_ID,
            TenantDemoMarkers::ENV_SKIP_FAMILIES,
            TenantDemoMarkers::ENV_SKIP_STEWARDSHIP,
            TenantDemoMarkers::ENV_SKIP_BCC_LEADERSHIP,
            TenantDemoMarkers::ENV_SKIP_SACRAMENTS,
        ] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NotificationDefinitionsSeeder::class);
        $this->seed(SacramentTypesSeeder::class);
        $this->seed(\Modules\Tenants\Database\Seeders\LeadershipRolesSeeder::class);
        $this->seed(\Modules\EcclesiasticalData\Database\Seeders\EcclesiasticalRoleSeeder::class);
        $this->seed(\Modules\EcclesiasticalData\Database\Seeders\EcclesiasticalOfficesSeeder::class);
    }

    #[Test]
    public function compact_orchestrator_creates_five_by_five_dataset_idempotently(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Compact Demo Parish',
            'active' => 1,
            'trial_ends_at' => null,
            'subscription_ends_at' => now()->addYear(),
        ]);

        User::factory()->create([
            'tenant_id' => $tenant->id,
            'active' => 1,
        ]);

        $this->bindDemoTenantEnv((int) $tenant->id);
        putenv(TenantDemoMarkers::ENV_SKIP_BCC_LEADERSHIP.'=1');

        $this->seed(TenantDemoDataOrchestratorSeeder::class);
        $this->seed(TenantDemoDataOrchestratorSeeder::class);

        $compactBccs = BCC::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('bcc_code', CompactDemoMarkers::BCC_CODES)
            ->count();
        $this->assertSame(5, $compactBccs);

        $families = Family::query()
            ->where('tenant_id', $tenant->id)
            ->where('notes', 'like', '%'.CompactDemoMarkers::MARKER.'%')
            ->count();
        $this->assertSame(25, $families);

        foreach (CompactDemoMarkers::BCC_CODES as $code) {
            $bccId = BCC::query()->where('tenant_id', $tenant->id)->where('bcc_code', $code)->value('id');
            $count = Family::query()
                ->where('tenant_id', $tenant->id)
                ->where('bcc_id', $bccId)
                ->where('notes', 'like', '%'.CompactDemoMarkers::MARKER.'%')
                ->count();
            $this->assertSame(5, $count);
        }

        $members = FamilyMember::query()
            ->where('tenant_id', $tenant->id)
            ->whereHas('family', fn ($q) => $q->where('notes', 'like', '%'.CompactDemoMarkers::MARKER.'%'))
            ->count();
        $this->assertLessThanOrEqual(CompactDemoMarkers::MAX_MEMBERS, $members);
        $this->assertGreaterThan(80, $members);

        $this->assertSame([], CompactDemoValidator::integrityErrors((int) $tenant->id));
        $this->assertSame([], CompactDemoValidator::compactShapeErrors((int) $tenant->id));
    }

    #[Test]
    public function sacred_heart_named_tenant_does_not_receive_sixty_bcc_catalog_from_demo_path(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Sacred Heart Church',
            'active' => 1,
            'trial_ends_at' => null,
            'subscription_ends_at' => now()->addYear(),
        ]);

        User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);

        $this->bindDemoTenantEnv((int) $tenant->id);
        putenv(TenantDemoMarkers::ENV_SKIP_FAMILIES.'=1');
        putenv(TenantDemoMarkers::ENV_SKIP_STEWARDSHIP.'=1');
        putenv(TenantDemoMarkers::ENV_SKIP_BCC_LEADERSHIP.'=1');
        putenv(TenantDemoMarkers::ENV_SKIP_SACRAMENTS.'=1');

        $this->seed(TenantDemoDataOrchestratorSeeder::class);

        $totalBccs = BCC::query()->where('tenant_id', $tenant->id)->count();
        $this->assertLessThanOrEqual(5, $totalBccs);
    }

    #[Test]
    public function stewardship_demo_dues_attach_only_to_compact_families(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Stewardship Scope Parish',
            'active' => 1,
            'trial_ends_at' => null,
            'subscription_ends_at' => now()->addYear(),
        ]);

        User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);

        $this->bindDemoTenantEnv((int) $tenant->id);
        putenv(TenantDemoMarkers::ENV_SKIP_BCC_LEADERSHIP.'=1');
        putenv(TenantDemoMarkers::ENV_SKIP_SACRAMENTS.'=1');

        $this->seed(TenantDemoDataOrchestratorSeeder::class);

        $demoPlanIds = ContributionPlan::forTenant((int) $tenant->id)->where('code', 'like', 'DEMO_%')->pluck('id');
        $this->assertNotEmpty($demoPlanIds->all());

        $bcc = BCC::query()->where('tenant_id', $tenant->id)->where('bcc_code', 'DEMO-N')->first()
            ?? BCC::factory()->create(['tenant_id' => $tenant->id]);

        $realFamily = Family::factory()->create([
            'tenant_id' => $tenant->id,
            'bcc_id' => $bcc->id,
            'status' => 'active',
            'notes' => 'real family not compact',
        ]);

        $realFamilyDues = ContributionDue::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('plan_id', $demoPlanIds)
            ->where('family_id', $realFamily->id)
            ->count();

        $this->assertSame(0, $realFamilyDues);
    }

    #[Test]
    public function demo_seed_does_not_write_entitlement_overrides(): void
    {
        $before = TenantEntitlementOverride::query()->count();

        $tenant = Tenant::factory()->create([
            'name' => 'Entitlement Guard Parish',
            'active' => 1,
            'trial_ends_at' => null,
            'subscription_ends_at' => now()->addYear(),
        ]);

        User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);

        $this->bindDemoTenantEnv((int) $tenant->id);
        putenv(TenantDemoMarkers::ENV_SKIP_BCC_LEADERSHIP.'=1');
        putenv(TenantDemoMarkers::ENV_SKIP_STEWARDSHIP.'=1');
        putenv(TenantDemoMarkers::ENV_SKIP_SACRAMENTS.'=1');

        $this->seed(TenantDemoDataOrchestratorSeeder::class);

        $this->assertSame($before, TenantEntitlementOverride::query()->count());
    }

    #[Test]
    public function production_environment_blocks_demo_seed_command(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $exit = Artisan::call('tenants:seed-demo-data', [
            '--tenant' => '1',
        ]);

        $this->assertSame(1, $exit);
    }
}
