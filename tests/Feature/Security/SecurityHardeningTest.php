<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\EcclesiasticalData\Repositories\BishopRepository;
use Modules\Sacraments\Repositories\SacramentRepository;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    #[Test]
    public function public_registration_cannot_create_a_privileged_user(): void
    {
        $before = User::query()->count();

        $this->postJson('/api/auth/register', [
            'name' => 'Intruder',
            'email' => 'intruder@example.test',
            'password' => 'Password*123',
            'password_confirmation' => 'Password*123',
            'role_id' => 1,
            'tenant_id' => 999,
        ])->assertForbidden()
            ->assertJsonPath('message', 'Self-registration is not available. Ask your parish administrator to create an account.');

        $this->assertSame($before, User::query()->count());
        $this->assertDatabaseMissing('users', ['email' => 'intruder@example.test']);
    }

    #[Test]
    public function api_host_is_not_indexable(): void
    {
        $robots = (string) file_get_contents(public_path('robots.txt'));
        $this->assertStringContainsString('Disallow: /', $robots);
        $this->assertStringNotContainsString("Disallow:\n", $robots);

        $this->get('/up')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    #[Test]
    public function unauthenticated_api_requests_return_json_401_without_a_login_redirect(): void
    {
        $response = $this->get('/api/admin/contact-enquiries');

        $response->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'UNAUTHENTICATED')
            ->assertJsonPath('message', 'Your session has expired. Please sign in again to continue.');
        $this->assertStringNotContainsString('<html', (string) $response->getContent());
        $this->assertStringNotContainsString('RouteNotFoundException', (string) $response->getContent());
    }

    #[Test]
    public function debug_and_test_routes_are_not_registered(): void
    {
        foreach (['/api/debug-token', '/api/test-guard', '/api/test-public', '/api/test-auth'] as $path) {
            $response = $this->getJson($path);
            $this->assertContains($response->status(), [404, 405]);
            $this->assertStringNotContainsString('token_user_id', (string) $response->getContent());
            $this->assertStringNotContainsString('getTraceAsString', (string) $response->getContent());
        }
    }

    #[Test]
    public function inactive_user_refresh_does_not_issue_a_new_token(): void
    {
        $this->seedPasswordClient();
        $user = $this->createActiveUser('Refresh*123');

        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'Refresh*123',
        ])->assertOk();

        $user->active = 0;
        $user->save();

        $this->postJson('/api/auth/refresh', [
            'refresh_token' => $login->json('refresh_token'),
        ])->assertUnauthorized();

        $this->assertSame(0, DB::table('oauth_access_tokens')->where('user_id', $user->id)->where('revoked', false)->count());
    }

    #[Test]
    public function deactivating_a_user_revokes_tokens(): void
    {
        $this->seedPasswordClient();
        $tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->addYear(),
            'trial_ends_at' => null,
            'subscription_suspended_at' => null,
        ]);
        $adminRole = Role::query()->create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Administrator',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);
        $staffRole = Role::query()->create([
            'name' => 'Secretary',
            'description' => 'Secretary',
            'level' => 3,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $adminRole->id,
            'active' => 1,
            'email' => 'status-admin@example.test',
            'password' => Hash::make('Admin*123'),
        ]);
        $admin->syncRoles([$adminRole->id]);

        $staff = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $staffRole->id,
            'active' => 1,
            'email' => 'status-staff@example.test',
            'password' => Hash::make('Staff*123'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $staff->email,
            'password' => 'Staff*123',
        ])->assertOk();

        $this->assertGreaterThan(0, DB::table('oauth_access_tokens')->where('user_id', $staff->id)->where('revoked', false)->count());

        $this->actingAs($admin, 'api')
            ->patchJson("/api/users/{$staff->id}/status", ['active' => 0])
            ->assertOk();

        $this->assertSame(0, DB::table('oauth_access_tokens')->where('user_id', $staff->id)->where('revoked', false)->count());
    }

    #[Test]
    public function webhook_without_a_secret_or_matching_signature_is_rejected(): void
    {
        config(['donations.webhooks.secret' => '']);

        $this->postJson('/api/donations/webhooks/generic', [
            'id' => 'evt_empty',
            'type' => 'payment.succeeded',
        ])->assertUnauthorized();

        config(['donations.webhooks.secret' => 'expected-secret']);

        $this->withHeaders(['X-Payment-Signature' => 'wrong'])
            ->postJson('/api/donations/webhooks/generic', [
                'id' => 'evt_bad',
                'type' => 'payment.succeeded',
            ])->assertUnauthorized();

        $this->assertDatabaseMissing('payment_gateway_webhook_events', ['event_id' => 'evt_empty']);
        $this->assertDatabaseMissing('payment_gateway_webhook_events', ['event_id' => 'evt_bad']);
    }

    #[Test]
    public function signed_webhook_stub_does_not_create_financial_records(): void
    {
        config([
            'donations.webhooks.secret' => 'expected-secret',
            'donations.webhooks.providers.generic.secret' => 'expected-secret',
        ]);

        $this->withHeaders(['X-Payment-Signature' => 'expected-secret'])
            ->postJson('/api/donations/webhooks/generic', [
                'id' => 'evt_signed_stub',
                'type' => 'payment.succeeded',
                'data' => ['amount' => 5000, 'tenant_id' => 1],
            ])->assertStatus(202);

        $this->assertDatabaseHas('payment_gateway_webhook_events', [
            'event_id' => 'evt_signed_stub',
            'status' => 'processed',
        ]);
        $this->assertSame(0, DB::table('donations')->count());
        $this->assertSame(0, DB::table('donation_payments')->count());
    }

    #[Test]
    public function parish_user_without_a_tenant_row_cannot_mutate(): void
    {
        config(['tenants.subscription.write_policy' => 'read_only_when_expired']);

        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'active' => 1,
        ]);

        \Illuminate\Support\Facades\Schema::withoutForeignKeyConstraints(function () use ($tenant): void {
            $tenant->forceDelete();
        });

        $this->actingAs($user, 'api')
            ->postJson('/api/families', ['family_name' => 'Should Not Save'])
            ->assertForbidden()
            ->assertJsonPath('reason', 'subscription_blocked');

        $read = $this->actingAs($user, 'api')->getJson('/api/families');
        $this->assertNotSame('subscription_blocked', $read->json('reason'));
    }

    #[Test]
    public function tenant_admin_cannot_mass_assign_role_security_fields(): void
    {
        $tenant = Tenant::factory()->create([
            'active' => 1,
            'trial_ends_at' => null,
            'subscription_ends_at' => now()->addYear(),
            'subscription_suspended_at' => null,
        ]);
        $other = Tenant::factory()->create();
        $adminRole = Role::query()->create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Administrator',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'role_type' => Role::ROLE_TYPE_TENANT,
            'role_classification' => Role::CLASSIFICATION_DEFAULT_TEMPLATE,
        ]);
        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $adminRole->id,
            'active' => 1,
        ]);
        $admin->syncRoles([$adminRole->id]);

        $this->actingAs($admin, 'api')->postJson('/api/roles', [
            'name' => 'Custom Steward',
            'description' => 'Parish helper',
            'level' => 5,
            'tenant_id' => $other->id,
            'role_type' => Role::ROLE_TYPE_PLATFORM,
            'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
            'active' => 0,
            'is_custom' => false,
        ])->assertCreated();

        $role = Role::query()->where('name', 'Custom Steward')->firstOrFail();
        $this->assertSame($tenant->id, (int) $role->tenant_id);
        $this->assertSame(Role::ROLE_TYPE_TENANT, $role->role_type);
        $this->assertSame(Role::CLASSIFICATION_CUSTOM, $role->role_classification);
        $this->assertSame(1, (int) $role->active);
        $this->assertTrue((bool) $role->is_custom);
    }

    #[Test]
    public function sacrament_and_bishop_sort_payloads_fall_back_to_allowlisted_columns(): void
    {
        $sacraments = app(SacramentRepository::class)->getPaginated([
            'tenant_id' => 1,
            'sort_by' => 'id);select pg_sleep(1)--',
            'sort_dir' => 'desc;select 1',
        ]);
        $this->assertSame(0, $sacraments->total());

        $bishops = app(BishopRepository::class)->getBishopsPaginated([
            'sort_by' => 'full_name;drop table bishops',
            'sort_dir' => 'asc;select 1',
        ]);
        $this->assertSame(0, $bishops->total());
    }

    private function seedPasswordClient(): void
    {
        if (DB::table('oauth_clients')->exists()) {
            return;
        }

        DB::table('oauth_clients')->insert([
            'id' => (string) Str::uuid(),
            'owner_type' => null,
            'owner_id' => null,
            'name' => 'Test Password Client',
            'secret' => null,
            'provider' => 'module_users',
            'redirect_uris' => json_encode([]),
            'grant_types' => json_encode(['password', 'refresh_token']),
            'revoked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createActiveUser(string $password): User
    {
        $tenant = Tenant::factory()->create();
        $role = Role::query()->create([
            'name' => 'Secretary',
            'description' => 'Secretary',
            'level' => 3,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'active' => 1,
            'email' => 'refresh-user@example.test',
            'password' => Hash::make($password),
        ]);
    }
}
