<?php

namespace Modules\EcclesiasticalData\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Models\DioceseManagement;
use Modules\EcclesiasticalData\Models\EcclesiasticalAuditLog;
use Modules\EcclesiasticalData\Services\BishopService;
use Modules\EcclesiasticalData\Services\BishopUpdateRequestService;
use Modules\EcclesiasticalData\Services\SuccessionService;
use Modules\EcclesiasticalData\Support\BishopUpdateRequestStatus;
use Modules\EcclesiasticalData\Support\BishopUpdateRequestType;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Models\Denomination;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * @group ecclesiastical
 * @group admin-bishop-review
 */
class AdminBishopUpdateRequestApiTest extends TestCase
{
    use RefreshDatabase;

    protected DioceseManagement $diocese;

    protected User $reviewer;

    protected Tenant $churchTenant;

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

        $this->diocese = DioceseManagement::factory()->create();
        $this->churchTenant = Tenant::factory()->active()->create();
        ChurchProfile::query()->create([
            'tenant_id' => $this->churchTenant->id,
            'archdiocese_id' => $this->diocese->id,
        ]);

        $this->reviewer = $this->createReviewer();
        Passport::actingAs($this->reviewer);
    }

    #[Test]
    public function it_lists_submitted_requests_in_review_queue(): void
    {
        $this->createSubmittedRequest('Bishop Queue');

        $response = $this->getJson('/api/ecclesiastical/bishop-update-requests?status=submitted');

        $response->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.proposed_bishop_data.full_name', 'Bishop Queue');
    }

    #[Test]
    public function it_shows_diff_for_review(): void
    {
        $bishop = app(BishopService::class)->createPerson([
            'full_name' => 'Bishop Current',
            'archdiocese_id' => $this->diocese->id,
        ], null, false);
        app(SuccessionService::class)->replaceCurrentOrdinary(
            $this->diocese->id,
            $bishop,
            ['effective_date' => '2018-01-01'],
        );

        $request = $this->createSubmittedRequest('Bishop Proposed');

        $response = $this->getJson("/api/ecclesiastical/bishop-update-requests/{$request->id}");

        $response->assertOk()
            ->assertJsonPath('data.diff.current_leadership.ordinary.bishop_name', 'Bishop Current')
            ->assertJsonPath('data.diff.proposed_bishop.full_name', 'Bishop Proposed')
            ->assertJsonStructure(['data' => ['request', 'diff']]);
    }

    #[Test]
    public function it_approves_and_applies_submitted_request(): void
    {
        app(SuccessionService::class)->replaceCurrentOrdinary(
            $this->diocese->id,
            app(BishopService::class)->createPerson([
                'full_name' => 'Bishop Old',
                'archdiocese_id' => $this->diocese->id,
            ], null, false),
            ['effective_date' => '2018-01-01'],
        );

        $request = $this->createSubmittedRequest('Bishop Approved');

        $response = $this->postJson("/api/ecclesiastical/bishop-update-requests/{$request->id}/approve", [
            'version' => 2,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', BishopUpdateRequestStatus::Applied->value);

        $leadership = $this->getJson("/api/ecclesiastical/dioceses/{$this->diocese->id}/leadership");
        $leadership->assertJsonPath('data.ordinary.bishop_name', 'Bishop Approved');
    }

    #[Test]
    public function it_rejects_submitted_request(): void
    {
        $request = $this->createSubmittedRequest('Bishop Reject');

        $response = $this->postJson("/api/ecclesiastical/bishop-update-requests/{$request->id}/reject", [
            'version' => 2,
            'reason' => 'Insufficient documentation.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', BishopUpdateRequestStatus::Rejected->value);
    }

    #[Test]
    public function it_approves_a_new_bishop_suggestion_and_records_succession(): void
    {
        $incumbent = app(BishopService::class)->createPerson([
            'full_name' => 'Bishop Incumbent',
            'archdiocese_id' => $this->diocese->id,
            'status' => 'active',
        ], null, false);
        app(SuccessionService::class)->replaceCurrentOrdinary(
            $this->diocese->id,
            $incumbent,
            ['effective_date' => '2018-01-01'],
        );

        ChurchProfile::query()
            ->where('tenant_id', $this->churchTenant->id)
            ->update(['bishop_id' => $incumbent->id]);

        $service = app(BishopUpdateRequestService::class);
        $submitterId = $this->createChurchSubmitterId();
        $draft = $service->createDraft(
            $this->churchTenant->id,
            $this->diocese->id,
            BishopUpdateRequestType::ChangeCurrentBishop,
            ['full_name' => 'Bishop Successor', 'given_name' => 'Successor'],
            ['effective_date' => '2026-03-01', 'end_reason' => 'retirement'],
            $submitterId,
        );
        $submitted = $service->submit($draft->id, $this->churchTenant->id, (int) $draft->fresh()->version, $submitterId);

        $response = $this->postJson("/api/ecclesiastical/bishop-update-requests/{$submitted->id}/approve", [
            'version' => $submitted->version,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', BishopUpdateRequestStatus::Applied->value);

        $successor = BishopManagement::query()->where('full_name', 'Bishop Successor')->first();
        $this->assertNotNull($successor);
        $this->assertTrue((bool) $successor->is_current);
        $this->assertSame('active', $successor->status);

        $incumbent->refresh();
        $this->assertFalse((bool) $incumbent->is_current);
        $this->assertSame('retired', $incumbent->status);

        $this->getJson("/api/ecclesiastical/dioceses/{$this->diocese->id}/leadership")
            ->assertJsonPath('data.ordinary.bishop_name', 'Bishop Successor');

        $this->assertDatabaseHas('church_profiles', [
            'tenant_id' => $this->churchTenant->id,
            'bishop_id' => $successor->id,
        ]);

        $this->assertTrue(
            EcclesiasticalAuditLog::query()
                ->where('entity_id', $submitted->id)
                ->where('action', 'bishop_suggestion_approved')
                ->exists()
        );
    }

    #[Test]
    public function it_applies_pending_photo_only_on_approval(): void
    {
        Storage::fake('public');

        $service = app(BishopUpdateRequestService::class);
        $submitterId = $this->createChurchSubmitterId();
        $draft = $service->createDraft(
            $this->churchTenant->id,
            $this->diocese->id,
            BishopUpdateRequestType::ChangeCurrentBishop,
            ['full_name' => 'Bishop Portrait'],
            ['effective_date' => '2026-03-01'],
            $submitterId,
        );

        $withPhoto = $service->uploadPendingPhoto(
            $draft->id,
            $this->churchTenant->id,
            UploadedFile::fake()->image('portrait.jpg', 140, 140),
            $submitterId,
        );

        $this->assertDatabaseMissing('bishops', ['full_name' => 'Bishop Portrait']);

        $submitted = $service->submit($withPhoto->id, $this->churchTenant->id, (int) $withPhoto->version, $submitterId);

        $this->postJson("/api/ecclesiastical/bishop-update-requests/{$submitted->id}/approve", [
            'version' => $submitted->version,
        ])->assertOk();

        $bishop = BishopManagement::query()->where('full_name', 'Bishop Portrait')->first();
        $this->assertNotNull($bishop);
        $this->assertNotNull($bishop->photo_path);
    }

    #[Test]
    public function it_leaves_master_data_unchanged_when_rejecting(): void
    {
        $request = $this->createSubmittedRequest('Bishop Rejected Person');

        $this->postJson("/api/ecclesiastical/bishop-update-requests/{$request->id}/reject", [
            'version' => $request->version,
            'reason' => 'Could not verify the announcement.',
        ])->assertOk()
            ->assertJsonPath('data.reviewer_comments', 'Could not verify the announcement.');

        $this->assertDatabaseMissing('bishops', ['full_name' => 'Bishop Rejected Person']);
        $this->assertTrue(
            EcclesiasticalAuditLog::query()
                ->where('entity_id', $request->id)
                ->where('action', 'bishop_suggestion_rejected')
                ->exists()
        );
    }

    #[Test]
    public function it_lists_pending_submitted_and_under_review_requests(): void
    {
        $this->createSubmittedRequest('Bishop Pending Filter');

        $this->getJson('/api/ecclesiastical/bishop-update-requests?status=pending')
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    private function createSubmittedRequest(string $bishopName)
    {
        $service = app(BishopUpdateRequestService::class);
        $submitterId = $this->createChurchSubmitterId();
        $draft = $service->createDraft(
            $this->churchTenant->id,
            $this->diocese->id,
            BishopUpdateRequestType::ChangeCurrentBishop,
            ['full_name' => $bishopName],
            ['effective_date' => '2026-03-01'],
            $submitterId,
        );

        return $service->submit($draft->id, $this->churchTenant->id, (int) $draft->fresh()->version, $submitterId);
    }

    private function createChurchSubmitterId(): int
    {
        return (int) User::factory()->create(['tenant_id' => $this->churchTenant->id])->id;
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
                    'description' => 'Admin review test permission',
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

        return $user->fresh();
    }
}
