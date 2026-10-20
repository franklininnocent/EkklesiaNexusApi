<?php

namespace Modules\Marketing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Marketing\Models\MarketingPlanCatalogPublication;
use Modules\Marketing\Models\MarketingPlanCatalogRevision;
use Modules\Marketing\Services\MarketingPlanCatalogService;
use Modules\Marketing\Support\MarketingPlanCatalogCache;
use Modules\Subscriptions\Models\Feature;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\PlanEntitlement;
use Modules\Subscriptions\Models\PlanVersion;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class MarketingPlanCatalogTest extends TestCase
{
    use ActsAsTenantRoles;
    use RefreshDatabase;

    public function test_public_catalog_empty_until_marketing_publish(): void
    {
        $this->getJson('/api/public/subscription-plans')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', []);
    }

    public function test_subscription_version_activation_does_not_auto_publish_marketing(): void
    {
        $ctx = $this->asSuperAdmin();
        $catalog = app(MarketingPlanCatalogService::class);
        $draft = $catalog->generatePreview($ctx['user']);
        $this->assertNotEmpty($draft->payload);

        $this->getJson('/api/public/subscription-plans')->assertJsonPath('data', []);

        $plan = Plan::query()->where('code', 'STARTER')->first();
        $this->assertNotNull($plan);
        $plan->forceFill(['name' => 'Starter Renamed For Test'])->save();

        $this->getJson('/api/public/subscription-plans')->assertJsonPath('data', []);
    }

    public function test_super_admin_can_generate_preview_validate_and_publish(): void
    {
        $this->asSuperAdmin();

        $preview = $this->postJson('/api/admin/marketing/plan-catalog/previews')
            ->assertCreated()
            ->json('data');

        $revisionId = (int) $preview['revision_id'];
        $payloadHash = (string) $preview['payload_hash'];

        $this->postJson("/api/admin/marketing/plan-catalog/revisions/{$revisionId}/validate")
            ->assertOk()
            ->assertJsonPath('success', true);

        $token = $this->postJson("/api/admin/marketing/plan-catalog/revisions/{$revisionId}/preview-token")
            ->assertOk()
            ->json('data.token');

        $exchanged = $this->getJson('/api/public/marketing-plan-catalog/preview?token='.$token)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json();
        $this->assertNotEmpty($exchanged['session']);
        $this->getJson('/api/public/marketing-plan-catalog/preview?token='.$token)
            ->assertForbidden();
        $this->getJson('/api/public/marketing-plan-catalog/preview?session='.$exchanged['session'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->getJson('/api/public/marketing-plan-catalog/preview?token=not-a-valid-token')
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This preview is unavailable or has expired.');

        $this->postJson("/api/admin/marketing/plan-catalog/revisions/{$revisionId}/publish", [
            'payload_hash' => $payloadHash,
            'confirm' => true,
        ])->assertCreated();

        $codes = array_column($this->getJson('/api/public/subscription-plans')->json('data'), 'code');
        $this->assertSame(['STARTER', 'STANDARD', 'PROFESSIONAL', 'ENTERPRISE'], $codes);

        $starter = collect($this->getJson('/api/public/subscription-plans')->json('data'))->firstWhere('code', 'STARTER');
        $this->assertIsArray($starter);
        $this->assertSame('INR', $starter['currency_code']);
        $this->assertSame('1499.00', $starter['monthly_price']);
        $this->assertSame('1499.00', $starter['regional_prices']['INR']['monthly_price']);
        $this->assertSame('14990.00', $starter['regional_prices']['INR']['annual_price']);
        $this->assertSame('39.00', $starter['regional_prices']['USD']['monthly_price']);
        $this->assertSame('390.00', $starter['regional_prices']['USD']['annual_price']);
        $this->assertSame('99.00', $starter['regional_prices']['BRL']['monthly_price']);
        $this->assertSame('990.00', $starter['regional_prices']['BRL']['annual_price']);
    }

    public function test_ekklesia_admin_cannot_publish_plan_catalog(): void
    {
        $this->asPlatformEkklesiaAdmin();

        $this->postJson('/api/admin/marketing/plan-catalog/previews')->assertForbidden();
    }

    public function test_stale_payload_hash_rejected(): void
    {
        $this->asSuperAdmin();
        $preview = $this->postJson('/api/admin/marketing/plan-catalog/previews')->assertCreated()->json('data');
        $revisionId = (int) $preview['revision_id'];

        $this->postJson("/api/admin/marketing/plan-catalog/revisions/{$revisionId}/publish", [
            'payload_hash' => 'deadbeef',
            'confirm' => true,
        ])->assertStatus(409);
    }

    public function test_publication_forgets_cache(): void
    {
        $this->asSuperAdmin();
        \Illuminate\Support\Facades\Cache::put(MarketingPlanCatalogCache::KEY_PUBLISHED, [['code' => 'STALE']], 300);

        $catalog = app(MarketingPlanCatalogService::class);
        $ctx = $this->asSuperAdmin();
        $revision = $catalog->generatePreview($ctx['user']);
        $catalog->publishRevision($ctx['user'], (int) $revision->id, $revision->payload_hash);
        $catalog->afterPublishCommit($revision->fresh());

        $this->assertNull(\Illuminate\Support\Facades\Cache::get(MarketingPlanCatalogCache::KEY_PUBLISHED));
    }

    public function test_hide_feature_on_one_plan_does_not_affect_other_plans_or_entitlements(): void
    {
        $this->asSuperAdmin();
        $entitlementCount = PlanEntitlement::query()
            ->where('feature_id', Feature::query()->where('code', 'IMPORT_EXPORT')->value('id'))
            ->count();

        $preview = $this->postJson('/api/admin/marketing/plan-catalog/previews')->assertCreated()->json('data');
        $revisionId = (int) $preview['revision_id'];
        $hash = (string) $preview['payload_hash'];

        $editor = $this->getJson("/api/admin/marketing/plan-catalog/revisions/{$revisionId}/feature-visibility")
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($editor['plans']);
        $this->assertSame($hash, $editor['payload_hash']);

        $this->putJson("/api/admin/marketing/plan-catalog/revisions/{$revisionId}/feature-visibility", [
            'payload_hash' => $hash,
            'hidden' => ['STANDARD' => ['IMPORT_EXPORT']],
        ])->assertOk();

        $this->assertSame(
            $entitlementCount,
            PlanEntitlement::query()
                ->where('feature_id', Feature::query()->where('code', 'IMPORT_EXPORT')->value('id'))
                ->count()
        );

        $saved = $this->getJson("/api/admin/marketing/plan-catalog/revisions/{$revisionId}/feature-visibility")
            ->assertOk()
            ->json('data');

        $standard = collect($saved['public_payload'])->firstWhere('code', 'STANDARD');
        $starter = collect($saved['public_payload'])->firstWhere('code', 'STARTER');
        $professional = collect($saved['public_payload'])->firstWhere('code', 'PROFESSIONAL');

        $this->assertFalse(collect($standard['features'] ?? [])->contains('code', 'IMPORT_EXPORT'));
        $this->assertFalse(collect($standard['coverage'] ?? [])->contains('code', 'IMPORT_EXPORT'));
        $this->assertTrue(collect($professional['coverage'] ?? [])->contains('code', 'IMPORT_EXPORT'));
        $this->assertTrue(collect($starter['coverage'] ?? [])->contains('code', 'IMPORT_EXPORT'));
        $this->getJson('/api/public/subscription-plans')->assertJsonPath('data', []);
    }

    public function test_restore_hidden_feature_and_hide_from_every_plan_omits_comparison_row(): void
    {
        $this->asSuperAdmin();
        $preview = $this->postJson('/api/admin/marketing/plan-catalog/previews')->assertCreated()->json('data');
        $revisionId = (int) $preview['revision_id'];
        $hash = (string) $preview['payload_hash'];

        $hiddenEverywhere = [
            'STARTER' => ['IMPORT_EXPORT'],
            'STANDARD' => ['IMPORT_EXPORT'],
            'PROFESSIONAL' => ['IMPORT_EXPORT'],
            'ENTERPRISE' => ['IMPORT_EXPORT'],
        ];

        $saved = $this->putJson("/api/admin/marketing/plan-catalog/revisions/{$revisionId}/feature-visibility", [
            'payload_hash' => $hash,
            'hidden' => $hiddenEverywhere,
        ])->assertOk()->json('data');

        $this->assertContains('IMPORT_EXPORT', $saved['hidden_from_all']);
        foreach ($saved['public_payload'] as $plan) {
            $this->assertFalse(collect($plan['coverage'] ?? [])->contains('code', 'IMPORT_EXPORT'));
        }

        $restored = $this->putJson("/api/admin/marketing/plan-catalog/revisions/{$revisionId}/feature-visibility", [
            'payload_hash' => $saved['payload_hash'],
            'hidden' => ['STANDARD' => ['IMPORT_EXPORT']],
        ])->assertOk()->json('data');

        $this->assertNotContains('IMPORT_EXPORT', $restored['hidden_from_all']);
        $professional = collect($restored['public_payload'])->firstWhere('code', 'PROFESSIONAL');
        $this->assertTrue(collect($professional['coverage'] ?? [])->contains('code', 'IMPORT_EXPORT'));
    }

    public function test_visibility_persists_on_reload_and_does_not_publish_until_approved(): void
    {
        $this->asSuperAdmin();
        $preview = $this->postJson('/api/admin/marketing/plan-catalog/previews')->assertCreated()->json('data');
        $revisionId = (int) $preview['revision_id'];

        $this->putJson("/api/admin/marketing/plan-catalog/revisions/{$revisionId}/feature-visibility", [
            'payload_hash' => $preview['payload_hash'],
            'hidden' => ['STANDARD' => ['IMPORT_EXPORT']],
        ])->assertOk();

        $reloaded = $this->getJson("/api/admin/marketing/plan-catalog/revisions/{$revisionId}/feature-visibility")
            ->assertOk()
            ->json('data');
        $this->assertSame(['IMPORT_EXPORT'], $reloaded['hidden']['STANDARD']);

        $this->getJson('/api/public/subscription-plans')->assertJsonPath('data', []);

        $token = $this->postJson("/api/admin/marketing/plan-catalog/revisions/{$revisionId}/preview-token")
            ->assertOk()
            ->json('data.token');
        $previewPlans = $this->getJson('/api/public/marketing-plan-catalog/preview?token='.$token)
            ->assertOk()
            ->json('data');
        $standard = collect($previewPlans)->firstWhere('code', 'STANDARD');
        $this->assertFalse(collect($standard['coverage'] ?? [])->contains('code', 'IMPORT_EXPORT'));
        $this->assertStringNotContainsString('Church Data Export', json_encode($standard));
    }

    public function test_globally_unpublished_feature_cannot_be_shown_by_override(): void
    {
        $this->asSuperAdmin();
        $preview = $this->postJson('/api/admin/marketing/plan-catalog/previews')->assertCreated()->json('data');
        $revisionId = (int) $preview['revision_id'];

        Feature::query()->where('code', 'IMPORT_EXPORT')->update(['is_public' => false]);

        $this->putJson("/api/admin/marketing/plan-catalog/revisions/{$revisionId}/feature-visibility", [
            'payload_hash' => $preview['payload_hash'],
            'hidden' => [],
        ])->assertStatus(422);
    }

    public function test_unauthorized_user_cannot_change_visibility_or_publish(): void
    {
        $this->asSuperAdmin();
        $preview = $this->postJson('/api/admin/marketing/plan-catalog/previews')->assertCreated()->json('data');
        $revisionId = (int) $preview['revision_id'];
        $hash = (string) $preview['payload_hash'];

        $this->asPlatformEkklesiaAdmin();
        $this->putJson("/api/admin/marketing/plan-catalog/revisions/{$revisionId}/feature-visibility", [
            'payload_hash' => $hash,
            'hidden' => ['STANDARD' => ['IMPORT_EXPORT']],
        ])->assertForbidden();
        $this->postJson("/api/admin/marketing/plan-catalog/revisions/{$revisionId}/publish", [
            'payload_hash' => $hash,
            'confirm' => true,
        ])->assertForbidden();
    }

    public function test_stale_visibility_save_and_failed_publish_preserve_live_revision(): void
    {
        $ctx = $this->asSuperAdmin();
        $catalog = app(MarketingPlanCatalogService::class);
        $first = $catalog->generatePreview($ctx['user']);
        $catalog->publishRevision($ctx['user'], (int) $first->id, $first->payload_hash);
        $liveId = MarketingPlanCatalogPublication::query()->whereKey(1)->value('published_revision_id');

        $this->asSuperAdmin();
        $draft = $this->postJson('/api/admin/marketing/plan-catalog/previews')->assertCreated()->json('data');
        $revisionId = (int) $draft['revision_id'];

        $this->putJson("/api/admin/marketing/plan-catalog/revisions/{$revisionId}/feature-visibility", [
            'payload_hash' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'hidden' => ['STANDARD' => ['IMPORT_EXPORT']],
        ])->assertStatus(409);

        $this->postJson("/api/admin/marketing/plan-catalog/revisions/{$revisionId}/publish", [
            'payload_hash' => 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef',
            'confirm' => true,
        ])->assertStatus(409);

        $this->assertSame($liveId, MarketingPlanCatalogPublication::query()->whereKey(1)->value('published_revision_id'));
        $codes = array_column($this->getJson('/api/public/subscription-plans')->json('data'), 'code');
        $this->assertSame(['STARTER', 'STANDARD', 'PROFESSIONAL', 'ENTERPRISE'], $codes);
        $standard = collect($this->getJson('/api/public/subscription-plans')->json('data'))->firstWhere('code', 'STANDARD');
        $this->assertTrue(collect($standard['coverage'] ?? [])->contains('code', 'IMPORT_EXPORT'));
    }

    public function test_publish_visibility_updates_public_catalog_without_leaking_hidden_fields(): void
    {
        $this->asSuperAdmin();
        $preview = $this->postJson('/api/admin/marketing/plan-catalog/previews')->assertCreated()->json('data');
        $revisionId = (int) $preview['revision_id'];
        $saved = $this->putJson("/api/admin/marketing/plan-catalog/revisions/{$revisionId}/feature-visibility", [
            'payload_hash' => $preview['payload_hash'],
            'hidden' => ['STANDARD' => ['IMPORT_EXPORT']],
        ])->assertOk()->json('data');

        $this->postJson("/api/admin/marketing/plan-catalog/revisions/{$revisionId}/publish", [
            'payload_hash' => $saved['payload_hash'],
            'confirm' => true,
        ])->assertCreated();

        $public = $this->getJson('/api/public/subscription-plans')->assertOk()->json();
        $encoded = json_encode($public);
        $standard = collect($public['data'])->firstWhere('code', 'STANDARD');
        $this->assertFalse(collect($standard['features'] ?? [])->contains('code', 'IMPORT_EXPORT'));
        $this->assertFalse(collect($standard['coverage'] ?? [])->contains('code', 'IMPORT_EXPORT'));
        $this->assertStringNotContainsString('eligible_payload', $encoded);
        $this->assertStringNotContainsString('feature_visibility', $encoded);
        $this->assertStringNotContainsString('"IMPORT_EXPORT"', json_encode($standard));
    }

    public function test_hide_enterprise_from_website_does_not_change_subscription(): void
    {
        $this->asSuperAdmin();
        $enterprise = Plan::query()->where('code', 'ENTERPRISE')->firstOrFail();
        $wasPublic = (bool) $enterprise->is_public;
        $entitlementCount = $this->entitlementCountForPlan($enterprise);

        $preview = $this->postJson('/api/admin/marketing/plan-catalog/previews')->assertCreated()->json('data');
        $revisionId = (int) $preview['revision_id'];

        $saved = $this->putJson("/api/admin/marketing/plan-catalog/revisions/{$revisionId}/feature-visibility", [
            'payload_hash' => $preview['payload_hash'],
            'hidden' => [],
            'hidden_plans' => ['ENTERPRISE'],
        ])->assertOk()->json('data');

        $this->assertContains('ENTERPRISE', $saved['hidden_plans']);
        $this->assertFalse(collect($saved['plans'])->firstWhere('code', 'ENTERPRISE')['listed']);
        $this->assertSame(
            ['STARTER', 'STANDARD', 'PROFESSIONAL'],
            array_column($saved['public_payload'], 'code')
        );

        $this->assertSame($wasPublic, (bool) $enterprise->fresh()->is_public);
        $this->assertSame(
            $entitlementCount,
            $this->entitlementCountForPlan($enterprise->fresh())
        );
        $this->getJson('/api/public/subscription-plans')->assertJsonPath('data', []);

        $token = $this->postJson("/api/admin/marketing/plan-catalog/revisions/{$revisionId}/preview-token")
            ->assertOk()
            ->json('data.token');
        $previewPlans = $this->getJson('/api/public/marketing-plan-catalog/preview?token='.$token)
            ->assertOk()
            ->json('data');
        $this->assertSame(['STARTER', 'STANDARD', 'PROFESSIONAL'], array_column($previewPlans, 'code'));

        $refreshed = $this->postJson('/api/admin/marketing/plan-catalog/previews')->assertCreated()->json('data');
        $carried = $this->getJson('/api/admin/marketing/plan-catalog/revisions/'.$refreshed['revision_id'].'/feature-visibility')
            ->assertOk()
            ->json('data');
        $this->assertContains('ENTERPRISE', $carried['hidden_plans']);

        $this->postJson("/api/admin/marketing/plan-catalog/revisions/{$revisionId}/publish", [
            'payload_hash' => $saved['payload_hash'],
            'confirm' => true,
        ])->assertCreated();

        $publicCodes = array_column($this->getJson('/api/public/subscription-plans')->json('data'), 'code');
        $this->assertSame(['STARTER', 'STANDARD', 'PROFESSIONAL'], $publicCodes);
        $this->assertSame($wasPublic, (bool) $enterprise->fresh()->is_public);
    }

    public function test_expired_and_missing_preview_tokens_fail_generically(): void
    {
        $this->asSuperAdmin();
        $preview = $this->postJson('/api/admin/marketing/plan-catalog/previews')->assertCreated()->json('data');
        $revisionId = (int) $preview['revision_id'];
        $this->postJson("/api/admin/marketing/plan-catalog/revisions/{$revisionId}/validate")->assertOk();
        $token = $this->postJson("/api/admin/marketing/plan-catalog/revisions/{$revisionId}/preview-token")
            ->assertOk()
            ->json('data.token');

        $this->travel(901)->seconds();
        $this->getJson('/api/public/marketing-plan-catalog/preview?token='.$token)
            ->assertForbidden()
            ->assertJsonPath('message', 'This preview is unavailable or has expired.');
        $this->getJson('/api/public/marketing-plan-catalog/preview')
            ->assertForbidden()
            ->assertJsonPath('message', 'This preview is unavailable or has expired.');
    }

    public function test_draft_revision_not_public(): void
    {
        $this->asSuperAdmin();
        $this->postJson('/api/admin/marketing/plan-catalog/previews')->assertCreated();

        $this->getJson('/api/public/subscription-plans')->assertJsonPath('data', []);

        $draft = MarketingPlanCatalogRevision::query()->where('status', 'DRAFT')->latest('id')->first();
        $this->assertNotNull($draft);
        $this->assertNotEmpty($draft->payload);
    }

    private function asPlatformEkklesiaAdmin(): void
    {
        $role = Role::query()->firstOrCreate(
            ['name' => Role::EKKLESIA_ADMIN, 'tenant_id' => null],
            [
                'description' => Role::EKKLESIA_ADMIN,
                'level' => Role::LEVEL_EKKLESIA_ADMIN,
                'active' => 1,
                'is_custom' => false,
                'role_type' => Role::ROLE_TYPE_PLATFORM,
            ]
        );

        $user = User::factory()->create([
            'user_type' => null,
            'is_primary_admin' => true,
            'tenant_id' => null,
            'role_id' => $role->id,
            'active' => 1,
        ]);
        $user->syncRoles([$role->id]);
        $this->refreshUserPermissionState($user);
        Passport::actingAs($user->fresh());
    }

    private function entitlementCountForPlan(Plan $plan): int
    {
        $versionIds = PlanVersion::query()
            ->where('plan_id', $plan->id)
            ->pluck('id');

        return PlanEntitlement::query()
            ->whereIn('plan_version_id', $versionIds)
            ->count();
    }
}
