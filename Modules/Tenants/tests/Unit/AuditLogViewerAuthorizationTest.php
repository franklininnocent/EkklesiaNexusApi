<?php

namespace Modules\Tenants\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\AuditLogViewerAuthorization;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditLogViewerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function primary_tenant_admin_can_view_tenant_audit(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'is_primary_admin' => true,
        ]);

        $this->assertTrue(AuditLogViewerAuthorization::canViewTenantAudit($user));
    }

    #[Test]
    public function secondary_tenant_admin_cannot_view_tenant_audit(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'is_primary_admin' => false,
        ]);

        $this->assertFalse(AuditLogViewerAuthorization::canViewTenantAudit($user));
    }

    #[Test]
    public function super_admin_can_view_platform_complete_audit(): void
    {
        $user = $this->platformUser(Role::SUPER_ADMIN);

        $this->assertTrue(AuditLogViewerAuthorization::canViewPlatformCompleteAudit($user));
    }

    #[Test]
    public function ekklesia_admin_cannot_view_platform_complete_audit(): void
    {
        $user = $this->platformUser(Role::EKKLESIA_ADMIN);

        $this->assertFalse(AuditLogViewerAuthorization::canViewPlatformCompleteAudit($user));
    }

    #[Test]
    public function support_admin_can_view_support_operational_audit(): void
    {
        $user = $this->platformUser(Role::SUPPORT_ADMIN);

        $this->assertTrue(AuditLogViewerAuthorization::canViewSupportOperationalAudit($user));
    }

    #[Test]
    public function ekklesia_admin_cannot_view_support_operational_audit(): void
    {
        $user = $this->platformUser(Role::EKKLESIA_ADMIN);

        $this->assertFalse(AuditLogViewerAuthorization::canViewSupportOperationalAudit($user));
    }

    private function platformUser(string $roleName): User
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

        $user = User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
            'active' => 1,
        ]);
        $user->syncRoles([$role->id]);

        return $user->fresh();
    }
}
