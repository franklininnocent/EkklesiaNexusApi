<?php

namespace Modules\Sacraments\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;
use Modules\Family\Models\PersonIdentityReconciliationLog;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentType;
use Modules\Sacraments\Support\BaptismalStatus;
use Modules\Sacraments\Support\SacramentRecordStatus;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SacramentContextApiTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Tenant $otherTenant;

    protected User $user;

    protected Family $family;

    protected FamilyMember $member;

    protected Person $person;

    protected SacramentType $baptismType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['name' => 'St Mary Parish']);
        $role = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Tenant Administrator',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $this->tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
        ]);
        $this->user->syncRoles([$role->id]);
        $this->grantPermissions($role, ['sacraments.view', 'families.update']);

        $this->otherTenant = Tenant::factory()->create();

        $this->person = Person::factory()->create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'John',
            'middle_name' => null,
            'last_name' => 'Connor',
            'date_of_birth' => '2000-03-01',
            'gender' => 'male',
            'father_name' => 'Henry Connor',
            'mother_name' => 'Emily Henry',
        ]);

        $this->family = Family::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->member = FamilyMember::factory()->create([
            'family_id' => $this->family->id,
            'person_id' => $this->person->id,
            'first_name' => 'John',
            'middle_name' => null,
            'last_name' => 'Connor',
            'date_of_birth' => '2000-03-01',
            'gender' => 'male',
        ]);

        $this->baptismType = SacramentType::factory()->create([
            'name' => 'Baptism',
            'code' => 'BAPTISM',
            'active' => true,
        ]);

        SacramentType::factory()->create([
            'name' => 'Marriage',
            'code' => 'MARRIAGE',
            'active' => true,
        ]);

        Passport::actingAs($this->user);
    }

    private function grantPermissions(Role $role, array $names): void
    {
        $ids = [];
        foreach ($names as $name) {
            $permission = Permission::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'description' => 'Test',
                    'module' => 'Sacraments',
                    'scope' => Permission::SCOPE_TENANT,
                    'category' => 'sacraments',
                    'tenant_id' => null,
                    'is_custom' => false,
                    'active' => 1,
                ]
            );
            $ids[] = $permission->id;
        }
        $role->permissions()->syncWithoutDetaching($ids);
    }

    #[Test]
    public function context_returns_canonical_identity_for_existing_member(): void
    {
        $response = $this->getJson('/api/sacraments/context?'.http_build_query([
            'family_member_id' => $this->member->id,
            'workflow' => 'MATRIMONY',
            'participant_role' => 'groom',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.canonical_identity.name.value', 'John Connor')
            ->assertJsonPath('data.canonical_identity.date_of_birth.value', '2000-03-01')
            ->assertJsonPath('data.canonical_identity.father_name.value', 'Henry Connor')
            ->assertJsonPath('data.canonical_identity.mother_name.value', 'Emily Henry')
            ->assertJsonPath('data.canonical_identity.name.field_state', 'CANONICAL')
            ->assertJsonPath('data.subject.person_id', $this->person->id);
    }

    #[Test]
    public function not_found_baptism_does_not_set_unbaptized(): void
    {
        $response = $this->getJson('/api/sacraments/context?'.http_build_query([
            'family_member_id' => $this->member->id,
            'workflow' => 'MATRIMONY',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.record_status.baptism', SacramentRecordStatus::NOT_FOUND)
            ->assertJsonPath('data.derived.baptismal_status.value', null);
    }

    #[Test]
    public function found_baptism_derives_baptized_catholic_from_evidence(): void
    {
        Sacrament::factory()->create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $this->family->id,
            'person_id' => $this->person->id,
            'sacrament_type_id' => $this->baptismType->id,
            'recipient_name' => 'John Connor',
            'date_administered' => '2000-06-12',
            'place_administered' => "St Mary's Church",
            'recipient_birth_date' => '2000-03-01',
            'status' => 'registered',
        ]);

        $response = $this->getJson('/api/sacraments/context?'.http_build_query([
            'family_member_id' => $this->member->id,
            'workflow' => 'MATRIMONY',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.record_status.baptism', SacramentRecordStatus::FOUND)
            ->assertJsonPath('data.derived.baptismal_status.value', BaptismalStatus::BAPTIZED_CATHOLIC)
            ->assertJsonPath('data.derived.baptismal_status.field_state', 'DERIVED')
            ->assertJsonPath('data.found_summary', fn ($v) => in_array('baptism', $v, true));
    }

    #[Test]
    public function dob_conflict_detected_between_member_and_baptism_record(): void
    {
        Sacrament::factory()->create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $this->family->id,
            'person_id' => $this->person->id,
            'sacrament_type_id' => $this->baptismType->id,
            'recipient_name' => 'John Connor',
            'date_administered' => '2000-06-12',
            'recipient_birth_date' => '2000-03-02',
            'status' => 'registered',
        ]);

        $response = $this->getJson('/api/sacraments/context?'.http_build_query([
            'family_member_id' => $this->member->id,
            'workflow' => 'MATRIMONY',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.has_blocking_conflicts', true);

        $conflicts = $response->json('data.conflicts');
        $this->assertTrue(collect($conflicts)->contains('field', 'date_of_birth'));
    }

    #[Test]
    public function cross_tenant_member_is_denied(): void
    {
        $otherFamily = Family::factory()->create(['tenant_id' => $this->otherTenant->id]);
        $otherMember = FamilyMember::factory()->create(['family_id' => $otherFamily->id]);

        $this->getJson('/api/sacraments/context?'.http_build_query([
            'family_member_id' => $otherMember->id,
            'workflow' => 'MATRIMONY',
        ]))->assertStatus(422);
    }

    #[Test]
    public function reconcile_identity_updates_person_and_creates_audit(): void
    {
        $response = $this->postJson('/api/persons/'.$this->person->id.'/reconcile-identity', [
            'field' => 'date_of_birth',
            'new_value' => '2000-03-02',
            'source_selected' => 'BAPTISM_RECORD',
            'reason' => 'Baptism register is authoritative for DOB.',
        ]);

        $response->assertOk();
        $this->person->refresh();
        $this->assertSame('2000-03-02', $this->person->date_of_birth->format('Y-m-d'));
        $this->assertDatabaseHas('person_identity_reconciliation_logs', [
            'person_id' => $this->person->id,
            'field' => 'date_of_birth',
        ]);
        $this->assertSame(1, PersonIdentityReconciliationLog::query()->count());
    }

    #[Test]
    public function context_requires_workflow_and_subject(): void
    {
        $this->getJson('/api/sacraments/context?family_member_id='.$this->member->id)
            ->assertStatus(422);

        $this->getJson('/api/sacraments/context?workflow=MATRIMONY')
            ->assertStatus(422);
    }

    #[Test]
    public function empty_profile_and_register_are_not_found(): void
    {
        $response = $this->getJson('/api/sacraments/context?'.http_build_query([
            'family_member_id' => $this->member->id,
            'workflow' => 'BAPTISM',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.record_status.baptism', SacramentRecordStatus::NOT_FOUND)
            ->assertJsonPath('data.record_status.baptism_register', SacramentRecordStatus::NOT_FOUND)
            ->assertJsonPath('data.sacraments.baptism.evidence_tier', null);
    }

    #[Test]
    public function profile_only_baptism_home_parish_surfaces_in_context(): void
    {
        $this->member->update([
            'baptism_date' => '2000-03-05',
            'baptism_location_type' => 'home_parish',
            'baptism_place' => null,
            'baptism_church_name' => null,
        ]);

        $response = $this->getJson('/api/sacraments/context?'.http_build_query([
            'family_member_id' => $this->member->id,
            'workflow' => 'MATRIMONY',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.record_status.baptism', SacramentRecordStatus::FOUND)
            ->assertJsonPath('data.record_status.baptism_register', SacramentRecordStatus::NOT_FOUND)
            ->assertJsonPath('data.sacraments.baptism.evidence_tier', 'MEMBER_PROFILE')
            ->assertJsonPath('data.sacraments.baptism.evidence.date.value', '2000-03-05')
            ->assertJsonPath('data.derived.baptismal_status.value', BaptismalStatus::BAPTIZED_CATHOLIC)
            ->assertJsonPath('data.fields.baptismal_status.input_hidden', true)
            ->assertJsonPath('data.found_summary', fn ($v) => in_array('baptism', $v, true));

        $missing = collect($response->json('data.missing'));
        $this->assertTrue($missing->contains(fn ($entry) => ($entry['field'] ?? '') === 'baptism_register_record'));
    }

    #[Test]
    public function profile_other_church_baptism_does_not_derive_baptismal_status(): void
    {
        $this->member->update([
            'baptism_date' => '2000-03-05',
            'baptism_location_type' => 'other',
            'baptism_church_name' => 'St Joseph Church',
        ]);

        $response = $this->getJson('/api/sacraments/context?'.http_build_query([
            'family_member_id' => $this->member->id,
            'workflow' => 'MATRIMONY',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.record_status.baptism', SacramentRecordStatus::FOUND)
            ->assertJsonPath('data.sacraments.baptism.evidence_tier', 'MEMBER_PROFILE')
            ->assertJsonPath('data.derived.baptismal_status.value', null)
            ->assertJsonPath('data.fields.baptismal_status.input_hidden', false);
    }

    #[Test]
    public function register_baptism_takes_priority_over_profile_summary(): void
    {
        $this->member->update([
            'baptism_date' => '2000-03-05',
            'baptism_location_type' => 'home_parish',
        ]);

        Sacrament::factory()->create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $this->family->id,
            'person_id' => $this->person->id,
            'sacrament_type_id' => $this->baptismType->id,
            'recipient_name' => 'John Connor',
            'date_administered' => '2000-06-12',
            'place_administered' => "St Mary's Church",
            'status' => 'registered',
        ]);

        $response = $this->getJson('/api/sacraments/context?'.http_build_query([
            'family_member_id' => $this->member->id,
            'workflow' => 'MATRIMONY',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.sacraments.baptism.evidence_tier', 'BAPTISM_RECORD')
            ->assertJsonPath('data.sacraments.baptism.evidence.date.value', '2000-06-12')
            ->assertJsonPath('data.record_status.baptism_register', SacramentRecordStatus::FOUND);
    }

    #[Test]
    public function register_and_profile_baptism_date_mismatch_is_warning_only(): void
    {
        $this->member->update([
            'baptism_date' => '2000-03-05',
            'baptism_location_type' => 'home_parish',
        ]);

        Sacrament::factory()->create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $this->family->id,
            'person_id' => $this->person->id,
            'sacrament_type_id' => $this->baptismType->id,
            'recipient_name' => 'John Connor',
            'date_administered' => '2000-06-12',
            'recipient_birth_date' => '2000-03-01',
            'status' => 'registered',
        ]);

        $response = $this->getJson('/api/sacraments/context?'.http_build_query([
            'family_member_id' => $this->member->id,
            'workflow' => 'MATRIMONY',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.has_blocking_conflicts', false);

        $conflicts = $response->json('data.conflicts');
        $this->assertTrue(collect($conflicts)->contains('field', 'baptism_date'));
    }

    #[Test]
    public function profile_baptism_exposes_church_and_godparent_fields_without_register(): void
    {
        $this->member->update([
            'baptism_date' => '2011-12-25',
            'baptism_location_type' => 'other',
            'baptism_church_name' => 'Sacred Heart of Jesus Church Demo',
            'baptism_church_address' => 'Kadayal, Kanyakumari, Tamil Nadu, 629101',
            'baptism_godparent_primary' => 'Felix A. Romo',
            'baptism_godparent_secondary' => 'Kristi D. Carr',
        ]);

        $response = $this->getJson('/api/sacraments/context?'.http_build_query([
            'family_member_id' => $this->member->id,
            'workflow' => 'BAPTISM',
            'participant_role' => 'recipient',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.record_status.baptism', SacramentRecordStatus::FOUND)
            ->assertJsonPath('data.record_status.baptism_register', SacramentRecordStatus::NOT_FOUND)
            ->assertJsonPath('data.sacraments.baptism.evidence_tier', 'MEMBER_PROFILE')
            ->assertJsonPath('data.sacraments.baptism.evidence.date.value', '2011-12-25')
            ->assertJsonPath('data.sacraments.baptism.evidence.baptism_church_name', 'Sacred Heart of Jesus Church Demo')
            ->assertJsonPath('data.sacraments.baptism.evidence.baptism_godparent_primary', 'Felix A. Romo')
            ->assertJsonPath('data.sacraments.baptism.evidence.baptism_godparent_secondary', 'Kristi D. Carr')
            ->assertJsonPath('data.sacraments.baptism.evidence.sacrament_id', null);
    }
}
