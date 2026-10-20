<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Family\Models\Family;
use Modules\Subscriptions\Models\TenantEntitlementOverride;
use Modules\Subscriptions\Models\TenantSubscription;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Models\TenantSubscriptionAudit;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class TenantPlanAssignmentApiTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    #[Test]
    public function ekklesia_admin_assigns_plan_and_history_is_preserved(): void
    {
        $tenant = Tenant::factory()->create(['plan' => 'free']);
        $this->assignPlan($tenant, 'STARTER');
        $this->asEkklesiaAdmin();

        $this->postJson("/api/admin/subscriptions/tenants/{$tenant->id}/assign", [
            'plan_id' => $this->plan('STANDARD')->id,
            'billing_interval' => 'ANNUAL',
            'reason' => 'Parish upgraded after council meeting',
        ])->assertOk()->assertJsonPath('data.plan.code', 'STANDARD');

        $tenant->refresh();
        $this->assertSame('standard', $tenant->plan);
        $this->assertSame(1, TenantSubscription::query()->forTenant($tenant->id)->current()->count());
        $this->assertSame(1, TenantSubscription::query()->forTenant($tenant->id)->where('record_status', TenantSubscription::RECORD_SUPERSEDED)->count());
        $this->assertSame('29990.00', (string) $this->currentSubscription($tenant)->contracted_price);
        $this->assertTrue(TenantSubscriptionAudit::query()->where('tenant_id', $tenant->id)->where('operation', 'plan_changed')->exists());
    }

    #[Test]
    public function downgrade_requires_confirmation_and_never_deletes_data(): void
    {
        $tenant = Tenant::factory()->create();
        $this->assignPlan($tenant, 'PROFESSIONAL');
        Family::factory()->count(3)->create(['tenant_id' => $tenant->id]);
        $this->asSuperAdmin();

        $payload = ['plan_id' => $this->plan('STARTER')->id, 'reason' => 'Budget cut'];

        $this->postJson("/api/admin/subscriptions/tenants/{$tenant->id}/preview", ['plan_id' => $payload['plan_id']])
            ->assertOk()
            ->assertJsonPath('data.is_downgrade', true)
            ->assertJsonPath('data.data_preserved', true);

        $this->postJson("/api/admin/subscriptions/tenants/{$tenant->id}/assign", $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'PLAN_CHANGE_REQUIRES_CONFIRMATION');
        $this->assertSame('PROFESSIONAL', $this->currentSubscription($tenant)->plan->code);

        $this->postJson("/api/admin/subscriptions/tenants/{$tenant->id}/assign", $payload + ['confirm_impact' => true])
            ->assertOk();

        $this->assertSame('STARTER', $this->currentSubscription($tenant)->plan->code);
        $this->assertSame(3, Family::query()->where('tenant_id', $tenant->id)->count());
    }

    #[Test]
    public function assign_requires_reason_and_rejects_hidden_legacy_plans(): void
    {
        $tenant = Tenant::factory()->create();
        $this->asSuperAdmin();

        $this->postJson("/api/admin/subscriptions/tenants/{$tenant->id}/assign", ['plan_id' => $this->plan('STANDARD')->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->postJson("/api/admin/subscriptions/tenants/{$tenant->id}/assign", [
            'plan_id' => $this->plan('LEGACY_FREE')->id,
            'reason' => 'try legacy',
        ])->assertStatus(422)->assertJsonPath('code', 'PLAN_NOT_AVAILABLE');
    }

    #[Test]
    public function body_tenant_id_is_ignored_route_tenant_is_used(): void
    {
        $target = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        $this->assignPlan($other, 'STARTER');
        $this->asSuperAdmin();

        $this->postJson("/api/admin/subscriptions/tenants/{$target->id}/assign", [
            'plan_id' => $this->plan('STANDARD')->id,
            'tenant_id' => $other->id,
            'reason' => 'Targeted change',
            'confirm_impact' => true,
        ])->assertOk();

        $this->assertSame('STANDARD', $this->currentSubscription($target)->plan->code);
        $this->assertSame('STARTER', $this->currentSubscription($other)->plan->code);
    }

    #[Test]
    public function overrides_are_granted_and_revoked_only_for_the_route_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $this->assignPlan($tenantA, 'STARTER');
        $this->assignPlan($tenantB, 'STARTER');
        $this->asSuperAdmin();
        $this->useEntitlementEngine('enforce');

        $overrideId = $this->postJson("/api/admin/subscriptions/tenants/{$tenantA->id}/overrides", [
            'feature_code' => 'api_access',
            'mode' => 'enable',
            'reason' => 'Pilot integration',
        ])->assertCreated()->json('data.id');

        $this->assertTrue($tenantA->fresh()->hasFeature('api_access'));
        $this->assertFalse($tenantB->fresh()->hasFeature('api_access'));

        $this->postJson("/api/admin/subscriptions/tenants/{$tenantB->id}/overrides/{$overrideId}/revoke", ['reason' => 'Wrong tenant'])
            ->assertNotFound();

        $this->postJson("/api/admin/subscriptions/tenants/{$tenantA->id}/overrides/{$overrideId}/revoke", ['reason' => 'Pilot ended'])
            ->assertOk();
        $this->assertNotNull(TenantEntitlementOverride::query()->find($overrideId)?->revoked_at);
        $this->assertFalse($tenantA->fresh()->hasFeature('api_access'));
    }

    #[Test]
    public function core_features_cannot_be_disabled_by_override(): void
    {
        $tenant = Tenant::factory()->create();
        $this->assignPlan($tenant, 'STARTER');
        $this->asSuperAdmin();

        $this->postJson("/api/admin/subscriptions/tenants/{$tenant->id}/overrides", [
            'feature_code' => 'FAMILIES',
            'mode' => 'DISABLE',
            'reason' => 'Should not be possible',
        ])->assertStatus(422);
    }

    #[Test]
    public function scheduled_change_is_pending_until_applied(): void
    {
        $tenant = Tenant::factory()->create();
        $this->assignPlan($tenant, 'STARTER');
        $this->asSuperAdmin();

        $this->postJson("/api/admin/subscriptions/tenants/{$tenant->id}/assign", [
            'plan_id' => $this->plan('STANDARD')->id,
            'scheduled_for' => now()->addDays(10)->toIso8601String(),
            'reason' => 'Next quarter',
        ])->assertOk()->assertJsonPath('message', 'Plan change scheduled.');

        $this->assertSame('STARTER', $this->currentSubscription($tenant)->plan->code);
        $this->assertSame(1, TenantSubscription::query()->forTenant($tenant->id)->where('record_status', TenantSubscription::RECORD_PENDING)->count());

        $this->postJson("/api/admin/subscriptions/tenants/{$tenant->id}/pending/cancel", ['reason' => 'Changed mind'])
            ->assertOk()->assertJsonPath('cancelled', true);
        $this->assertSame(0, TenantSubscription::query()->forTenant($tenant->id)->where('record_status', TenantSubscription::RECORD_PENDING)->count());
    }

    #[Test]
    public function legacy_upgrade_endpoint_delegates_to_catalog(): void
    {
        $tenant = Tenant::factory()->create(['plan' => 'free']);
        $this->asSuperAdmin();

        $this->postJson("/api/tenant/{$tenant->id}/subscription/upgrade", ['plan' => 'standard', 'subscription_duration_months' => 12])
            ->assertOk();
        $this->assertSame('STANDARD', $this->currentSubscription($tenant)->plan->code);

        $this->postJson("/api/tenant/{$tenant->id}/subscription/upgrade", ['plan' => 'basic'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PLAN_NOT_AVAILABLE');

        $this->postJson("/api/tenant/{$tenant->id}/subscription/upgrade", ['plan' => 'starter'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'PLAN_CHANGE_REQUIRES_CONFIRMATION');
        $this->assertSame('STANDARD', $this->currentSubscription($tenant)->plan->code);
    }

    #[Test]
    public function legacy_plan_crud_is_blocked_for_catalog_plans(): void
    {
        $this->asSuperAdmin();
        $starter = $this->plan('STARTER');

        $this->putJson("/api/subscription/plans/{$starter->id}", ['price' => 1])->assertStatus(409);
        $this->deleteJson("/api/subscription/plans/{$starter->id}")->assertStatus(409);
        $this->postJson('/api/subscription/plans', [
            'key' => 'shadow', 'name' => 'Shadow', 'price' => 0, 'max_users' => 1, 'max_storage_mb' => 1,
        ])->assertStatus(409);
    }

    #[Test]
    public function tenant_update_cannot_mass_assign_plan_or_entitlements(): void
    {
        $tenant = Tenant::factory()->create(['plan' => 'free', 'features' => ['donations']]);
        $this->assignPlan($tenant, 'STARTER');
        $before = $tenant->fresh()->only(['plan', 'features', 'max_users', 'max_storage_mb']);
        $this->asSuperAdmin();

        $this->putJson("/api/tenant/{$tenant->id}", [
            'plan' => 'enterprise',
            'features' => ['api_access', 'custom_branding'],
            'max_users' => 5000,
            'max_storage_mb' => 900000,
        ]);

        $this->assertSame($before, $tenant->fresh()->only(['plan', 'features', 'max_users', 'max_storage_mb']));
    }
}
