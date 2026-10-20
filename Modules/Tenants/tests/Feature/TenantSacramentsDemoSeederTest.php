<?php

namespace Modules\Tenants\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\BCC\Models\BCC;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Sacraments\Database\Seeders\SacramentPermissionsSeeder;
use Modules\Sacraments\database\seeders\SacramentTypesSeeder;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Repositories\SacramentRepository;
use Modules\Tenants\Database\Seeders\Support\SacramentsDemoMarkers;
use Modules\Tenants\Database\Seeders\Support\SacramentsDemoVerifier;
use Modules\Tenants\Database\Seeders\TenantSacramentsDemoSeeder;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BindsDemoTenantEnv;
use Tests\TestCase;

class TenantSacramentsDemoSeederTest extends TestCase
{
    use BindsDemoTenantEnv;
    use RefreshDatabase;

    #[Test]
    public function sacraments_demo_seeder_is_idempotent_and_reconciles_dashboard_and_list(): void
    {
        $this->seed(SacramentTypesSeeder::class);
        $this->seed(SacramentPermissionsSeeder::class);

        $tenant = Tenant::factory()->create(['name' => 'Sacraments Demo Parish', 'active' => 1]);
        $role = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Tenant Administrator',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $permissionNames = [
            'sacraments.view', 'sacraments.create', 'sacraments.edit',
            'sacraments.view_restricted',
        ];
        $permissionIds = [];
        foreach ($permissionNames as $name) {
            $permissionIds[] = Permission::query()->where('name', $name)->value('id');
        }
        $role->permissions()->syncWithoutDetaching(array_filter($permissionIds));

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'active' => 1,
        ]);
        $user->syncRoles([$role->id]);

        $bcc = BCC::factory()->create(['tenant_id' => $tenant->id]);

        for ($f = 0; $f < 25; $f++) {
            $family = Family::factory()->create([
                'tenant_id' => $tenant->id,
                'bcc_id' => $bcc->id,
                'status' => 'active',
                'address_line_1' => sprintf('%d Test Parish Road', 20 + $f),
                'city' => 'Kochi',
                'postal_code' => sprintf('%06d', 682100 + $f),
            ]);

            FamilyMember::factory()->create([
                'family_id' => $family->id,
                'gender' => 'male',
                'relationship_to_head' => 'father',
                'first_name' => 'Thomas',
                'last_name' => 'Parent',
                'date_of_birth' => Carbon::now()->subYears(62)->toDateString(),
                'status' => 'active',
            ]);
            FamilyMember::factory()->create([
                'family_id' => $family->id,
                'gender' => 'female',
                'relationship_to_head' => 'mother',
                'first_name' => 'Mary',
                'last_name' => 'Parent',
                'date_of_birth' => Carbon::now()->subYears(58)->toDateString(),
                'status' => 'active',
            ]);
            FamilyMember::factory()->create([
                'family_id' => $family->id,
                'gender' => 'male',
                'relationship_to_head' => 'self',
                'date_of_birth' => Carbon::now()->subYears(35)->toDateString(),
                'status' => 'active',
            ]);
            FamilyMember::factory()->create([
                'family_id' => $family->id,
                'gender' => 'female',
                'relationship_to_head' => 'spouse',
                'date_of_birth' => Carbon::now()->subYears(33)->toDateString(),
                'status' => 'active',
            ]);
            FamilyMember::factory()->create([
                'family_id' => $family->id,
                'gender' => 'male',
                'relationship_to_head' => 'son',
                'date_of_birth' => Carbon::now()->subYears(8)->toDateString(),
                'status' => 'active',
            ]);
            FamilyMember::factory()->create([
                'family_id' => $family->id,
                'gender' => 'female',
                'relationship_to_head' => 'daughter',
                'date_of_birth' => Carbon::now()->subYears(16)->toDateString(),
                'status' => 'active',
            ]);
            FamilyMember::factory()->create([
                'family_id' => $family->id,
                'gender' => 'female',
                'relationship_to_head' => 'grandmother',
                'date_of_birth' => Carbon::now()->subYears(72)->toDateString(),
                'status' => 'active',
            ]);
        }

        $this->bindDemoTenantEnv((int) $tenant->id);
        putenv(SacramentsDemoMarkers::ENV_TARGET.'='.SacramentsDemoMarkers::DEFAULT_TARGET);
        $_ENV[SacramentsDemoMarkers::ENV_TARGET] = (string) SacramentsDemoMarkers::DEFAULT_TARGET;
        $_SERVER[SacramentsDemoMarkers::ENV_TARGET] = (string) SacramentsDemoMarkers::DEFAULT_TARGET;

        $this->seed(TenantSacramentsDemoSeeder::class);
        $firstCount = Sacrament::query()
            ->where('tenant_id', $tenant->id)
            ->where('notes', 'like', '%'.SacramentsDemoMarkers::MARKER.'%')
            ->count();

        $this->assertGreaterThanOrEqual(SacramentsDemoVerifier::MIN_DEMO_ROWS, $firstCount);

        $this->seed(TenantSacramentsDemoSeeder::class);
        $secondCount = Sacrament::query()
            ->where('tenant_id', $tenant->id)
            ->where('notes', 'like', '%'.SacramentsDemoMarkers::MARKER.'%')
            ->count();

        $this->assertSame($firstCount, $secondCount);
        $this->assertSame([], SacramentsDemoVerifier::verify((int) $tenant->id));

        $page2 = app(SacramentRepository::class)->getPaginated([
            'tenant_id' => (int) $tenant->id,
            'per_page' => 20,
            'page' => 2,
        ]);
        $this->assertGreaterThanOrEqual(2, $page2->lastPage());
        $this->assertCount(20, $page2->items());

        Passport::actingAs($user);
        $recon = SacramentsDemoVerifier::dashboardReconciliation((int) $tenant->id);
        $this->assertTrue($recon['reconciled']);
    }
}
