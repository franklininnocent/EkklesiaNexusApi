<?php

namespace Modules\ApplicationAccess\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Modules\ApplicationAccess\Models\ApplicationAccessEvent;
use Modules\ApplicationAccess\Models\ApplicationAccessSession;
use Modules\ApplicationAccess\Models\ApplicationSecurityEvent;
use Modules\ApplicationAccess\Repositories\ApplicationAccessSessionRepository;
use Modules\ApplicationAccess\Support\ApplicationAccessEnums;
use Modules\ApplicationAccess\Support\ApplicationAccessPermissionCatalog;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplicationAccessReadApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['applicationaccess.telemetry_enabled' => true]);

        app()->instance(TenantContext::class, TenantContext::empty());
        User::flushRequestPermissionCache();
        Cache::flush();

        $this->seedRolesAndPermissions();
    }

    #[Test]
    public function super_admin_can_read_dashboard_and_lists(): void
    {
        $admin = $this->createPlatformUser(Role::SUPER_ADMIN);
        $fixture = $this->seedSessionFixture();

        Passport::actingAs($admin);

        $this->getJson('/api/admin/application-access/dashboard')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['kpis', 'windows']]);

        $this->getJson('/api/admin/application-access/sessions')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/admin/application-access/events')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/admin/application-access/security-events')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/admin/application-access/sessions/'.$fixture['session']->id)
            ->assertOk()
            ->assertJsonPath('data.id', $fixture['session']->id);

        $this->getJson('/api/admin/application-access/signals')
            ->assertOk();
    }

    #[Test]
    public function tenant_user_is_denied_all_read_endpoints(): void
    {
        $tenant = Tenant::factory()->create();
        $role = Role::query()->create([
            'name' => 'Administrator',
            'description' => 'Tenant admin',
            'level' => 10,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'active' => 1,
            'password' => Hash::make('password'),
        ]);

        Passport::actingAs($user);

        foreach ([
            '/api/admin/application-access/dashboard',
            '/api/admin/application-access/sessions',
            '/api/admin/application-access/events',
            '/api/admin/application-access/security-events',
            '/api/admin/application-access/signals',
        ] as $path) {
            $this->getJson($path)->assertForbidden();
        }
    }

    #[Test]
    public function dual_hat_ekklesia_admin_without_support_session_can_view(): void
    {
        $tenant = Tenant::factory()->create();
        $role = Role::query()->where('name', Role::EKKLESIA_ADMIN)->firstOrFail();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'active' => 1,
            'password' => Hash::make('password'),
        ]);

        Passport::actingAs($user);

        $this->getJson('/api/admin/application-access/dashboard')->assertOk();
    }

    #[Test]
    public function per_page_all_is_rejected_with_validation_error(): void
    {
        $admin = $this->createPlatformUser(Role::SUPER_ADMIN);
        Passport::actingAs($admin);

        $this->getJson('/api/admin/application-access/sessions?per_page=all')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    #[Test]
    public function view_only_user_sees_masked_email_but_investigator_sees_full_email(): void
    {
        $fixture = $this->seedSessionFixture();

        $viewer = $this->createViewOnlyPlatformUser();
        Passport::actingAs($viewer);

        $masked = $this->getJson('/api/admin/application-access/sessions/'.$fixture['session']->id)
            ->assertOk()
            ->json('data.user.email');

        $this->assertStringContainsString('***', (string) $masked);
        $this->assertStringNotContainsString($fixture['subject']->email, (string) $masked);

        $investigator = $this->createPlatformUser(Role::SUPER_ADMIN);
        Passport::actingAs($investigator);

        $this->getJson('/api/admin/application-access/sessions/'.$fixture['session']->id)
            ->assertOk()
            ->assertJsonPath('data.user.email', $fixture['subject']->email);
    }

    #[Test]
    public function email_filter_requires_investigate_permission(): void
    {
        $fixture = $this->seedSessionFixture();
        $viewer = $this->createViewOnlyPlatformUser();
        Passport::actingAs($viewer);

        $this->getJson('/api/admin/application-access/sessions?q='.urlencode($fixture['subject']->email))
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson('/api/admin/application-access/sessions?email='.$fixture['subject']->email)
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $investigator = $this->createPlatformUser(Role::SUPER_ADMIN);
        Passport::actingAs($investigator);

        $this->getJson('/api/admin/application-access/sessions?email='.$fixture['subject']->email)
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function opening_investigation_records_self_audit_once_per_target(): void
    {
        $fixture = $this->seedSessionFixture();
        $admin = $this->createPlatformUser(Role::SUPER_ADMIN);
        Passport::actingAs($admin);

        $this->getJson('/api/admin/application-access/sessions/'.$fixture['session']->id)->assertOk();
        $this->getJson('/api/admin/application-access/sessions/'.$fixture['session']->id)->assertOk();

        $this->assertSame(
            1,
            ApplicationSecurityEvent::query()
                ->where('event_type', 'PRIVILEGED_OPERATION')
                ->where('reason_code', 'investigation_open')
                ->where('resource_id', $fixture['session']->id)
                ->count()
        );
    }

    #[Test]
    public function super_admin_can_export_sessions_csv(): void
    {
        $fixture = $this->seedSessionFixture();
        $admin = $this->createPlatformUser(Role::SUPER_ADMIN);

        Passport::actingAs($admin);

        $response = $this->get('/api/admin/application-access/export/sessions');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString($fixture['session']->session_reference, $response->streamedContent());

        $this->assertDatabaseHas('application_security_events', [
            'event_type' => 'PRIVILEGED_OPERATION',
            'reason_code' => 'export',
        ]);
    }

    #[Test]
    public function session_timeline_returns_cursor_pages(): void
    {
        $fixture = $this->seedSessionFixture();
        $admin = $this->createPlatformUser(Role::SUPER_ADMIN);

        foreach (range(1, 3) as $index) {
            ApplicationAccessEvent::query()->create([
                'id' => (string) Str::uuid(),
                'access_session_id' => $fixture['session']->id,
                'user_id' => $fixture['subject']->id,
                'event_type' => 'VIEW',
                'action' => 'VIEW',
                'occurred_at' => now()->subMinutes($index),
                'telemetry_source' => ApplicationAccessEnums::TELEMETRY_APPLICATION,
            ]);
        }

        Passport::actingAs($admin);

        $first = $this->getJson('/api/admin/application-access/sessions/'.$fixture['session']->id.'/timeline?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.has_more', true);

        $cursor = $first->json('meta.next_cursor');
        $this->assertNotEmpty($cursor);

        $this->getJson('/api/admin/application-access/sessions/'.$fixture['session']->id.'/timeline?per_page=2&cursor='.$cursor)
            ->assertOk()
            ->assertJsonPath('meta.has_more', false);
    }

    /**
     * @return array{subject: User, session: ApplicationAccessSession}
     */
    private function seedSessionFixture(): array
    {
        $tenant = Tenant::factory()->create();
        $role = Role::query()->create([
            'name' => 'Administrator',
            'description' => 'Tenant admin',
            'level' => 10,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $subject = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'name' => 'Fixture Parish User',
            'email' => 'fixture.user@parish.test',
            'active' => 1,
            'password' => Hash::make('password'),
        ]);

        $session = app(ApplicationAccessSessionRepository::class)->create([
            'oauth_access_token_id' => (string) Str::uuid(),
            'user_id' => $subject->id,
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'identity_type' => ApplicationAccessEnums::IDENTITY_TENANT_USER,
            'access_context' => ApplicationAccessEnums::CONTEXT_TENANT,
            'authentication_status' => 'authenticated',
            'status' => ApplicationAccessEnums::SESSION_ACTIVE,
            'started_at' => now()->subHour(),
            'last_activity_at' => now()->subMinutes(5),
            'ip_address' => '203.0.113.44',
            'country' => 'US',
            'city' => 'Chicago',
        ]);

        ApplicationAccessEvent::query()->create([
            'id' => (string) Str::uuid(),
            'access_session_id' => $session->id,
            'user_id' => $subject->id,
            'tenant_id' => $tenant->id,
            'event_type' => 'VIEW',
            'action' => 'VIEW',
            'authorization_result' => 'allowed',
            'occurred_at' => now()->subMinutes(10),
            'telemetry_source' => ApplicationAccessEnums::TELEMETRY_APPLICATION,
        ]);

        ApplicationSecurityEvent::query()->create([
            'id' => (string) Str::uuid(),
            'event_type' => 'LOGIN_SUCCESS',
            'severity' => 'LOW',
            'actor_user_id' => $subject->id,
            'tenant_id' => $tenant->id,
            'access_session_id' => $session->id,
            'authorization_result' => 'allowed',
            'reason_code' => 'login',
            'detected_at' => now()->subMinutes(10),
        ]);

        return ['subject' => $subject, 'session' => $session];
    }

    private function seedRolesAndPermissions(): void
    {
        foreach ([
            [Role::SUPER_ADMIN, Role::LEVEL_SUPER_ADMIN],
            [Role::EKKLESIA_ADMIN, Role::LEVEL_EKKLESIA_ADMIN],
        ] as [$name, $level]) {
            Role::query()->updateOrCreate(
                ['name' => $name],
                [
                    'description' => $name,
                    'level' => $level,
                    'active' => 1,
                    'tenant_id' => null,
                    'role_type' => Role::ROLE_TYPE_PLATFORM,
                ]
            );
        }

        ApplicationAccessPermissionCatalog::syncPermissionsAndRoles();
    }

    private function createPlatformUser(string $roleName): User
    {
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        return User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
            'active' => 1,
            'password' => Hash::make('password'),
        ]);
    }

    private function createViewOnlyPlatformUser(): User
    {
        $role = Role::query()->updateOrCreate(
            ['name' => Role::EKKLESIA_USER],
            [
                'description' => Role::EKKLESIA_USER,
                'level' => Role::LEVEL_EKKLESIA_USER,
                'active' => 1,
                'tenant_id' => null,
                'role_type' => Role::ROLE_TYPE_PLATFORM,
            ]
        );

        $permission = Permission::query()->where('name', 'application_access.view')->firstOrFail();
        $role->permissions()->sync([$permission->id]);

        return User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
            'active' => 1,
            'password' => Hash::make('password'),
        ]);
    }
}
