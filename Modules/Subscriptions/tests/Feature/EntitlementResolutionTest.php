<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\User;
use Modules\Family\app\Services\FamilySplitService;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Subscriptions\Exceptions\SubscriptionException;
use Modules\Subscriptions\Models\Feature;
use Modules\Subscriptions\Models\PlanVersion;
use Modules\Subscriptions\Models\TenantEntitlementOverride;
use Modules\Subscriptions\Services\Entitlements\EntitlementResolver;
use Modules\Subscriptions\Services\LimitEnforcementService;
use Modules\Subscriptions\Services\SubscriptionPolicyService;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use Modules\Tenants\Contracts\TenantLimitGuard;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class EntitlementResolutionTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    #[Test]
    public function overrides_apply_only_inside_their_effective_window(): void
    {
        $tenant = $this->activeTenant();
        $this->assignPlan($tenant, 'STARTER');
        $resolver = app(EntitlementResolver::class);

        $override = $this->override($tenant, 'API_ACCESS', TenantEntitlementOverride::MODE_ENABLE, ['effective_until' => now()->subDay()]);
        $this->assertFalse($resolver->resolve($tenant)->allows('API_ACCESS'));

        $override->forceFill(['effective_from' => now()->addDay(), 'effective_until' => null])->save();
        $resolver->forget((int) $tenant->id);
        $this->assertFalse($resolver->resolve($tenant)->allows('API_ACCESS'));

        $override->forceFill(['effective_from' => now()->subHour(), 'effective_until' => now()->addDay()])->save();
        $resolver->forget((int) $tenant->id);
        $this->assertTrue($resolver->resolve($tenant)->allows('API_ACCESS'));

        $this->travel(2)->days();
        $resolver->forget((int) $tenant->id);
        $this->assertFalse($resolver->resolve($tenant)->allows('API_ACCESS'));
    }

    #[Test]
    public function override_beats_plan_and_core_features_cannot_be_switched_off(): void
    {
        $tenant = $this->activeTenant();
        $this->assignPlan($tenant, 'STARTER');
        $resolver = app(EntitlementResolver::class);

        $this->override($tenant, 'CONTRIBUTIONS', TenantEntitlementOverride::MODE_DISABLE);
        $this->override($tenant, 'FAMILIES', TenantEntitlementOverride::MODE_DISABLE);
        $this->override($tenant, 'PEOPLE_LIMIT', TenantEntitlementOverride::MODE_SET_LIMIT, ['numeric_value' => 400]);

        $resolved = $resolver->resolve($tenant);
        $this->assertFalse($resolved->allows('CONTRIBUTIONS'));
        $this->assertTrue($resolved->allows('FAMILIES'));
        $this->assertSame(400, $resolved->limit('PEOPLE_LIMIT'));
    }

    #[Test]
    public function custom_limits_apply_only_to_custom_priced_plans(): void
    {
        $tenant = $this->activeTenant();
        $this->assignPlan($tenant, 'ENTERPRISE', ['contracted_price' => '50000.00', 'custom_limits' => ['people_limit' => 5000]]);
        $this->assertSame(5000, app(EntitlementResolver::class)->resolve($tenant)->limit('PEOPLE_LIMIT'));

        $this->expectException(SubscriptionException::class);
        $this->assignPlan($tenant, 'STANDARD', ['custom_limits' => ['PEOPLE_LIMIT' => 5000]]);
    }

    #[Test]
    public function plan_change_invalidates_cached_entitlements(): void
    {
        $tenant = $this->activeTenant();
        $this->assignPlan($tenant, 'STARTER');
        $resolver = app(EntitlementResolver::class);
        $this->assertSame(250, $resolver->resolve($tenant)->limit('PEOPLE_LIMIT'));

        $this->assignPlan($tenant, 'STANDARD');
        $this->assertSame(1000, $resolver->resolve($tenant)->limit('PEOPLE_LIMIT'));

        $resolver->flushMemo();
        $this->assertSame(1000, $resolver->resolve($tenant->fresh())->limit('PEOPLE_LIMIT'));
        $this->assertTrue($resolver->resolve($tenant->fresh())->allows('MINISTRIES'));
    }

    #[Test]
    public function limit_boundary_allows_reaching_the_limit_and_blocks_going_over(): void
    {
        $tenant = $this->activeTenant();
        $this->assignPlan($tenant, 'STARTER');
        $this->assertSame(250, app(EntitlementResolver::class)->resolve($tenant)->limit('PEOPLE_LIMIT'));

        $this->override($tenant, 'PEOPLE_LIMIT', TenantEntitlementOverride::MODE_SET_LIMIT, ['numeric_value' => 3]);
        $family = Family::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        FamilyMember::factory()->count(2)->create(['family_id' => $family->id, 'status' => 'active']);
        $this->useEntitlementEngine('enforce');
        $limits = app(LimitEnforcementService::class);

        DB::transaction(fn () => $limits->assertCanAdd($tenant, 'PEOPLE_LIMIT', 1));

        try {
            DB::transaction(fn () => $limits->assertCanAdd($tenant, 'PEOPLE_LIMIT', 2));
            $this->fail('Adding past the limit must be blocked.');
        } catch (SubscriptionException $e) {
            $this->assertSame(SubscriptionException::ENTITLEMENT_LIMIT_REACHED, $e->errorCode);
            $this->assertSame(2, $e->context['current_usage']);
        }

        FamilyMember::factory()->create(['family_id' => $family->id, 'status' => 'active']);
        $this->expectException(SubscriptionException::class);
        DB::transaction(fn () => $limits->assertCanAdd($tenant, 'PEOPLE_LIMIT', 1));
    }

    #[Test]
    public function tenant_override_takes_precedence_over_custom_limits(): void
    {
        $tenant = $this->activeTenant();
        $this->assignPlan($tenant, 'ENTERPRISE', ['contracted_price' => '50000.00', 'custom_limits' => ['PEOPLE_LIMIT' => 5000]]);
        $this->override($tenant, 'PEOPLE_LIMIT', TenantEntitlementOverride::MODE_SET_LIMIT, ['numeric_value' => 6000]);

        $this->assertSame(6000, app(EntitlementResolver::class)->resolve($tenant)->limit('PEOPLE_LIMIT'));
    }

    #[Test]
    public function scheduled_version_activates_on_time_and_existing_tenants_stay_grandfathered(): void
    {
        $tenant = $this->activeTenant();
        $this->assignPlan($tenant, 'STARTER');
        $v1 = $this->currentSubscription($tenant)->plan_version_id;

        $this->asSuperAdmin();
        $starter = $this->plan('STARTER');
        $draftId = $this->postJson("/api/admin/subscriptions/plans/{$starter->id}/versions", [])->assertCreated()->json('data.id');
        $this->patchJson("/api/admin/subscriptions/plans/{$starter->id}/versions/{$draftId}", ['monthly_price' => '1599.00'])->assertOk();
        $this->postJson("/api/admin/subscriptions/plans/{$starter->id}/versions/{$draftId}/publish", [
            'effective_from' => now()->addDays(2)->toIso8601String(),
            'reason' => 'Price change next month',
        ])->assertOk()->assertJsonPath('data.status', PlanVersion::STATUS_SCHEDULED);

        $this->artisan('subscriptions:apply-scheduled')->assertSuccessful();
        $this->assertSame(PlanVersion::STATUS_SCHEDULED, PlanVersion::query()->find($draftId)->status);

        $this->travel(3)->days();
        $this->artisan('subscriptions:apply-scheduled')->assertSuccessful();

        $this->assertSame(PlanVersion::STATUS_ACTIVE, PlanVersion::query()->find($draftId)->status);
        $this->assertSame(PlanVersion::STATUS_RETIRED, PlanVersion::query()->find($v1)->status);
        $this->assertSame($v1, $this->currentSubscription($tenant)->plan_version_id);
    }

    #[Test]
    public function tenant_admin_cannot_change_own_plan_through_any_endpoint(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->assignPlan($ctx['tenant'], 'STARTER');
        $this->useEntitlementEngine('enforce');
        $starter = $this->plan('STARTER');

        $this->postJson("/api/tenant/{$ctx['tenant']->id}/subscription/upgrade", ['plan' => 'professional', 'reason' => 'Self upgrade'])
            ->assertForbidden();
        $this->postJson("/api/admin/subscriptions/plans/{$starter->id}/versions/{$starter->activeVersion->id}/migrate-tenants", [
            'tenant_ids' => [$ctx['tenant']->id],
            'reason' => 'Self migrate',
        ])->assertForbidden();
        $this->getJson('/api/tenant/entitlements?plan_id='.$this->plan('ENTERPRISE')->id)
            ->assertOk()
            ->assertJsonPath('data.plan.code', 'STARTER')
            ->assertJsonPath('data.features.API_ACCESS', false);

        $this->assertSame('STARTER', $this->currentSubscription($ctx['tenant'])->plan->code);
    }

    #[Test]
    public function subscription_poll_endpoints_carry_plan_and_entitlements_version(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->assignPlan($ctx['tenant'], 'STARTER');

        $access = $this->getJson('/api/tenant/subscription-access')
            ->assertOk()
            ->assertJsonPath('data.plan.code', 'STARTER')
            ->assertJsonStructure(['data' => ['status', 'access_mode', 'entitlements_version', 'engine_mode']])
            ->json('data');
        $this->assertArrayNotHasKey('limits', $access);

        $mine = $this->getJson('/api/tenant/my-subscription')->assertOk()->json('data');
        $this->assertSame('PEOPLE_LIMIT', collect($mine['limits'])->firstWhere('code', 'PEOPLE_LIMIT')['code']);
        $this->assertSame(250, collect($mine['limits'])->firstWhere('code', 'PEOPLE_LIMIT')['limit']);

        $this->assignPlan($ctx['tenant'], 'STANDARD');
        app(EntitlementResolver::class)->flushMemo();
        $after = $this->getJson('/api/tenant/subscription-access')->assertOk()->json('data');
        $this->assertSame('STANDARD', $after['plan']['code']);
        $this->assertNotSame($access['entitlements_version'], $after['entitlements_version']);
    }

    #[Test]
    public function reactivating_a_staff_user_counts_against_the_staff_limit(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->assignPlan($ctx['tenant'], 'STARTER');
        $inactive = User::factory()->create(['tenant_id' => $ctx['tenant']->id, 'active' => 0, 'is_primary_admin' => false]);
        $this->override($ctx['tenant'], 'STAFF_USER_LIMIT', TenantEntitlementOverride::MODE_SET_LIMIT, ['numeric_value' => 1]);
        $this->useEntitlementEngine('enforce');

        $this->patchJson("/api/users/{$inactive->id}/status", ['active' => 1])
            ->assertForbidden()
            ->assertJsonPath('code', SubscriptionException::ENTITLEMENT_LIMIT_REACHED)
            ->assertJsonPath('feature', 'STAFF_USER_LIMIT');
        $this->assertSame(0, (int) $inactive->fresh()->active);

        $this->useEntitlementEngine('shadow');
        $this->patchJson("/api/users/{$inactive->id}/status", ['active' => 1])->assertOk();
    }

    #[Test]
    public function family_split_respects_the_family_limit_unless_policy_exempts_it(): void
    {
        $tenant = $this->activeTenant();
        $this->assignPlan($tenant, 'STARTER');
        $family = Family::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        $member = FamilyMember::factory()->create(['family_id' => $family->id, 'status' => 'active', 'relationship_to_head' => 'son']);
        $this->override($tenant, 'FAMILY_LIMIT', TenantEntitlementOverride::MODE_SET_LIMIT, ['numeric_value' => 1]);
        $this->useEntitlementEngine('enforce');

        try {
            app(FamilySplitService::class)->splitMember($family->id, $member->id, ['family_name' => 'New Household'], [], $tenant->id, 1);
            $this->fail('Split past the family limit must be blocked.');
        } catch (SubscriptionException $e) {
            $this->assertSame('FAMILY_LIMIT', $e->context['feature']);
        }
        $this->assertSame(1, Family::query()->where('tenant_id', $tenant->id)->count());
        $this->assertSame($family->id, $member->fresh()->family_id);

        $admin = $this->asSuperAdmin()['user'];
        app(SubscriptionPolicyService::class)->update(['limit_exempt_flows' => ['sacrament_recipient', 'family_split']], $admin, 'Allow splits');
        app(TenantLimitGuard::class)->assertTenantCanAdd((int) $tenant->id, 'FAMILY_LIMIT', 1, 'family_split');

        $this->expectException(SubscriptionException::class);
        app(TenantLimitGuard::class)->assertTenantCanAdd((int) $tenant->id, 'FAMILY_LIMIT', 1, 'marriage_transition');
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

    private function activeTenant(): Tenant
    {
        return Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->addYear(),
            'trial_ends_at' => now()->addYear(),
        ]);
    }
}
