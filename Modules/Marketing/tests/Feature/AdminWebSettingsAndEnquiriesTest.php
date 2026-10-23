<?php

namespace Modules\Marketing\Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Marketing\Mail\ContactEnquiryNotificationMail;
use Modules\Marketing\Models\ContactEnquiry;
use Modules\Marketing\Models\WebSetting;
use Modules\RolesAndPermissions\Models\Permission;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class AdminWebSettingsAndEnquiriesTest extends TestCase
{
    use ActsAsTenantRoles;

    public function test_guest_cannot_read_web_settings(): void
    {
        $this->getJson('/api/admin/web-settings')->assertUnauthorized();
        $this->get('/api/admin/contact-enquiries')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED')
            ->assertJsonPath('message', 'Your session has expired. Please sign in again to continue.');
    }

    public function test_tenant_admin_cannot_read_or_update_web_settings(): void
    {
        $this->asTenantAdmin();

        $this->getJson('/api/admin/web-settings')->assertForbidden();
        $this->putJson('/api/admin/web-settings', [
            'contact_recipient_email' => 'ops@example.com',
            'contact_form_enabled' => true,
            'captcha_enabled' => true,
            'captcha_ttl_seconds' => 300,
            'enquiry_retention_days' => 730,
        ])->assertForbidden();
    }

    public function test_super_admin_can_read_and_update_recipient(): void
    {
        $this->asSuperAdmin();

        $this->getJson('/api/admin/web-settings')
            ->assertOk()
            ->assertJsonPath('data.contact_recipient_email', 'contact@ekklesianexus.com')
            ->assertJsonMissingPath('data.smtp_password')
            ->assertJsonMissingPath('data.mail_password');

        $this->putJson('/api/admin/web-settings', [
            'contact_recipient_email' => 'ops@example.com',
            'contact_form_enabled' => true,
            'captcha_enabled' => true,
            'captcha_ttl_seconds' => 180,
            'enquiry_retention_days' => 365,
        ])->assertOk()->assertJsonPath('data.contact_recipient_email', 'ops@example.com');

        $this->assertSame('ops@example.com', WebSetting::query()->first()?->contact_recipient_email);
    }

    public function test_ekklesia_admin_can_access_web_settings(): void
    {
        $this->asPlatformRole(Role::EKKLESIA_ADMIN, Role::LEVEL_EKKLESIA_ADMIN);

        $this->getJson('/api/admin/web-settings')->assertOk();
    }

    public function test_ekklesia_manager_requires_permission(): void
    {
        $this->asPlatformRole(Role::EKKLESIA_MANAGER, Role::LEVEL_EKKLESIA_MANAGER);

        $this->getJson('/api/admin/web-settings')->assertForbidden();
    }

    public function test_ekklesia_manager_with_permission_can_view(): void
    {
        $context = $this->asPlatformRole(Role::EKKLESIA_MANAGER, Role::LEVEL_EKKLESIA_MANAGER);
        $permission = Permission::updateOrCreate(
            ['name' => 'web_settings.view'],
            [
                'display_name' => 'View Web Settings',
                'module' => 'WebSettings',
                'scope' => Permission::SCOPE_PLATFORM,
                'active' => 1,
                'is_custom' => false,
            ]
        );
        $context['role']->permissions()->syncWithoutDetaching([$permission->id]);
        $this->refreshUserPermissionState($context['user']);
        Passport::actingAs($context['user']->fresh());

        $this->getJson('/api/admin/web-settings')->assertOk();
    }

    public function test_test_email_reports_smtp_authentication_failure(): void
    {
        $this->asSuperAdmin();
        config(['mail.default' => 'smtp']);
        Mail::shouldReceive('to')->once()->andThrow(
            new \RuntimeException('Failed to authenticate on SMTP server with username "contact@ekklesianexus.com". 535 Authentication Failed')
        );

        $response = $this->postJson('/api/admin/web-settings/test-email')
            ->assertStatus(502)
            ->assertJsonPath('success', false);

        $this->assertStringContainsString(
            'smtppro.zoho.in',
            (string) $response->json('message'),
        );
    }

    public function test_resend_diagnostics_require_an_api_key(): void
    {
        $this->asSuperAdmin();
        config([
            'mail.default' => 'resend',
            'services.resend.key' => '',
            'mail.from.address' => 'contact@ekklesianexus.com',
        ]);

        $this->getJson('/api/admin/web-settings')
            ->assertOk()
            ->assertJsonPath('data.diagnostics.mailer', 'resend')
            ->assertJsonPath('data.diagnostics.api_key_configured', false)
            ->assertJsonPath('data.diagnostics.transport_ready', false)
            ->assertJsonPath('data.diagnostics.contact_email_will_deliver', false)
            ->assertJsonMissingPath('data.diagnostics.resend_key');

        config(['services.resend.key' => 're_test_key']);

        $this->getJson('/api/admin/web-settings')
            ->assertOk()
            ->assertJsonPath('data.diagnostics.api_key_configured', true)
            ->assertJsonPath('data.diagnostics.transport_ready', true);
    }

    public function test_test_email_reports_resend_domain_failure_without_smtp_guidance(): void
    {
        $this->asSuperAdmin();
        config(['mail.default' => 'resend']);
        Mail::shouldReceive('to')->once()->andThrow(
            new \RuntimeException('Resend API error: domain is not verified')
        );

        $response = $this->postJson('/api/admin/web-settings/test-email')
            ->assertStatus(502)
            ->assertJsonPath('success', false);

        $message = (string) $response->json('message');
        $this->assertStringContainsString('Resend', $message);
        $this->assertStringNotContainsString('smtppro.zoho.in', $message);
    }

    public function test_invalid_recipient_is_rejected(): void
    {
        $this->asSuperAdmin();
        $this->putJson('/api/admin/web-settings', [
            'contact_recipient_email' => 'not-an-email',
            'contact_form_enabled' => true,
            'captcha_enabled' => true,
            'captcha_ttl_seconds' => 300,
            'enquiry_retention_days' => 730,
        ])->assertUnprocessable()->assertJsonValidationErrors(['contact_recipient_email']);
    }

    public function test_enquiries_can_be_listed_filtered_sorted_and_opened(): void
    {
        $this->asSuperAdmin();
        ContactEnquiry::factory()->create([
            'full_name' => 'Alpha One',
            'email' => 'alpha@example.com',
            'enquiry_status' => ContactEnquiry::STATUS_UNREAD,
            'delivery_status' => ContactEnquiry::DELIVERY_FAILED,
            'reference_number' => 'ENQ-20261009-AAAAAA',
        ]);
        ContactEnquiry::factory()->create([
            'full_name' => 'Beta Two',
            'email' => 'beta@example.com',
            'enquiry_status' => ContactEnquiry::STATUS_READ,
            'delivery_status' => ContactEnquiry::DELIVERY_SENT,
            'reference_number' => 'ENQ-20261009-BBBBBB',
        ]);

        $this->getJson('/api/admin/contact-enquiries?search=alpha&sort=full_name&direction=asc')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.email', 'alpha@example.com');

        $this->getJson('/api/admin/contact-enquiries?enquiry_status=read')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.full_name', 'Beta Two');

        $id = ContactEnquiry::query()->where('email', 'alpha@example.com')->value('id');
        $this->getJson('/api/admin/contact-enquiries/'.$id)
            ->assertOk()
            ->assertJsonPath('data.organisation', 'St Mary Parish')
            ->assertJsonPath('data.subject', 'We would like to learn more about EkklesiaNexus.')
            ->assertJsonPath('data.message', 'We would like to learn more about EkklesiaNexus.');
    }

    public function test_status_update_and_retry_email(): void
    {
        Mail::fake();
        $this->asSuperAdmin();
        $enquiry = ContactEnquiry::factory()->create([
            'delivery_status' => ContactEnquiry::DELIVERY_FAILED,
            'enquiry_status' => ContactEnquiry::STATUS_UNREAD,
        ]);

        $this->patchJson('/api/admin/contact-enquiries/'.$enquiry->id, [
            'enquiry_status' => ContactEnquiry::STATUS_IN_PROGRESS,
            'is_read' => true,
        ])->assertOk()->assertJsonPath('data.enquiry_status', ContactEnquiry::STATUS_IN_PROGRESS);

        $this->postJson('/api/admin/contact-enquiries/'.$enquiry->id.'/retry-email')
            ->assertOk();

        Mail::assertSent(ContactEnquiryNotificationMail::class);
    }

    public function test_tenant_cannot_list_enquiries(): void
    {
        $this->asTenantAdmin();
        $this->getJson('/api/admin/contact-enquiries')->assertForbidden();
    }

    public function test_delete_requires_permission_path_for_manager(): void
    {
        $this->asPlatformRole(Role::EKKLESIA_MANAGER, Role::LEVEL_EKKLESIA_MANAGER);
        $enquiry = ContactEnquiry::factory()->create();
        $this->deleteJson('/api/admin/contact-enquiries/'.$enquiry->id)->assertForbidden();
        $this->postJson('/api/admin/contact-enquiries/bulk-delete', [
            'ids' => [$enquiry->id],
        ])->assertForbidden();
    }

    public function test_super_admin_can_delete_single_and_bulk_enquiries(): void
    {
        $this->asSuperAdmin();
        $keep = ContactEnquiry::factory()->create(['email' => 'keep@example.com']);
        $removeA = ContactEnquiry::factory()->create(['email' => 'remove-a@example.com']);
        $removeB = ContactEnquiry::factory()->create(['email' => 'remove-b@example.com']);

        $this->deleteJson('/api/admin/contact-enquiries/'.$removeA->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('contact_enquiries', ['id' => $removeA->id]);
        $this->assertDatabaseHas('contact_enquiries', ['id' => $keep->id, 'deleted_at' => null]);

        $this->postJson('/api/admin/contact-enquiries/bulk-delete', [
            'ids' => [$removeB->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.deleted_count', 1);

        $this->assertSoftDeleted('contact_enquiries', ['id' => $removeB->id]);
    }

    /**
     * @return array{user: User, role: Role}
     */
    private function asPlatformRole(string $roleName, int $level): array
    {
        $role = Role::query()->firstOrCreate(
            ['name' => $roleName, 'tenant_id' => null],
            [
                'description' => $roleName,
                'level' => $level,
                'active' => 1,
                'is_custom' => false,
                'role_type' => Role::ROLE_TYPE_PLATFORM,
            ]
        );

        $user = User::factory()->create([
            'user_type' => null,
            'is_primary_admin' => $roleName === Role::EKKLESIA_ADMIN,
            'tenant_id' => null,
            'role_id' => $role->id,
            'active' => 1,
        ]);
        $user->syncRoles([$role->id]);
        $this->refreshUserPermissionState($user);
        Passport::actingAs($user->fresh());

        return ['user' => $user->fresh(), 'role' => $role];
    }
}
