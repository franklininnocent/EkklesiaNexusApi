<?php

namespace Modules\EcclesiasticalData\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\EcclesiasticalData\Models\BishopUpdateRequest;
use Modules\EcclesiasticalData\Services\BishopService;
use Modules\EcclesiasticalData\Services\SuccessionService;
use Modules\EcclesiasticalData\Support\BishopUpdateRequestStatus;
use Modules\EcclesiasticalData\Support\BishopUpdateRequestType;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Models\Denomination;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContextBinder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * @group ecclesiastical
 * @group church-bishop-updates
 */
class ChurchBishopUpdateRequestApiTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected User $churchUser;

    protected \Modules\EcclesiasticalData\Models\DioceseManagement $diocese;

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

        $this->tenant = Tenant::factory()->active()->create();
        $this->diocese = \Modules\EcclesiasticalData\Models\DioceseManagement::factory()->create();

        ChurchProfile::query()->create([
            'tenant_id' => $this->tenant->id,
            'archdiocese_id' => $this->diocese->id,
        ]);

        $bishop = app(BishopService::class)->createPerson([
            'full_name' => 'Bishop Current',
            'archdiocese_id' => $this->diocese->id,
            'status' => 'active',
        ], null, false);
        app(SuccessionService::class)->replaceCurrentOrdinary(
            $this->diocese->id,
            $bishop,
            ['effective_date' => '2018-01-01'],
        );

        $this->churchUser = $this->createChurchStaffUser([
            'bishops.view',
            'bishops.submit_update_request',
            'bishops.view_own_requests',
        ]);

        Passport::actingAs($this->churchUser);
        TenantContextBinder::bind($this->tenant->id, (int) $this->churchUser->id, $this->tenant->id);
    }

    #[Test]
    public function it_returns_diocesan_leadership_for_church(): void
    {
        $response = $this->getJson('/api/tenant/bishop-updates/leadership');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.ordinary.bishop_name', 'Bishop Current')
            ->assertJsonPath('data.diocese_id', $this->diocese->id)
            ->assertJsonPath('data.diocese_name', $this->diocese->name);
    }

    #[Test]
    public function it_creates_draft_update_request(): void
    {
        $response = $this->postJson('/api/tenant/bishop-updates', [
            'request_type' => BishopUpdateRequestType::ChangeCurrentBishop->value,
            'proposed_bishop_data' => ['full_name' => 'Bishop Proposed'],
            'proposed_appointment_data' => ['effective_date' => '2026-03-01'],
            'submission_notes' => 'New appointment announced in bulletin.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', BishopUpdateRequestStatus::Draft->value)
            ->assertJsonPath('data.is_editable', true);

        $this->assertDatabaseHas('bishop_update_requests', [
            'tenant_id' => $this->tenant->id,
            'status' => BishopUpdateRequestStatus::Draft->value,
        ]);
    }

    #[Test]
    public function it_submits_draft_for_review_without_changing_authoritative_data(): void
    {
        $draft = $this->postJson('/api/tenant/bishop-updates', [
            'request_type' => BishopUpdateRequestType::ChangeCurrentBishop->value,
            'proposed_bishop_data' => ['full_name' => 'Bishop Pending'],
            'proposed_appointment_data' => ['effective_date' => '2026-03-01'],
        ])->json('data');

        $response = $this->postJson("/api/tenant/bishop-updates/{$draft['id']}/submit", [
            'version' => $draft['version'],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', BishopUpdateRequestStatus::Submitted->value)
            ->assertJsonPath('data.submitted_by_user.id', $this->churchUser->id)
            ->assertJsonPath('data.submitted_by_user.name', $this->churchUser->name);

        $this->assertDatabaseMissing('bishops', ['full_name' => 'Bishop Pending']);
    }

    #[Test]
    public function it_lists_submitter_and_reviewer_names_for_church_users(): void
    {
        $draft = $this->postJson('/api/tenant/bishop-updates', [
            'request_type' => BishopUpdateRequestType::CorrectInformation->value,
            'proposed_bishop_data' => ['full_name' => 'Bishop Listed'],
        ])->json('data');

        $this->postJson("/api/tenant/bishop-updates/{$draft['id']}/submit", [
            'version' => $draft['version'],
        ])->assertOk();

        $response = $this->getJson('/api/tenant/bishop-updates');

        $response->assertOk()
            ->assertJsonPath('data.data.0.submitted_by_user.name', $this->churchUser->name)
            ->assertJsonPath('data.data.0.proposed_bishop_data.full_name', 'Bishop Listed');
    }

    #[Test]
    public function it_lists_own_tenant_requests_only(): void
    {
        $otherTenant = Tenant::factory()->active()->create();
        $otherUser = User::factory()->create([
            'tenant_id' => $otherTenant->id,
            'active' => 1,
        ]);
        BishopUpdateRequest::query()->create([
            'tenant_id' => $otherTenant->id,
            'diocese_id' => ChurchProfile::query()->where('tenant_id', $this->tenant->id)->value('archdiocese_id'),
            'request_type' => BishopUpdateRequestType::ChangeCurrentBishop,
            'proposed_bishop_data' => ['full_name' => 'Foreign'],
            'status' => BishopUpdateRequestStatus::Submitted,
            'created_by' => $otherUser->id,
            'updated_by' => $otherUser->id,
        ]);

        $this->postJson('/api/tenant/bishop-updates', [
            'request_type' => BishopUpdateRequestType::CorrectInformation->value,
            'proposed_bishop_data' => ['full_name' => 'Bishop Local'],
        ]);

        $response = $this->getJson('/api/tenant/bishop-updates');

        $response->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    #[Test]
    public function it_allows_resubmit_after_clarification_requested(): void
    {
        $draft = $this->postJson('/api/tenant/bishop-updates', [
            'request_type' => BishopUpdateRequestType::CorrectInformation->value,
            'proposed_bishop_data' => ['full_name' => 'Bishop Clarify'],
        ])->json('data');

        $request = BishopUpdateRequest::query()->findOrFail($draft['id']);
        $request->update([
            'status' => BishopUpdateRequestStatus::ChangesRequested,
            'submitter_feedback' => 'Please include source link.',
            'version' => 2,
        ]);

        $response = $this->putJson("/api/tenant/bishop-updates/{$request->id}", [
            'version' => 2,
            'source_reference' => 'https://diocese.example/bulletin',
            'proposed_bishop_data' => ['full_name' => 'Bishop Clarify Updated'],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.is_editable', true)
            ->assertJsonPath('data.proposed_bishop_data.full_name', 'Bishop Clarify Updated');
    }

    #[Test]
    public function it_hides_internal_reviewer_notes_from_church_users(): void
    {
        $draft = $this->postJson('/api/tenant/bishop-updates', [
            'request_type' => BishopUpdateRequestType::CorrectInformation->value,
            'proposed_bishop_data' => ['full_name' => 'Bishop Hidden Notes'],
        ])->json('data');

        BishopUpdateRequest::query()->where('id', $draft['id'])->update([
            'internal_reviewer_notes' => 'Internal only',
            'submitter_feedback' => 'Visible to church',
            'status' => BishopUpdateRequestStatus::ChangesRequested,
        ]);

        $response = $this->getJson("/api/tenant/bishop-updates/{$draft['id']}");

        $response->assertOk()
            ->assertJsonPath('data.submitter_feedback', 'Visible to church')
            ->assertJsonMissingPath('data.internal_reviewer_notes');
    }

    #[Test]
    public function it_ignores_target_bishop_id_for_new_bishop_suggestions(): void
    {
        $currentId = $this->getJson('/api/tenant/bishop-updates/leadership')->json('data.ordinary.bishop_id');

        $response = $this->postJson('/api/tenant/bishop-updates', [
            'request_type' => BishopUpdateRequestType::ChangeCurrentBishop->value,
            'target_bishop_id' => $currentId,
            'proposed_bishop_data' => ['full_name' => 'Bishop Successor'],
            'proposed_appointment_data' => [
                'effective_date' => '2026-03-01',
                'end_reason' => 'retirement',
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.target_bishop_id', null);
    }

    #[Test]
    public function it_rejects_a_second_in_flight_ordinary_suggestion_for_the_same_diocese(): void
    {
        $this->postJson('/api/tenant/bishop-updates', [
            'request_type' => BishopUpdateRequestType::ChangeCurrentBishop->value,
            'proposed_bishop_data' => ['full_name' => 'Bishop First'],
            'proposed_appointment_data' => ['effective_date' => '2026-03-01'],
        ])->assertCreated();

        $this->postJson('/api/tenant/bishop-updates', [
            'request_type' => BishopUpdateRequestType::ChangeCurrentBishop->value,
            'proposed_bishop_data' => ['full_name' => 'Bishop Second'],
            'proposed_appointment_data' => ['effective_date' => '2026-04-01'],
        ])->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function it_stores_a_pending_photo_without_creating_a_master_bishop(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        $draft = $this->postJson('/api/tenant/bishop-updates', [
            'request_type' => BishopUpdateRequestType::ChangeCurrentBishop->value,
            'proposed_bishop_data' => [
                'full_name' => 'Bishop With Photo',
                'given_name' => 'Photo',
                'biography' => 'Suggested from parish bulletin.',
            ],
            'proposed_appointment_data' => [
                'effective_date' => '2026-03-01',
                'end_reason' => 'transfer',
            ],
        ])->json('data');

        $response = $this->postJson("/api/tenant/bishop-updates/{$draft['id']}/photo", [
            'image' => \Illuminate\Http\UploadedFile::fake()->image('bishop.jpg', 120, 120),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.proposed_bishop_data.full_name', 'Bishop With Photo')
            ->assertJsonPath('data.proposed_bishop_data.given_name', 'Photo');

        $this->assertNotEmpty($response->json('data.proposed_bishop_data.pending_photo_path'));
        $this->assertNotEmpty($response->json('data.pending_photo_public_url'));
        $this->assertDatabaseMissing('bishops', ['full_name' => 'Bishop With Photo']);
    }

    #[Test]
    public function it_strips_disallowed_proposed_bishop_fields(): void
    {
        $response = $this->postJson('/api/tenant/bishop-updates', [
            'request_type' => BishopUpdateRequestType::CorrectInformation->value,
            'proposed_bishop_data' => [
                'full_name' => 'Bishop Sanitized',
                'status' => 'active',
                'photo_path' => 'ecclesiastical/bishops/1/hack.jpg',
                'archdiocese_id' => 999,
            ],
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function church_user_cannot_approve_platform_review_endpoint(): void
    {
        $draft = $this->postJson('/api/tenant/bishop-updates', [
            'request_type' => BishopUpdateRequestType::CorrectInformation->value,
            'proposed_bishop_data' => ['full_name' => 'Bishop Hidden'],
        ])->json('data');

        $this->postJson("/api/tenant/bishop-updates/{$draft['id']}/submit", [
            'version' => $draft['version'],
        ])->assertOk();

        $this->postJson("/api/ecclesiastical/bishop-update-requests/{$draft['id']}/approve", [
            'version' => 2,
        ])->assertForbidden();
    }

    #[Test]
    public function church_user_cannot_upload_photo_for_another_tenant_request(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        $otherTenant = Tenant::factory()->active()->create();
        $otherUser = User::factory()->create([
            'tenant_id' => $otherTenant->id,
            'active' => 1,
        ]);
        $foreign = BishopUpdateRequest::query()->create([
            'tenant_id' => $otherTenant->id,
            'diocese_id' => $this->diocese->id,
            'request_type' => BishopUpdateRequestType::CorrectInformation,
            'proposed_bishop_data' => ['full_name' => 'Foreign Photo'],
            'status' => BishopUpdateRequestStatus::Draft,
            'created_by' => $otherUser->id,
            'updated_by' => $otherUser->id,
        ]);

        $this->postJson("/api/tenant/bishop-updates/{$foreign->id}/photo", [
            'image' => \Illuminate\Http\UploadedFile::fake()->image('other.jpg', 120, 120),
        ])->assertForbidden();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createChurchStaffUser(array $permissions): User
    {
        $role = Role::create([
            'name' => 'Church Staff',
            'description' => 'Church bishop workflow staff',
            'level' => 3,
            'active' => 1,
            'tenant_id' => $this->tenant->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $permissionIds = [];
        foreach ($permissions as $name) {
            $permission = Permission::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'description' => 'Church bishop API test permission',
                    'module' => 'EcclesiasticalData',
                    'scope' => Permission::SCOPE_TENANT,
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
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
            'active' => 1,
        ]);
        $user->syncRoles([$role->id]);
        $user->clearPermissionsCache();

        return $user->fresh();
    }
}
