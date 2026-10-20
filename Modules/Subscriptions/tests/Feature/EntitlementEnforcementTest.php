<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\User;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Subscriptions\Exceptions\SubscriptionException;
use Modules\Subscriptions\Models\Feature;
use Modules\Subscriptions\Models\TenantEntitlementOverride;
use Modules\Subscriptions\Services\Entitlements\EntitlementResolver;
use Modules\Subscriptions\Services\LimitEnforcementService;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\Concerns\InteractsWithTenantContext;
use Tests\TestCase;

class EntitlementEnforcementTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithSubscriptions;
    use InteractsWithTenantContext;
    use RefreshDatabase;

    #[Test]
    public function enforce_mode_blocks_family_limit_at_guard_with_structured_error(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->assignPlan($ctx['tenant'], 'STARTER');
        $this->limitOverride($ctx['tenant'], 'FAMILY_LIMIT', 1);
        Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $this->useEntitlementEngine('enforce');

        try {
            DB::transaction(fn () => app(LimitEnforcementService::class)->assertCanAdd($ctx['tenant'], 'FAMILY_LIMIT', 1));
            $this->fail('Expected family limit to block a second household.');
        } catch (SubscriptionException $e) {
            $this->assertSame(SubscriptionException::ENTITLEMENT_LIMIT_REACHED, $e->errorCode);
            $this->assertSame('FAMILY_LIMIT', $e->context['feature']);
            $this->assertSame(1, $e->context['limit']);
            $this->assertSame(1, $e->context['current_usage']);
        }

        $this->assertSame(1, Family::query()->where('tenant_id', $ctx['tenant']->id)->count());
    }

    #[Test]
    public function enforce_mode_blocks_new_staff_user_over_the_plan_limit(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->bindTenantContext($ctx['user']);
        $this->assignPlan($ctx['tenant'], 'STARTER');
        $this->limitOverride($ctx['tenant'], 'STAFF_USER_LIMIT', 1);
        $this->useEntitlementEngine('enforce');

        $this->postJson('/api/users', [
            'name' => 'Extra Staff',
            'email' => 'extra-staff-'.uniqid().'@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role_ids' => [$ctx['role']->id],
        ])->assertForbidden()
            ->assertJsonPath('code', SubscriptionException::ENTITLEMENT_LIMIT_REACHED)
            ->assertJsonPath('feature', 'STAFF_USER_LIMIT');

        $this->assertSame(1, User::query()->where('tenant_id', $ctx['tenant']->id)->count());
    }

    #[Test]
    public function shadow_mode_never_blocks(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->bindTenantContext($ctx['user']);
        $this->assignPlan($ctx['tenant'], 'STARTER');
        $this->limitOverride($ctx['tenant'], 'FAMILY_LIMIT', 1);
        Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        $this->useEntitlementEngine('shadow');

        $this->postJson('/api/families', ['family_name' => 'Shadow Allowed', 'status' => 'active'])->assertCreated();
    }

    #[Test]
    public function over_limit_tenant_keeps_access_to_existing_data(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->bindTenantContext($ctx['user']);
        $this->assignPlan($ctx['tenant'], 'STARTER');
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        $this->limitOverride($ctx['tenant'], 'FAMILY_LIMIT', 1);
        $this->useEntitlementEngine('enforce');

        $this->getJson('/api/families')->assertOk();
        $this->getJson('/api/families/'.$family->id)->assertOk();
    }

    #[Test]
    public function exempt_flows_are_not_blocked(): void
    {
        $tenant = Tenant::factory()->create();
        $this->assignPlan($tenant, 'STARTER');
        $this->limitOverride($tenant, 'FAMILY_LIMIT', 0);
        $this->useEntitlementEngine('enforce');
        $service = app(LimitEnforcementService::class);

        $service->assertCanAdd($tenant, 'FAMILY_LIMIT', 1, 'sacrament_recipient');

        $this->expectException(SubscriptionException::class);
        DB::transaction(fn () => $service->assertCanAdd($tenant, 'FAMILY_LIMIT', 1));
    }

    #[Test]
    public function feature_gate_blocks_disabled_feature_in_enforce_mode_only(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->bindTenantContext($ctx['user']);
        $this->assignPlan($ctx['tenant'], 'STARTER');
        TenantEntitlementOverride::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'feature_id' => Feature::query()->where('code', 'CONTRIBUTIONS')->value('id'),
            'mode' => TenantEntitlementOverride::MODE_DISABLE,
            'reason' => 'Test',
        ]);
        app(EntitlementResolver::class)->forget($ctx['tenant']->id);

        $this->useEntitlementEngine('shadow');
        $this->getJson('/api/tenant/donations/categories')->assertOk();

        $this->useEntitlementEngine('enforce');
        $this->getJson('/api/tenant/donations/categories')->assertForbidden();
    }

    #[Test]
    public function plan_without_ministries_hides_ministries_in_enforce_mode(): void
    {
        $tenant = Tenant::factory()->create(['plan' => 'free', 'features' => ['ministries_associations']]);
        $this->assignPlan($tenant, 'STARTER');
        $this->useEntitlementEngine('enforce');

        $this->assertFalse($tenant->fresh()->supportsMinistriesAssociations());

        $this->assignPlan($tenant, 'STANDARD');
        $this->assertTrue($tenant->fresh()->supportsMinistriesAssociations());
    }

    private function limitOverride(Tenant $tenant, string $code, int $value): void
    {
        TenantEntitlementOverride::query()->create([
            'tenant_id' => $tenant->id,
            'feature_id' => Feature::query()->where('code', $code)->value('id'),
            'mode' => TenantEntitlementOverride::MODE_SET_LIMIT,
            'numeric_value' => $value,
            'reason' => 'Test limit',
        ]);
        app(EntitlementResolver::class)->forget((int) $tenant->id);
    }
}
