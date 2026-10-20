<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Family\Models\Family;
use Modules\Family\Support\PeopleLimitEligibility;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Support\Usage\PeopleUsageProvider;
use Modules\Subscriptions\Models\Feature;
use Modules\Subscriptions\Models\TenantEntitlementOverride;
use Modules\Subscriptions\Services\Entitlements\EntitlementResolver;
use Modules\Subscriptions\Services\LimitEnforcementService;
use Illuminate\Support\Facades\DB;
use Modules\Subscriptions\Exceptions\SubscriptionException;
use Modules\Subscriptions\Support\SubscriptionsPermissionCatalog;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\Concerns\InteractsWithTenantContext;
use Tests\TestCase;

class PeopleLimitConsumptionTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithSubscriptions;
    use InteractsWithTenantContext;
    use RefreshDatabase;

    #[Test]
    public function usage_counts_only_active_members_in_non_migrated_families(): void
    {
        $tenant = Tenant::factory()->create();
        $family = Family::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        FamilyMember::factory()->create(['tenant_id' => $tenant->id, 'family_id' => $family->id, 'status' => 'active']);
        FamilyMember::factory()->create(['tenant_id' => $tenant->id, 'family_id' => $family->id, 'status' => 'inactive']);
        FamilyMember::factory()->create(['tenant_id' => $tenant->id, 'family_id' => $family->id, 'status' => 'deceased']);
        FamilyMember::factory()->create(['tenant_id' => $tenant->id, 'family_id' => $family->id, 'status' => 'migrated']);

        $this->assertSame(1, app(PeopleUsageProvider::class)->currentUsage((int) $tenant->id));
    }

    #[Test]
    public function migrated_family_excludes_active_members_from_usage(): void
    {
        $tenant = Tenant::factory()->create();
        $family = Family::factory()->create(['tenant_id' => $tenant->id, 'status' => 'migrated']);
        FamilyMember::factory()->create(['tenant_id' => $tenant->id, 'family_id' => $family->id, 'status' => 'active']);

        $this->assertSame(0, app(PeopleUsageProvider::class)->currentUsage((int) $tenant->id));
    }

    #[Test]
    public function ekklesia_admin_cannot_grant_entitlement_override(): void
    {
        $tenant = Tenant::factory()->create();
        $this->assignPlan($tenant, 'STARTER');
        $this->asEkklesiaAdmin();

        $this->postJson("/api/admin/subscriptions/tenants/{$tenant->id}/overrides", [
            'feature_code' => 'PEOPLE_LIMIT',
            'mode' => 'SET_LIMIT',
            'numeric_value' => 300,
            'reason' => 'Should be denied',
        ])->assertForbidden();
    }

    #[Test]
    public function tenant_admin_cannot_grant_entitlement_override_even_with_permission(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->assignPlan($ctx['tenant'], 'STARTER');
        $ctx['user']->givePermissionTo(SubscriptionsPermissionCatalog::OVERRIDES_MANAGE);

        $this->postJson("/api/admin/subscriptions/tenants/{$ctx['tenant']->id}/overrides", [
            'feature_code' => 'PEOPLE_LIMIT',
            'mode' => 'SET_LIMIT',
            'numeric_value' => 300,
            'reason' => 'Should be denied',
        ])->assertForbidden();
    }

    #[Test]
    public function reactivation_at_limit_is_blocked_in_enforce_mode(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->bindTenantContext($ctx['user']);
        $this->assignPlan($ctx['tenant'], 'STARTER');
        $this->override($ctx['tenant'], 'PEOPLE_LIMIT', TenantEntitlementOverride::MODE_SET_LIMIT, ['numeric_value' => 1]);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        FamilyMember::factory()->create(['tenant_id' => $ctx['tenant']->id, 'family_id' => $family->id, 'status' => 'active']);
        $inactive = FamilyMember::factory()->create(['tenant_id' => $ctx['tenant']->id, 'family_id' => $family->id, 'status' => 'inactive']);
        $this->useEntitlementEngine('enforce');

        $this->assertSame(1, app(PeopleUsageProvider::class)->currentUsage((int) $ctx['tenant']->id));
        $this->assertSame(1, app(EntitlementResolver::class)->resolve($ctx['tenant'])->limit('PEOPLE_LIMIT'));

        $delta = PeopleLimitEligibility::deltaForMemberStatusChange($inactive, 'active', $family->status);
        $this->assertSame(1, $delta);

        $this->expectException(SubscriptionException::class);
        DB::transaction(fn () => app(LimitEnforcementService::class)->assertCanAdd($ctx['tenant'], 'PEOPLE_LIMIT', $delta));
    }

    #[Test]
    public function marking_deceased_frees_capacity_for_another_active_member(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->bindTenantContext($ctx['user']);
        $this->assignPlan($ctx['tenant'], 'STARTER');
        $this->limitOverride($ctx['tenant'], 'PEOPLE_LIMIT', 1);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $active = FamilyMember::factory()->create(['tenant_id' => $ctx['tenant']->id, 'family_id' => $family->id, 'status' => 'active']);
        $this->useEntitlementEngine('enforce');

        $this->putJson("/api/families/{$family->id}/members/{$active->id}", [
            'status' => 'deceased',
            'deceased_date' => now()->toDateString(),
        ])->assertOk();

        $this->postJson("/api/families/{$family->id}/members", [
            'first_name' => 'New',
            'last_name' => 'Member',
            'relationship_to_head' => 'son',
            'date_of_birth' => '2010-01-15',
            'gender' => 'male',
            'status' => 'active',
        ])->assertCreated();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function override(Tenant $tenant, string $code, string $mode, array $extra = []): TenantEntitlementOverride
    {
        $override = TenantEntitlementOverride::query()->create($extra + [
            'tenant_id' => $tenant->id,
            'feature_id' => Feature::query()->where('code', $code)->value('id'),
            'mode' => $mode,
            'reason' => 'Test override',
        ]);
        app(EntitlementResolver::class)->forget((int) $tenant->id);

        return $override;
    }

    private function limitOverride(Tenant $tenant, string $code, int $value): void
    {
        $this->override($tenant, $code, TenantEntitlementOverride::MODE_SET_LIMIT, ['numeric_value' => $value]);
    }
}
