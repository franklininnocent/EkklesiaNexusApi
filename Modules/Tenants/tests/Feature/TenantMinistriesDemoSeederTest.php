<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\MinistriesAssociations\Models\GuestMember;
use Modules\MinistriesAssociations\Models\LeadershipTerm;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\MinistriesAssociations\Models\OrganizationMembership;
use Modules\Tenants\Database\Seeders\Support\MinistriesDemoCatalog;
use Modules\Tenants\Database\Seeders\Support\TenantDemoMarkers;
use Modules\Tenants\Database\Seeders\TenantMinistriesDemoSeeder;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BindsDemoTenantEnv;
use Tests\TestCase;

class TenantMinistriesDemoSeederTest extends TestCase
{
    use BindsDemoTenantEnv;
    use RefreshDatabase;

    #[Test]
    public function ministries_demo_seeder_is_idempotent_and_tenant_scoped(): void
    {
        $tenant = Tenant::factory()->create([
            'features' => ['ministries_associations'],
            'active' => 1,
            'trial_ends_at' => null,
            'subscription_ends_at' => now()->addYear(),
        ]);

        User::factory()->create([
            'tenant_id' => $tenant->id,
            'active' => 1,
        ]);

        $family = Family::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => 'active',
        ]);

        for ($i = 0; $i < 45; $i++) {
            FamilyMember::factory()->create([
                'family_id' => $family->id,
            ]);
        }

        $this->bindDemoTenantEnv((int) $tenant->id);

        $this->seed(TenantMinistriesDemoSeeder::class);
        $membershipCount = OrganizationMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('remarks', TenantDemoMarkers::MARKER)
            ->count();
        $orgCount = Organization::query()
            ->forTenant($tenant->id)
            ->whereIn('code', MinistriesDemoCatalog::organizationCodes())
            ->count();

        $this->seed(TenantMinistriesDemoSeeder::class);

        $this->assertSame($membershipCount, OrganizationMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('remarks', TenantDemoMarkers::MARKER)
            ->count());
        $this->assertSame($orgCount, Organization::query()
            ->forTenant($tenant->id)
            ->whereIn('code', MinistriesDemoCatalog::organizationCodes())
            ->count());
        $this->assertSame(count(MinistriesDemoCatalog::organizationCodes()), $orgCount);
        $this->assertGreaterThan(20, $membershipCount);
        $this->assertGreaterThan(5, LeadershipTerm::query()->where('tenant_id', $tenant->id)->count());
        $this->assertGreaterThanOrEqual(1, GuestMember::query()->forTenant($tenant->id)->count());

        $this->assertTrue(Organization::query()
            ->forTenant($tenant->id)
            ->where('code', 'SSVP')
            ->where('status', Organization::STATUS_ACTIVE)
            ->exists());
        $this->assertTrue(Organization::query()
            ->forTenant($tenant->id)
            ->where('code', 'FINANCE_COMM')
            ->where('status', Organization::STATUS_INACTIVE)
            ->exists());
    }
}
