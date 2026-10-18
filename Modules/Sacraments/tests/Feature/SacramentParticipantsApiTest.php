<?php

namespace Modules\Sacraments\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentParticipant;
use Modules\Sacraments\Models\SacramentType;
use Modules\Tenants\Models\ChurchLeadership;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\LeadershipAssignment;
use Modules\Tenants\Models\LeadershipRole;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\LeadershipAssignmentStatus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 3 — participants create/read, definitions, idempotency, IDOR.
 */
class SacramentParticipantsApiTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Tenant $otherTenant;

    protected User $user;

    protected SacramentType $baptismType;

    protected SacramentType $marriageType;

    protected Family $family;

    protected FamilyMember $member;

    protected FamilyMember $bride;

    protected FamilyMember $groom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
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
        $this->grantPermissions($role);

        $this->otherTenant = Tenant::factory()->create();

        $this->baptismType = SacramentType::factory()->create([
            'name' => 'Baptism',
            'code' => 'BAPTISM',
            'active' => true,
            'requires_minister' => true,
        ]);
        $this->marriageType = SacramentType::factory()->create([
            'name' => 'Marriage',
            'code' => 'MARRIAGE',
            'active' => true,
            'requires_minister' => true,
        ]);

        $this->family = Family::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->member = FamilyMember::factory()->create([
            'family_id' => $this->family->id,
            'first_name' => 'Anna',
            'middle_name' => null,
            'last_name' => 'Recipient',
        ]);
        $this->bride = FamilyMember::factory()->create([
            'family_id' => $this->family->id,
            'first_name' => 'Maria',
            'middle_name' => null,
            'last_name' => 'Bride',
        ]);
        $this->groom = FamilyMember::factory()->create([
            'family_id' => $this->family->id,
            'first_name' => 'Joseph',
            'middle_name' => null,
            'last_name' => 'Groom',
        ]);

        Passport::actingAs($this->user);
    }

    private function grantPermissions(Role $role): void
    {
        $ids = [];
        foreach (['sacraments.view', 'sacraments.create', 'sacraments.edit', 'sacraments.delete'] as $name) {
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

    /** @return array<string, string> */
    private function baptismIdentity(): array
    {
        return [
            'place_administered' => 'St. Mary',
            'recipient_birth_date' => '2015-04-10',
            'recipient_birth_place' => 'Parish City',
            'recipient_gender' => 'female',
            'father_name' => 'John Father',
            'mother_name' => 'Jane Mother',
        ];
    }

    #[Test]
    public function it_returns_sacrament_definitions(): void
    {
        $response = $this->getJson('/api/sacraments/definitions');

        $response->assertOk()
            ->assertJsonPath('success', true);

        // Phase 9 cutover: participants_v1 defaults on (env can still override).
        $this->assertTrue((bool) $response->json('meta.participants_v1'));

        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertContains('BAPTISM', $codes);
        $this->assertContains('MATRIMONY', $codes);

        $matrimony = collect($response->json('data'))->firstWhere('code', 'MATRIMONY');
        $witnessSlot = collect($matrimony['participants'] ?? [])->firstWhere('role', 'witness');
        $this->assertSame(['external'], $witnessSlot['allowed_sources'] ?? null);
    }

    #[Test]
    public function it_creates_baptism_with_member_recipient_and_external_minister(): void
    {
        $payload = array_merge($this->baptismIdentity(), [
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2026-08-01',
            'participants' => [
                [
                    'role' => 'recipient',
                    'source' => 'member',
                    'family_member_id' => $this->member->id,
                    'sort_order' => 0,
                ],
                [
                    'role' => 'father',
                    'source' => 'external',
                    'external_full_name' => 'John Father',
                    'sort_order' => 0,
                ],
                [
                    'role' => 'minister',
                    'source' => 'external',
                    'external_full_name' => 'Fr. Thomas',
                    'external_title' => 'Fr.',
                    'external_minister_role' => 'priest',
                    'sort_order' => 0,
                ],
            ],
        ]);

        $response = $this->postJson('/api/sacraments', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.recipient_name', 'Anna Recipient')
            ->assertJsonPath('data.father_name', 'John Father')
            ->assertJsonPath('data.minister_name', 'Fr. Thomas');

        $id = $response->json('data.id');
        $this->assertDatabaseCount('sacrament_participants', 3);
        $this->assertDatabaseHas('sacrament_participants', [
            'sacrament_id' => $id,
            'role' => 'recipient',
            'source' => 'member',
            'family_member_id' => $this->member->id,
        ]);

        $recipient = SacramentParticipant::where('sacrament_id', $id)->where('role', 'recipient')->first();
        $this->assertNotEmpty($recipient->snapshot_json['full_name']);
        $this->assertSame('Anna Recipient', $recipient->snapshot_json['full_name']);
    }

    #[Test]
    public function it_rejects_cross_tenant_family_member(): void
    {
        $otherFamily = Family::factory()->create(['tenant_id' => $this->otherTenant->id]);
        $otherMember = FamilyMember::factory()->create(['family_id' => $otherFamily->id]);

        $response = $this->postJson('/api/sacraments', array_merge($this->baptismIdentity(), [
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2026-08-01',
            'participants' => [
                [
                    'role' => 'recipient',
                    'source' => 'member',
                    'family_member_id' => $otherMember->id,
                ],
                [
                    'role' => 'minister',
                    'source' => 'external',
                    'external_full_name' => 'Fr. Thomas',
                    'external_title' => 'Fr.',
                    'external_minister_role' => 'priest',
                ],
            ],
        ]));

        $response->assertStatus(422)
            ->assertJsonPath('code', 'cross_tenant_member');
    }

    #[Test]
    public function it_creates_baptism_with_member_recipient_using_canonical_dob_without_recipient_birth_date(): void
    {
        $payload = array_merge($this->baptismIdentity(), [
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2026-08-01',
            'family_member_id' => $this->member->id,
            'participants' => [
                [
                    'role' => 'recipient',
                    'source' => 'member',
                    'family_member_id' => $this->member->id,
                    'sort_order' => 0,
                ],
                [
                    'role' => 'minister',
                    'source' => 'external',
                    'external_full_name' => 'Fr. Thomas',
                    'external_title' => 'Fr.',
                    'external_minister_role' => 'priest',
                    'sort_order' => 0,
                ],
            ],
        ]);
        unset($payload['recipient_birth_date']);

        $this->postJson('/api/sacraments', $payload)
            ->assertCreated();
    }

    #[Test]
    public function it_rejects_marriage_member_without_canonical_date_of_birth(): void
    {
        $brideWithoutDob = FamilyMember::factory()->create([
            'family_id' => $this->family->id,
            'first_name' => 'No',
            'last_name' => 'Dob',
            'date_of_birth' => null,
            'gender' => 'female',
        ]);
        $brideWithoutDob->person?->update(['date_of_birth' => null]);

        $response = $this->postJson('/api/sacraments', [
            'sacrament_type_id' => $this->marriageType->id,
            'date_administered' => '2026-08-10',
            'place_administered' => 'Cathedral',
            'participants' => [
                [
                    'role' => 'bride',
                    'source' => 'member',
                    'family_member_id' => $brideWithoutDob->id,
                    'affiliation_type' => 'home_parish',
                ],
                [
                    'role' => 'groom',
                    'source' => 'external',
                    'external_full_name' => 'External Groom',
                    'external_date_of_birth' => '1990-06-20',
                    'external_gender' => 'male',
                    'affiliation_type' => 'home_parish',
                ],
                [
                    'role' => 'minister',
                    'source' => 'external',
                    'external_full_name' => 'Fr. Wedding',
                    'external_title' => 'Fr.',
                    'external_minister_role' => 'priest',
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['bride_date_of_birth']);
    }

    #[Test]
    public function it_rejects_external_marriage_participant_without_date_of_birth(): void
    {
        $response = $this->postJson('/api/sacraments', [
            'sacrament_type_id' => $this->marriageType->id,
            'date_administered' => '2026-08-10',
            'place_administered' => 'Cathedral',
            'participants' => [
                [
                    'role' => 'bride',
                    'source' => 'external',
                    'external_full_name' => 'External Bride',
                    'external_gender' => 'female',
                    'affiliation_type' => 'home_parish',
                ],
                [
                    'role' => 'groom',
                    'source' => 'external',
                    'external_full_name' => 'External Groom',
                    'external_date_of_birth' => '1990-06-20',
                    'external_gender' => 'male',
                    'affiliation_type' => 'home_parish',
                ],
                [
                    'role' => 'minister',
                    'source' => 'external',
                    'external_full_name' => 'Fr. Wedding',
                    'external_title' => 'Fr.',
                    'external_minister_role' => 'priest',
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['bride_date_of_birth']);
    }

    #[Test]
    public function it_creates_marriage_member_member(): void
    {
        $this->assertMarriageCombo(
            bride: ['source' => 'member', 'family_member_id' => $this->bride->id],
            groom: ['source' => 'member', 'family_member_id' => $this->groom->id],
            expectedBride: 'Maria Bride',
            expectedGroom: 'Joseph Groom'
        );
    }

    #[Test]
    public function it_creates_marriage_member_external(): void
    {
        $this->assertMarriageCombo(
            bride: ['source' => 'member', 'family_member_id' => $this->bride->id],
            groom: ['source' => 'external', 'external_full_name' => 'External Groom'],
            expectedBride: 'Maria Bride',
            expectedGroom: 'External Groom'
        );
    }

    #[Test]
    public function it_creates_marriage_external_member(): void
    {
        $this->assertMarriageCombo(
            bride: ['source' => 'external', 'external_full_name' => 'External Bride'],
            groom: ['source' => 'member', 'family_member_id' => $this->groom->id],
            expectedBride: 'External Bride',
            expectedGroom: 'Joseph Groom'
        );
    }

    #[Test]
    public function it_creates_marriage_external_external_with_affiliation(): void
    {
        $response = $this->postJson('/api/sacraments', [
            'sacrament_type_id' => $this->marriageType->id,
            'date_administered' => '2026-08-10',
            'place_administered' => 'Cathedral',
            'participants' => [
                [
                    'role' => 'bride',
                    'source' => 'external',
                    'external_full_name' => 'Eve External',
                    'external_date_of_birth' => '1992-03-10',
                    'external_gender' => 'female',
                    'affiliation_type' => 'other',
                    'affiliation_parish_name' => 'St. Joseph',
                    'affiliation_diocese_name' => 'Diocese X',
                ],
                [
                    'role' => 'groom',
                    'source' => 'external',
                    'external_full_name' => 'Adam External',
                    'external_date_of_birth' => '1990-06-20',
                    'external_gender' => 'male',
                    'affiliation_type' => 'other',
                    'affiliation_parish_name' => 'Sacred Heart',
                    'affiliation_diocese_name' => 'Diocese Y',
                ],
                [
                    'role' => 'minister',
                    'source' => 'external',
                    'external_full_name' => 'Fr. Celebrant',
                    'external_title' => 'Fr.',
                    'external_minister_role' => 'priest',
                ],
            ],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('sacraments', [
            'id' => $response->json('data.id'),
            'marriage_bride_full_name' => 'Eve External',
            'marriage_bride_diocese_name' => 'Diocese X',
            'marriage_groom_full_name' => 'Adam External',
            'marriage_groom_diocese_name' => 'Diocese Y',
        ]);
    }

    #[Test]
    public function it_requires_diocese_when_marriage_affiliation_other(): void
    {
        $response = $this->postJson('/api/sacraments', [
            'sacrament_type_id' => $this->marriageType->id,
            'date_administered' => '2026-08-10',
            'place_administered' => 'Cathedral',
            'participants' => [
                [
                    'role' => 'bride',
                    'source' => 'external',
                    'external_full_name' => 'Eve',
                    'external_date_of_birth' => '1992-03-10',
                    'external_gender' => 'female',
                    'affiliation_type' => 'other',
                    'affiliation_parish_name' => 'St. Joseph',
                    // missing diocese
                ],
                [
                    'role' => 'groom',
                    'source' => 'external',
                    'external_full_name' => 'Adam',
                    'external_date_of_birth' => '1990-06-20',
                    'external_gender' => 'male',
                    'affiliation_type' => 'home_parish',
                ],
                [
                    'role' => 'minister',
                    'source' => 'external',
                    'external_full_name' => 'Fr. Celebrant',
                    'external_title' => 'Fr.',
                    'external_minister_role' => 'priest',
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'affiliation_diocese_required');
    }

    #[Test]
    public function it_replays_idempotent_create(): void
    {
        $payload = array_merge($this->baptismIdentity(), [
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2026-08-02',
            'participants' => [
                [
                    'role' => 'recipient',
                    'source' => 'external',
                    'external_full_name' => 'Idempotent Child',
                ],
                [
                    'role' => 'minister',
                    'source' => 'external',
                    'external_full_name' => 'Fr. Idem',
                    'external_title' => 'Fr.',
                    'external_minister_role' => 'priest',
                ],
            ],
        ]);

        $headers = ['Idempotency-Key' => 'test-key-baptism-1'];

        $first = $this->postJson('/api/sacraments', $payload, $headers);
        $first->assertCreated();
        $id = $first->json('data.id');

        $second = $this->postJson('/api/sacraments', $payload, $headers);
        $second->assertOk()
            ->assertJsonPath('replay', true)
            ->assertJsonPath('data.id', $id);

        $this->assertSame(1, Sacrament::where('recipient_name', 'Idempotent Child')->count());
    }

    #[Test]
    public function it_conflicts_when_idempotency_key_reused_with_different_body(): void
    {
        $headers = ['Idempotency-Key' => 'test-key-conflict-1'];

        $this->postJson('/api/sacraments', array_merge($this->baptismIdentity(), [
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2026-08-03',
            'participants' => [
                ['role' => 'recipient', 'source' => 'external', 'external_full_name' => 'Child A'],
                [
                    'role' => 'minister',
                    'source' => 'external',
                    'external_full_name' => 'Fr. A',
                    'external_title' => 'Fr.',
                    'external_minister_role' => 'priest',
                ],
            ],
        ]), $headers)->assertCreated();

        $this->postJson('/api/sacraments', array_merge($this->baptismIdentity(), [
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2026-08-03',
            'participants' => [
                ['role' => 'recipient', 'source' => 'external', 'external_full_name' => 'Child B'],
                [
                    'role' => 'minister',
                    'source' => 'external',
                    'external_full_name' => 'Fr. A',
                    'external_title' => 'Fr.',
                    'external_minister_role' => 'priest',
                ],
            ],
        ]), $headers)
            ->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_key_conflict');
    }

    #[Test]
    public function it_warns_on_duplicate_without_ack(): void
    {
        $payload = array_merge($this->baptismIdentity(), [
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2026-08-04',
            'participants' => [
                ['role' => 'recipient', 'source' => 'external', 'external_full_name' => 'Dup Child'],
                [
                    'role' => 'minister',
                    'source' => 'external',
                    'external_full_name' => 'Fr. Dup',
                    'external_title' => 'Fr.',
                    'external_minister_role' => 'priest',
                ],
            ],
        ]);

        $this->postJson('/api/sacraments', $payload)->assertCreated();

        $this->postJson('/api/sacraments', $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'duplicate_sacrament');

        $acked = $payload;
        $acked['acknowledge_duplicate_warning'] = true;
        $this->postJson('/api/sacraments', $acked)->assertCreated();
    }

    #[Test]
    public function profile_summary_alone_does_not_block_baptism_register_create(): void
    {
        $this->member->update([
            'baptism_date' => '2011-12-25',
            'baptism_church_name' => 'Another Parish Church',
            'baptism_location_type' => 'other',
        ]);

        $create = $this->postJson('/api/sacraments', array_merge($this->baptismIdentity(), [
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2011-12-25',
            'place_administered' => 'Another Parish Church',
            'family_id' => $this->family->id,
            'participants' => [
                [
                    'role' => 'recipient',
                    'source' => 'member',
                    'family_member_id' => $this->member->id,
                ],
                [
                    'role' => 'minister',
                    'source' => 'external',
                    'external_full_name' => 'Fr. Profile',
                    'external_title' => 'Fr.',
                    'external_minister_role' => 'priest',
                ],
            ],
        ]));

        $create->assertCreated();
        $this->member->refresh();
        $this->assertSame('2011-12-25', $this->member->baptism_date?->format('Y-m-d'));
        $this->assertSame('Another Parish Church', $this->member->baptism_church_name);
    }

    #[Test]
    public function existing_person_register_entry_is_a_duplicate_even_when_names_differ(): void
    {
        $this->postJson('/api/sacraments', array_merge($this->baptismIdentity(), [
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2011-12-25',
            'family_id' => $this->family->id,
            'participants' => [
                [
                    'role' => 'recipient',
                    'source' => 'member',
                    'family_member_id' => $this->member->id,
                ],
                [
                    'role' => 'minister',
                    'source' => 'external',
                    'external_full_name' => 'Fr. First',
                    'external_title' => 'Fr.',
                    'external_minister_role' => 'priest',
                ],
            ],
        ]))->assertCreated();

        $duplicate = $this->postJson('/api/sacraments', array_merge($this->baptismIdentity(), [
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2012-01-06',
            'recipient_name' => 'A Different Display Name',
            'family_id' => $this->family->id,
            'participants' => [
                [
                    'role' => 'recipient',
                    'source' => 'member',
                    'family_member_id' => $this->member->id,
                ],
                [
                    'role' => 'minister',
                    'source' => 'external',
                    'external_full_name' => 'Fr. Second',
                    'external_title' => 'Fr.',
                    'external_minister_role' => 'priest',
                ],
            ],
        ]));

        $duplicate->assertStatus(422)->assertJsonPath('code', 'duplicate_sacrament');

        $retry = $this->postJson('/api/sacraments', array_merge($this->baptismIdentity(), [
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2012-01-06',
            'family_id' => $this->family->id,
            'acknowledge_duplicate_warning' => true,
            'participants' => [
                [
                    'role' => 'recipient',
                    'source' => 'member',
                    'family_member_id' => $this->member->id,
                ],
                [
                    'role' => 'minister',
                    'source' => 'external',
                    'external_full_name' => 'Fr. Ack',
                    'external_title' => 'Fr.',
                    'external_minister_role' => 'priest',
                ],
            ],
        ]));
        $retry->assertStatus(422)->assertJsonPath('code', 'duplicate_sacrament');
        $this->assertNotEmpty($retry->json('context.existing_sacrament_id'));
    }

    #[Test]
    public function voided_baptism_register_is_not_treated_as_a_live_duplicate(): void
    {
        Sacrament::factory()->create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $this->family->id,
            'person_id' => $this->member->person_id,
            'sacrament_type_id' => $this->baptismType->id,
            'recipient_name' => 'Anna Recipient',
            'date_administered' => '2010-01-01',
            'status' => 'voided',
        ]);

        $this->postJson('/api/sacraments', array_merge($this->baptismIdentity(), [
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2011-12-25',
            'family_id' => $this->family->id,
            'participants' => [
                [
                    'role' => 'recipient',
                    'source' => 'member',
                    'family_member_id' => $this->member->id,
                ],
                [
                    'role' => 'minister',
                    'source' => 'external',
                    'external_full_name' => 'Fr. After Void',
                    'external_title' => 'Fr.',
                    'external_minister_role' => 'priest',
                ],
            ],
        ]))->assertCreated();
    }

    #[Test]
    public function it_supports_internal_leadership_minister(): void
    {
        $leader = ChurchLeadership::create([
            'tenant_id' => $this->tenant->id,
            'full_name' => 'Fr. Parish Priest',
            'role' => 'Pastor',
            'title' => 'Fr.',
            'active' => 1,
            'is_primary' => 1,
            'display_order' => 1,
        ]);

        $response = $this->postJson('/api/sacraments', array_merge($this->baptismIdentity(), [
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2026-08-05',
            'participants' => [
                [
                    'role' => 'recipient',
                    'source' => 'member',
                    'family_member_id' => $this->member->id,
                ],
                [
                    'role' => 'minister',
                    'source' => 'internal_leadership',
                    'church_leadership_id' => $leader->id,
                ],
            ],
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.minister_name', 'Fr. Parish Priest');
    }

    #[Test]
    public function it_supports_internal_leadership_minister_via_assignment(): void
    {
        $profile = ChurchProfile::factory()->create(['tenant_id' => $this->tenant->id]);
        $person = Person::factory()->create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Andrew',
            'middle_name' => null,
            'last_name' => 'Kosmos',
        ]);
        $role = LeadershipRole::query()->where('title', 'Pastor')->first()
            ?? LeadershipRole::factory()->pastor()->create();

        $assignment = LeadershipAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'church_profile_id' => $profile->id,
            'person_id' => $person->id,
            'role_id' => $role->id,
            'status' => LeadershipAssignmentStatus::ACTIVE,
            'start_date' => now()->subMonth()->toDateString(),
        ]);

        $response = $this->postJson('/api/sacraments', array_merge($this->baptismIdentity(), [
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2026-08-05',
            'participants' => [
                [
                    'role' => 'recipient',
                    'source' => 'member',
                    'family_member_id' => $this->member->id,
                ],
                [
                    'role' => 'minister',
                    'source' => 'internal_leadership',
                    'leadership_assignment_id' => $assignment->id,
                ],
            ],
        ]));

        $response->assertCreated();

        $minister = SacramentParticipant::query()
            ->where('sacrament_id', $response->json('data.id'))
            ->where('role', 'minister')
            ->first();

        $this->assertNotNull($minister);
        $this->assertSame('internal_leadership', $minister->source);
        $this->assertSame($assignment->id, $minister->leadership_assignment_id);
        $this->assertNull($minister->church_leadership_id);
        $this->assertSame('Andrew Kosmos', $response->json('data.minister_name'));
    }

    #[Test]
    public function it_stores_marriage_witness_as_external_without_person_or_family(): void
    {
        $personCount = Person::count();
        $memberCount = FamilyMember::count();
        $familyCount = Family::count();

        $response = $this->postJson('/api/sacraments', $this->marriagePayload([
            $this->validWitness(),
        ]));

        $response->assertCreated();
        $sacramentId = $response->json('data.id');

        $this->assertDatabaseHas('sacraments', [
            'id' => $sacramentId,
            'witnesses' => 'Pat Witness',
        ]);

        $witness = SacramentParticipant::query()
            ->where('sacrament_id', $sacramentId)
            ->where('role', 'witness')
            ->first();

        $this->assertNotNull($witness);
        $this->assertSame('external', $witness->source);
        $this->assertNull($witness->person_id);
        $this->assertNull($witness->family_member_id);
        $this->assertSame('Pat Witness', $witness->external_full_name);
        $this->assertSame('12 Oak Lane', $witness->external_address);
        $this->assertSame('female', $witness->external_gender);
        $this->assertSame('5550100', $witness->external_contact_number);
        $this->assertNull($witness->external_date_of_birth);

        $snapshot = $witness->snapshot_json;
        $this->assertSame('external', $snapshot['source'] ?? null);
        $this->assertSame('Pat Witness', $snapshot['full_name'] ?? null);
        $this->assertSame('12 Oak Lane', $snapshot['address'] ?? null);
        $this->assertSame('female', $snapshot['gender'] ?? null);
        $this->assertSame('5550100', $snapshot['contact_number'] ?? null);
        $this->assertNull($snapshot['date_of_birth'] ?? null);

        $this->assertSame($personCount, Person::count());
        $this->assertSame($memberCount, FamilyMember::count());
        $this->assertSame($familyCount, Family::count());
    }

    #[Test]
    public function it_requires_marriage_witness_full_name(): void
    {
        $response = $this->postJson('/api/sacraments', $this->marriagePayload([
            array_merge($this->validWitness(), ['external_full_name' => '']),
        ]));

        $response->assertStatus(422)
            ->assertJsonPath('code', 'invalid_participant_source');
    }

    #[Test]
    public function it_requires_marriage_witness_address(): void
    {
        $this->assertWitnessFieldRequired('external_address');
    }

    #[Test]
    public function it_requires_marriage_witness_gender(): void
    {
        $this->assertWitnessFieldRequired('external_gender');
    }

    #[Test]
    public function it_requires_marriage_witness_contact_number(): void
    {
        $this->assertWitnessFieldRequired('external_contact_number');
    }

    #[Test]
    public function it_does_not_require_marriage_witness_date_of_birth(): void
    {
        $response = $this->postJson('/api/sacraments', $this->marriagePayload([
            array_merge($this->validWitness(), [
                'external_date_of_birth' => '1980-01-01',
            ]),
        ]));

        $response->assertCreated();

        $witness = SacramentParticipant::query()
            ->where('sacrament_id', $response->json('data.id'))
            ->where('role', 'witness')
            ->first();

        $this->assertNotNull($witness);
        $this->assertNull($witness->external_date_of_birth);
        $this->assertNull($witness->snapshot_json['date_of_birth'] ?? null);
    }

    #[Test]
    public function it_rejects_member_source_for_marriage_witness(): void
    {
        $response = $this->postJson('/api/sacraments', $this->marriagePayload([
            [
                'role' => 'witness',
                'source' => 'member',
                'family_member_id' => $this->member->id,
            ],
        ]));

        $response->assertStatus(422)
            ->assertJsonPath('code', 'invalid_participant_source');
    }

    #[Test]
    public function it_rejects_client_supplied_snapshot_json(): void
    {
        $this->postJson('/api/sacraments', [
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2026-08-06',
            'participants' => [
                [
                    'role' => 'recipient',
                    'source' => 'external',
                    'external_full_name' => 'Child',
                    'snapshot_json' => ['full_name' => 'Hacked'],
                ],
                [
                    'role' => 'minister',
                    'source' => 'external',
                    'external_full_name' => 'Fr. X',
                    'external_title' => 'Fr.',
                    'external_minister_role' => 'priest',
                ],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['participants.0.snapshot_json']);
    }

    /**
     * @param  array<string, mixed>  $bride
     * @param  array<string, mixed>  $groom
     */
    private function assertMarriageCombo(array $bride, array $groom, string $expectedBride, string $expectedGroom): void
    {
        $response = $this->postJson('/api/sacraments', [
            'sacrament_type_id' => $this->marriageType->id,
            'date_administered' => '2026-08-10',
            'place_administered' => 'Cathedral',
            'participants' => [
                array_merge(
                    ['role' => 'bride', 'affiliation_type' => 'home_parish'],
                    $this->partyIdentity($bride),
                    $bride
                ),
                array_merge(
                    ['role' => 'groom', 'affiliation_type' => 'home_parish'],
                    $this->partyIdentity($groom, 'male'),
                    $groom
                ),
                [
                    'role' => 'minister',
                    'source' => 'external',
                    'external_full_name' => 'Fr. Wedding',
                    'external_title' => 'Fr.',
                    'external_minister_role' => 'priest',
                ],
            ],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('sacraments', [
            'id' => $response->json('data.id'),
            'marriage_bride_full_name' => $expectedBride,
            'marriage_groom_full_name' => $expectedGroom,
        ]);
        $this->assertSame(3, SacramentParticipant::where('sacrament_id', $response->json('data.id'))->count());
    }

    /** @param array<string, mixed> $party */
    private function partyIdentity(array $party, string $gender = 'female'): array
    {
        if (($party['source'] ?? '') !== 'external') {
            return [];
        }

        return [
            'external_date_of_birth' => '1990-05-01',
            'external_gender' => $gender,
        ];
    }

    /** @param  list<array<string, mixed>>  $witnesses */
    private function marriagePayload(array $witnesses = []): array
    {
        $participants = [
            [
                'role' => 'bride',
                'source' => 'external',
                'external_full_name' => 'Eve External',
                'external_date_of_birth' => '1992-03-10',
                'external_gender' => 'female',
                'affiliation_type' => 'home_parish',
            ],
            [
                'role' => 'groom',
                'source' => 'external',
                'external_full_name' => 'Adam External',
                'external_date_of_birth' => '1990-06-20',
                'external_gender' => 'male',
                'affiliation_type' => 'home_parish',
            ],
            [
                'role' => 'minister',
                'source' => 'external',
                'external_full_name' => 'Fr. Celebrant',
                'external_title' => 'Fr.',
                'external_minister_role' => 'priest',
            ],
        ];

        return [
            'sacrament_type_id' => $this->marriageType->id,
            'date_administered' => '2026-08-10',
            'place_administered' => 'Cathedral',
            'participants' => array_merge($participants, $witnesses),
        ];
    }

    /** @return array<string, mixed> */
    private function validWitness(): array
    {
        return [
            'role' => 'witness',
            'source' => 'external',
            'external_full_name' => 'Pat Witness',
            'external_address' => '12 Oak Lane',
            'external_gender' => 'female',
            'external_contact_number' => '5550100',
        ];
    }

    private function assertWitnessFieldRequired(string $field): void
    {
        $witness = $this->validWitness();
        $witness[$field] = '';

        $response = $this->postJson('/api/sacraments', $this->marriagePayload([$witness]));

        $response->assertStatus(422)
            ->assertJsonPath('code', 'marriage_witness_details_required')
            ->assertJsonPath('context.field', $field);
    }
}
