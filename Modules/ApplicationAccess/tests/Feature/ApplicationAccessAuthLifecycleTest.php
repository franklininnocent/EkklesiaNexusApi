<?php

namespace Modules\ApplicationAccess\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\ApplicationAccess\Models\ApplicationAccessSession;
use Modules\ApplicationAccess\Models\ApplicationSecurityEvent;
use Modules\ApplicationAccess\Support\ApplicationAccessEnums;
use Modules\ApplicationAccess\Support\IdentifierMasker;
use App\Services\TokenService;
use App\Support\OAuthAccessTokenId;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplicationAccessAuthLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['applicationaccess.telemetry_enabled' => true]);

        if (app()->bound(TenantContext::class)) {
            app()->forgetInstance(TenantContext::class);
        }

        app()->instance(TenantContext::class, TenantContext::empty());
        User::flushRequestPermissionCache();

        $this->seedPasswordGrantClient();
    }

    #[Test]
    public function successful_login_creates_access_session_and_security_event(): void
    {
        $user = $this->createTenantUser('password');

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Login successful');

        $tokenId = OAuthAccessTokenId::normalize((string) DB::table('oauth_access_tokens')
            ->where('user_id', $user->id)
            ->value('id'));

        $session = ApplicationAccessSession::query()
            ->where('oauth_access_token_id', $tokenId)
            ->first();

        $this->assertNotNull($session);
        $this->assertSame($user->id, $session->user_id);
        $this->assertSame(ApplicationAccessEnums::SESSION_ACTIVE, $session->status);
        $this->assertNull($session->ended_at);

        $this->assertDatabaseHas('application_security_events', [
            'event_type' => 'LOGIN_SUCCESS',
            'actor_user_id' => $user->id,
            'access_session_id' => $session->id,
            'reason_code' => 'login',
        ]);

        $this->assertDatabaseHas('application_access_events', [
            'access_session_id' => $session->id,
            'user_id' => $user->id,
            'event_type' => 'SESSION_CREATED',
            'action' => 'LOGIN',
        ]);
    }

    #[Test]
    public function failed_login_records_masked_security_event_without_password(): void
    {
        $email = 'secret.user@example.com';
        $user = $this->createTenantUser('password', $email);

        $response = $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $responseContent = $response->getContent() ?? '';
        $this->assertStringNotContainsString('wrong-password', $responseContent);
        $this->assertStringNotContainsString($email, $responseContent);

        $event = ApplicationSecurityEvent::query()
            ->where('event_type', 'LOGIN_FAILURE')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('invalid_credentials', $event->reason_code);
        $this->assertSame('denied', $event->authorization_result);
        $this->assertSame(
            IdentifierMasker::maskEmail($email),
            $event->metadata['masked_identifier'] ?? null
        );
        $this->assertArrayNotHasKey('password', $event->metadata ?? []);
        $this->assertSame(0, ApplicationAccessSession::query()->count());
    }

    #[Test]
    public function token_refresh_rotates_sessions_and_links_previous_session(): void
    {
        $user = $this->createTenantUser('password');

        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $firstTokenId = OAuthAccessTokenId::normalize((string) DB::table('oauth_access_tokens')
            ->where('user_id', $user->id)
            ->value('id'));

        $firstSession = ApplicationAccessSession::query()
            ->where('oauth_access_token_id', $firstTokenId)
            ->firstOrFail();

        $refresh = $this->postJson('/api/auth/refresh', [
            'refresh_token' => $login->json('refresh_token'),
        ]);

        $refresh->assertOk()
            ->assertJsonPath('message', 'Token refreshed successfully');

        $firstSession->refresh();
        $this->assertSame(ApplicationAccessEnums::SESSION_ENDED, $firstSession->status);
        $this->assertSame('rotated', $firstSession->end_reason);
        $this->assertNotNull($firstSession->ended_at);

        $secondTokenId = OAuthAccessTokenId::normalize((string) DB::table('oauth_access_tokens')
            ->where('user_id', $user->id)
            ->where('revoked', false)
            ->value('id'));

        $secondSession = ApplicationAccessSession::query()
            ->where('oauth_access_token_id', $secondTokenId)
            ->first();

        $this->assertNotNull($secondSession);
        $this->assertSame($firstSession->id, $secondSession->previous_session_id);
        $this->assertNull($secondSession->ended_at);

        $this->assertDatabaseHas('application_access_events', [
            'access_session_id' => $secondSession->id,
            'event_type' => 'SESSION_RENEWED',
            'action' => 'RENEW',
        ]);
    }

    #[Test]
    public function logout_ends_all_active_sessions_for_user(): void
    {
        $user = $this->createTenantUser('password');

        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertSame(1, ApplicationAccessSession::query()->whereNull('ended_at')->count());

        app(TokenService::class)->revokeAllTokens($user->id);

        $sessions = ApplicationAccessSession::query()
            ->where('user_id', $user->id)
            ->get();

        $this->assertCount(1, $sessions);
        $this->assertSame(ApplicationAccessEnums::SESSION_ENDED, $sessions->first()->status);
        $this->assertSame('logout', $sessions->first()->end_reason);
        $this->assertNotNull($sessions->first()->ended_at);
    }

    private function seedPasswordGrantClient(): void
    {
        DB::table('oauth_clients')->insert([
            'id' => (string) Str::uuid(),
            'owner_type' => null,
            'owner_id' => null,
            'name' => 'Test Password Client',
            'secret' => null,
            'provider' => 'module_users',
            'redirect_uris' => json_encode([]),
            'grant_types' => json_encode(['password']),
            'revoked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTenantUser(string $password, ?string $email = null): User
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

        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'active' => 1,
            'email' => $email ?? fake()->unique()->safeEmail(),
            'password' => Hash::make($password),
        ]);
    }
}
