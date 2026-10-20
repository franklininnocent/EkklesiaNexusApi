<?php

namespace Modules\EcclesiasticalData\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\EcclesiasticalData\Http\Middleware\EnsureEcclesiasticalPermission;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Models\BishopUpdateRequest;
use Modules\EcclesiasticalData\Models\DioceseManagement;
use Modules\EcclesiasticalData\Policies\BishopManagementPolicy;
use Modules\EcclesiasticalData\Policies\BishopUpdateRequestPolicy;
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
 * @group bishop-authorization
 */
class BishopAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected DioceseManagement $diocese;

    protected Tenant $tenant;

    protected Tenant $otherTenant;

    protected BishopManagementPolicy $bishopPolicy;

    protected BishopUpdateRequestPolicy $requestPolicy;

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
        $this->tenant = Tenant::factory()->active()->create();
        $this->otherTenant = Tenant::factory()->active()->create();
        $this->bishopPolicy = app(BishopManagementPolicy::class);
        $this->requestPolicy = app(BishopUpdateRequestPolicy::class);
    }

    #[Test]
    public function super_admin_can_view_bishop_audit_without_explicit_permission(): void
    {
        $user = $this->platformUser([], Role::SUPER_ADMIN);
        $bishop = BishopManagement::factory()->create(['archdiocese_id' => $this->diocese->id]);

        $this->assertTrue($this->bishopPolicy->viewAudit($user, $bishop));
    }

    #[Test]
    public function ekklesia_admin_cannot_view_bishop_audit_even_with_permission(): void
    {
        $user = $this->platformUser(['bishops.view_audit'], Role::EKKLESIA_ADMIN);
        $bishop = BishopManagement::factory()->create(['archdiocese_id' => $this->diocese->id]);

        $this->assertFalse($this->bishopPolicy->viewAudit($user, $bishop));
    }

    #[Test]
    public function platform_viewer_can_view_but_not_create_bishops(): void
    {
        $user = $this->platformUser(['bishops.view']);
        $bishop = BishopManagement::factory()->create(['archdiocese_id' => $this->diocese->id]);

        $this->assertTrue($this->bishopPolicy->viewAny($user));
        $this->assertTrue($this->bishopPolicy->view($user, $bishop));
        $this->assertFalse($this->bishopPolicy->create($user));
        $this->assertFalse($this->bishopPolicy->update($user, $bishop));
    }

    #[Test]
    public function platform_editor_can_create_and_update_bishops(): void
    {
        $user = $this->platformUser(['bishops.view', 'bishops.create', 'bishops.update']);
        $bishop = BishopManagement::factory()->create(['archdiocese_id' => $this->diocese->id]);

        $this->assertTrue($this->bishopPolicy->create($user));
        $this->assertTrue($this->bishopPolicy->update($user, $bishop));
    }

    #[Test]
    public function tenant_user_cannot_access_platform_bishop_management(): void
    {
        $user = $this->tenantUser($this->tenant, ['bishops.view', 'bishops.submit_update_request']);
        $bishop = BishopManagement::factory()->create(['archdiocese_id' => $this->diocese->id]);

        $this->assertFalse($this->bishopPolicy->viewAny($user));
        $this->assertFalse($this->bishopPolicy->view($user, $bishop));
        $this->assertFalse($this->bishopPolicy->create($user));
    }

    #[Test]
    public function church_user_can_submit_update_request_for_own_diocese(): void
    {
        $this->createChurchProfile($this->tenant->id);
        $user = $this->tenantUser($this->tenant, ['bishops.submit_update_request']);

        TenantContextBinder::bind($this->tenant->id, (int) $user->id, $this->tenant->id);

        $this->assertTrue($this->requestPolicy->create($user));
    }

    #[Test]
    public function church_user_cannot_submit_without_linked_diocese(): void
    {
        $user = $this->tenantUser($this->tenant, ['bishops.submit_update_request']);

        TenantContextBinder::bind($this->tenant->id, (int) $user->id, $this->tenant->id);

        $this->assertFalse($this->requestPolicy->create($user));
    }

    #[Test]
    public function church_user_cannot_view_other_tenant_update_requests(): void
    {
        $this->createChurchProfile($this->tenant->id);
        $this->createChurchProfile($this->otherTenant->id);

        $user = $this->tenantUser($this->tenant, ['bishops.view_own_requests']);
        $foreignRequest = $this->createUpdateRequest($this->otherTenant->id, BishopUpdateRequestStatus::Submitted);

        TenantContextBinder::bind($this->tenant->id, (int) $user->id, $this->tenant->id);

        $this->assertFalse($this->requestPolicy->view($user, $foreignRequest));
    }

    #[Test]
    public function church_user_cannot_approve_update_requests(): void
    {
        $this->createChurchProfile($this->tenant->id);
        $user = $this->tenantUser($this->tenant, ['bishops.submit_update_request', 'bishops.view_own_requests']);
        $request = $this->createUpdateRequest($this->tenant->id, BishopUpdateRequestStatus::Submitted);

        TenantContextBinder::bind($this->tenant->id, (int) $user->id, $this->tenant->id);

        $this->assertFalse($this->requestPolicy->approve($user, $request));
        $this->assertFalse($this->requestPolicy->reject($user, $request));
        $this->assertFalse($this->requestPolicy->requestClarification($user, $request));
    }

    #[Test]
    public function ekklesia_reviewer_can_approve_submitted_requests(): void
    {
        $reviewer = $this->platformUser(['bishops.review_requests', 'bishops.approve_requests']);
        $request = $this->createUpdateRequest($this->tenant->id, BishopUpdateRequestStatus::Submitted);

        $this->assertTrue($this->requestPolicy->approve($reviewer, $request));
    }

    #[Test]
    public function ekklesia_reviewer_without_approve_permission_cannot_approve(): void
    {
        $reviewer = $this->platformUser(['bishops.review_requests']);
        $request = $this->createUpdateRequest($this->tenant->id, BishopUpdateRequestStatus::Submitted);

        $this->assertFalse($this->requestPolicy->approve($reviewer, $request));
    }

    #[Test]
    public function church_user_cannot_view_internal_reviewer_notes(): void
    {
        $churchUser = $this->tenantUser($this->tenant, ['bishops.view_own_requests']);
        $request = $this->createUpdateRequest($this->tenant->id, BishopUpdateRequestStatus::Submitted);

        TenantContextBinder::bind($this->tenant->id, (int) $churchUser->id, $this->tenant->id);

        $this->assertFalse($this->requestPolicy->viewInternalNotes($churchUser, $request));

        $reviewer = $this->platformUser(['bishops.review_requests']);
        $this->assertTrue($this->requestPolicy->viewInternalNotes($reviewer, $request));
    }

    #[Test]
    public function legacy_platform_permissions_map_to_modern_bishop_permissions(): void
    {
        $user = $this->platformUser(['view_bishops', 'create_bishops']);
        $bishop = BishopManagement::factory()->create(['archdiocese_id' => $this->diocese->id]);

        $this->assertTrue($this->bishopPolicy->viewAny($user));
        $this->assertTrue($this->bishopPolicy->create($user));
        $this->assertFalse($this->bishopPolicy->update($user, $bishop));
    }

    #[Test]
    public function ecclesiastical_permission_middleware_blocks_tenant_users(): void
    {
        $user = $this->tenantUser($this->tenant, ['bishops.view']);
        $request = Request::create('/api/ecclesiastical/bishops', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = app(EnsureEcclesiasticalPermission::class);
        $response = $middleware->handle($request, fn () => response()->json(['success' => true]), 'bishops.view');

        $this->assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function ecclesiastical_permission_middleware_allows_platform_user_with_permission(): void
    {
        $user = $this->platformUser(['bishops.view']);
        $request = Request::create('/api/ecclesiastical/bishops', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = app(EnsureEcclesiasticalPermission::class);
        $response = $middleware->handle($request, fn () => response()->json(['success' => true]), 'bishops.view');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['success']);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function platformUser(array $permissions, string $roleName = Role::EKKLESIA_MANAGER): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => $roleName, 'tenant_id' => null],
            [
                'description' => 'Platform test role',
                'level' => Role::LEVEL_EKKLESIA_MANAGER,
                'active' => 1,
                'is_custom' => false,
                'role_type' => Role::ROLE_TYPE_PLATFORM,
            ]
        );

        $this->syncPermissions($role, $permissions, Permission::SCOPE_PLATFORM);

        $user = User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
            'active' => 1,
        ]);
        $user->syncRoles([$role->id]);
        $user->clearPermissionsCache();

        return $user->fresh();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function tenantUser(Tenant $tenant, array $permissions): User
    {
        $role = Role::create([
            'name' => 'Church Staff',
            'description' => 'Tenant test role',
            'level' => 3,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $this->syncPermissions($role, $permissions, Permission::SCOPE_TENANT);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'active' => 1,
        ]);
        $user->syncRoles([$role->id]);
        $user->clearPermissionsCache();

        return $user->fresh();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function syncPermissions(Role $role, array $permissions, string $scope): void
    {
        $permissionIds = [];

        foreach ($permissions as $name) {
            $permission = Permission::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'description' => 'Authorization test permission',
                    'module' => 'EcclesiasticalData',
                    'scope' => $scope,
                    'category' => 'bishops',
                    'tenant_id' => null,
                    'is_custom' => false,
                    'active' => 1,
                ]
            );
            $permissionIds[] = $permission->id;
        }

        $role->permissions()->sync($permissionIds);
        $role->clearUsersPermissionCache();
    }

    private function createChurchProfile(int $tenantId): ChurchProfile
    {
        return ChurchProfile::query()->create([
            'tenant_id' => $tenantId,
            'archdiocese_id' => $this->diocese->id,
        ]);
    }

    private function createUpdateRequest(int $tenantId, BishopUpdateRequestStatus $status): BishopUpdateRequest
    {
        $actor = User::factory()->create([
            'tenant_id' => $tenantId,
            'active' => 1,
        ]);

        return BishopUpdateRequest::query()->create([
            'tenant_id' => $tenantId,
            'diocese_id' => $this->diocese->id,
            'request_type' => BishopUpdateRequestType::ChangeCurrentBishop,
            'proposed_bishop_data' => ['full_name' => 'Bishop Request'],
            'proposed_appointment_data' => ['effective_date' => '2026-03-01'],
            'status' => $status,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }
}
