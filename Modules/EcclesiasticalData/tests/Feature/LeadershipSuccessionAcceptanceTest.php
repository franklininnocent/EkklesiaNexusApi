<?php

namespace Modules\EcclesiasticalData\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\EcclesiasticalData\Database\Seeders\EcclesiasticalOfficesSeeder;
use Modules\EcclesiasticalData\Models\BishopAppointment;
use Modules\EcclesiasticalData\Models\DioceseManagement;
use Modules\EcclesiasticalData\Services\BishopService;
use Modules\EcclesiasticalData\Services\BishopUpdateRequestService;
use Modules\EcclesiasticalData\Services\SuccessionService;
use Modules\EcclesiasticalData\Support\BishopUpdateRequestType;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Models\Denomination;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Testing\ChurchProfileCertificationTestCase;
use PHPUnit\Framework\Attributes\Test;

class LeadershipSuccessionAcceptanceTest extends ChurchProfileCertificationTestCase
{
    use RefreshDatabase;

    protected DioceseManagement $diocese;

    protected Tenant $tenantA;

    protected Tenant $tenantB;

    protected User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Denomination::count()) {
            Denomination::create([
                'name' => 'Roman Catholic',
                'code' => 'RC',
                'description' => 'Roman Catholic Church',
                'status' => 'active',
            ]);
        }

        if (! Country::where('iso2', 'IN')->exists()) {
            Country::create([
                'name' => 'India',
                'iso2' => 'IN',
                'iso3' => 'IND',
                'phone_code' => '+91',
                'status' => 'active',
            ]);
        }

        $this->seed(EcclesiasticalOfficesSeeder::class);
        $this->diocese = DioceseManagement::factory()->create();
        $this->tenantA = Tenant::factory()->active()->create();
        $this->tenantB = Tenant::factory()->active()->create();

        ChurchProfile::query()->create([
            'tenant_id' => $this->tenantA->id,
            'archdiocese_id' => $this->diocese->id,
        ]);
        ChurchProfile::query()->create([
            'tenant_id' => $this->tenantB->id,
            'archdiocese_id' => $this->diocese->id,
        ]);

        $this->reviewer = $this->createReviewer();
    }

    #[Test]
    public function it_resolves_new_bishop_on_all_church_profiles_after_approved_succession(): void
    {
        app(SuccessionService::class)->replaceCurrentOrdinary(
            (int) $this->diocese->id,
            app(BishopService::class)->createPerson([
                'full_name' => 'Bishop Old',
                'archdiocese_id' => $this->diocese->id,
            ], null, false),
            ['effective_date' => '2018-01-01'],
        );

        $service = app(BishopUpdateRequestService::class);
        $submitterId = (int) User::factory()->create(['tenant_id' => $this->tenantA->id])->id;
        $draft = $service->createDraft(
            $this->tenantA->id,
            $this->diocese->id,
            BishopUpdateRequestType::ChangeCurrentBishop,
            ['full_name' => 'Bishop Successor', 'given_name' => 'Successor'],
            ['effective_date' => '2026-03-01', 'end_reason' => 'retirement'],
            $submitterId,
        );
        $submitted = $service->submit($draft->id, $this->tenantA->id, (int) $draft->fresh()->version, $submitterId);

        Passport::actingAs($this->reviewer);
        $this->postJson("/api/ecclesiastical/bishop-update-requests/{$submitted->id}/approve", [
            'version' => $submitted->version,
        ])->assertOk();

        $this->assertEquals(1, BishopAppointment::query()
            ->where('diocese_id', $this->diocese->id)
            ->where('is_current', true)
            ->ordinary()
            ->count());

        foreach ([$this->tenantA, $this->tenantB] as $tenant) {
            $this->actingAsTenantWith(['church.settings.edit', 'bishops.view'], $tenant);

            $this->getJson('/api/church-profile')
                ->assertOk()
                ->assertJsonPath('data.bishop.full_name', 'Bishop Successor');

            $this->getJson('/api/church-profile/leadership/diocesan')
                ->assertOk()
                ->assertJsonPath('data.ordinary.bishop_name', 'Bishop Successor');
        }
    }

    private function createReviewer(): User
    {
        $permissions = [
            'bishops.review_requests',
            'bishops.approve_requests',
            'bishops.reject_requests',
            'bishops.request_clarification',
            'bishops.view',
            'dioceses.view',
            'bishops.manage_appointments',
        ];

        $role = Role::query()->firstOrCreate(
            ['name' => Role::EKKLESIA_ADMIN, 'tenant_id' => null],
            [
                'description' => 'Ekklesia Admin',
                'level' => Role::LEVEL_EKKLESIA_ADMIN,
                'active' => 1,
                'is_custom' => false,
                'role_type' => Role::ROLE_TYPE_PLATFORM,
            ]
        );

        $permissionIds = [];
        foreach ($permissions as $name) {
            $permission = Permission::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'description' => 'Leadership acceptance test permission',
                    'module' => 'EcclesiasticalData',
                    'scope' => Permission::SCOPE_PLATFORM,
                    'category' => 'bishops',
                    'tenant_id' => null,
                    'is_custom' => false,
                    'active' => 1,
                ]
            );
            $permissionIds[] = $permission->id;
        }
        $role->permissions()->sync($permissionIds);

        $user = User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
            'active' => 1,
        ]);
        $user->syncRoles([$role->id]);
        $user->clearPermissionsCache();

        return $user;
    }
}
