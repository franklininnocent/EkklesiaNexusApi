<?php

namespace Modules\Marketing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Modules\Marketing\Mail\ContactEnquiryNotificationMail;
use Modules\Marketing\Models\ContactEnquiry;
use Modules\Marketing\Models\WebSetting;
use Modules\Marketing\Services\ContactCaptchaService;
use Modules\Marketing\Services\WebSettingsService;
use RuntimeException;
use Tests\TestCase;

class PublicContactEnquiryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function validPayload(string $challengeId, string $answer): array
    {
        return [
            'name' => 'Maria Thomas',
            'email' => 'maria@example.com',
            'phone' => '+91 94424 66036',
            'organisation' => 'St Mary Parish',
            'message' => 'We would like help evaluating EkklesiaNexus for our parish.',
            'website' => '',
            'captcha_challenge_id' => $challengeId,
            'captcha_response' => $answer,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function seedCaptcha(string $answer = 'AB3DK'): array
    {
        $id = '11111111-1111-4111-8111-111111111111';
        app(ContactCaptchaService::class)->seedForTesting($id, $answer);

        return [$id, $answer];
    }

    public function test_captcha_issue_does_not_include_answer(): void
    {
        $response = $this->getJson('/api/public/contact-captcha');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('data.answer')
            ->assertJsonMissingPath('data.captcha');

        $image = (string) $response->json('data.image');
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $image);
        $decoded = base64_decode(substr($image, strlen('data:image/svg+xml;base64,')), true);
        $this->assertNotFalse($decoded);
        $this->assertDoesNotMatchRegularExpression('/answer/i', $decoded);
    }

    public function test_valid_enquiry_persists_and_queues_mail_to_configured_recipient(): void
    {
        Mail::fake();
        [$id, $answer] = $this->seedCaptcha();

        $response = $this->postJson('/api/public/contact-enquiries', $this->validPayload($id, $answer));

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('data.recipient')
            ->assertJsonPath('message', 'Thank you for contacting EkklesiaNexus. Your enquiry has been received successfully, and our team will be in touch.');

        $this->assertDatabaseCount('contact_enquiries', 1);
        $enquiry = ContactEnquiry::query()->first();
        $this->assertNotNull($enquiry);
        $this->assertSame('maria@example.com', $enquiry->email);
        $this->assertSame('St Mary Parish', $enquiry->organisation);
        $this->assertSame(
            'We would like help evaluating EkklesiaNexus for our parish.',
            $enquiry->subject,
        );
        $this->assertFalse(isset($enquiry->tenant_id));
        $this->assertSame(ContactEnquiry::DELIVERY_SENT, $enquiry->fresh()->delivery_status);

        Mail::assertSent(ContactEnquiryNotificationMail::class, function (ContactEnquiryNotificationMail $mail) use ($enquiry) {
            $html = $mail->render();

            return $mail->hasTo('contact@ekklesianexus.com', 'EkklesiaNexus')
                && str_contains($html, (string) $enquiry->reference_number)
                && str_contains($html, 'Maria Thomas')
                && str_contains($html, 'St Mary Parish')
                && str_contains($html, '/settings/contact-enquiries')
                && str_contains($html, '#003070');
        });
    }

    public function test_html_in_message_is_escaped_in_email(): void
    {
        Mail::fake();
        [$id, $answer] = $this->seedCaptcha();
        $payload = $this->validPayload($id, $answer);
        $payload['message'] = '<script>alert(1)</script>Hello Bcc: evil@example.com';

        $this->postJson('/api/public/contact-enquiries', $payload)->assertCreated();

        Mail::assertSent(ContactEnquiryNotificationMail::class, function (ContactEnquiryNotificationMail $mail) {
            $html = $mail->render();

            return ! str_contains($html, '<script>alert(1)</script>')
                && str_contains($html, 'Hello');
        });
    }

    public function test_invalid_captcha_is_rejected(): void
    {
        Mail::fake();
        [$id] = $this->seedCaptcha();

        $this->postJson('/api/public/contact-enquiries', $this->validPayload($id, 'WRONG'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['captcha_response']);

        $this->assertDatabaseCount('contact_enquiries', 0);
        Mail::assertNothingSent();
    }

    public function test_expired_captcha_is_rejected(): void
    {
        [$id, $answer] = $this->seedCaptcha();
        $this->travel(301)->seconds();
        $this->postJson('/api/public/contact-enquiries', $this->validPayload($id, $answer))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['captcha_response']);
        $this->assertDatabaseCount('contact_enquiries', 0);
    }

    public function test_captcha_cannot_be_replayed(): void
    {
        Mail::fake();
        [$id, $answer] = $this->seedCaptcha();
        $this->postJson('/api/public/contact-enquiries', $this->validPayload($id, $answer))->assertCreated();

        $payload = $this->validPayload($id, $answer);
        $payload['email'] = 'second@example.com';
        $this->postJson('/api/public/contact-enquiries', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['captcha_response']);
    }

    public function test_refresh_invalidates_previous_challenge(): void
    {
        Mail::fake();
        [$oldId, $oldAnswer] = $this->seedCaptcha('AAAAA');
        app(ContactCaptchaService::class)->invalidate($oldId);
        [$newId, $newAnswer] = $this->seedCaptcha('BBBBB');
        $newId = '22222222-2222-4222-8222-222222222222';
        app(ContactCaptchaService::class)->seedForTesting($newId, $newAnswer);

        $this->postJson('/api/public/contact-enquiries', $this->validPayload($oldId, $oldAnswer))
            ->assertUnprocessable();

        $this->postJson('/api/public/contact-enquiries', $this->validPayload($newId, $newAnswer))
            ->assertCreated();
    }

    public function test_required_fields_are_validated(): void
    {
        $this->postJson('/api/public/contact-enquiries', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'phone', 'message', 'captcha_challenge_id', 'captcha_response']);
    }

    public function test_honeypot_rejects_spam(): void
    {
        [$id, $answer] = $this->seedCaptcha();
        $payload = $this->validPayload($id, $answer);
        $payload['website'] = 'https://spam.example';

        $this->postJson('/api/public/contact-enquiries', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['website']);
        $this->assertDatabaseCount('contact_enquiries', 0);
    }

    public function test_missing_recipient_persists_enquiry_as_failed(): void
    {
        Mail::fake();
        WebSetting::query()->update(['contact_recipient_email' => 'not-an-email']);
        Cache::forget(WebSettingsService::CACHE_KEY);
        [$id, $answer] = $this->seedCaptcha();

        $this->postJson('/api/public/contact-enquiries', $this->validPayload($id, $answer))
            ->assertCreated();

        $enquiry = ContactEnquiry::query()->first();
        $this->assertSame(ContactEnquiry::DELIVERY_FAILED, $enquiry->delivery_status);
        Mail::assertNothingSent();
    }

    public function test_public_response_never_includes_admin_settings(): void
    {
        Mail::fake();
        [$id, $answer] = $this->seedCaptcha();
        $response = $this->postJson('/api/public/contact-enquiries', $this->validPayload($id, $answer));
        $json = $response->json();
        $this->assertArrayNotHasKey('contact_recipient_email', $json);
        $this->assertStringNotContainsString('franklininnocent.fs@gmail.com', $response->getContent());
    }

    public function test_throttle_limits_repeated_submissions(): void
    {
        Mail::fake();
        config(['tenants.api.rate_limit.enabled' => true]);

        for ($i = 0; $i < 5; $i++) {
            $id = sprintf('33333333-3333-4333-8333-%012d', $i);
            app(ContactCaptchaService::class)->seedForTesting($id, 'AB3DK');
            $payload = $this->validPayload($id, 'AB3DK');
            $payload['email'] = "user{$i}@example.com";
            $this->postJson('/api/public/contact-enquiries', $payload)->assertCreated();
        }

        $id = '33333333-3333-4333-8333-000000000099';
        app(ContactCaptchaService::class)->seedForTesting($id, 'AB3DK');
        $this->postJson('/api/public/contact-enquiries', $this->validPayload($id, 'AB3DK'))
            ->assertStatus(429);
    }

    public function test_default_api_throttle_does_not_overwrite_public_form_headers(): void
    {
        Mail::fake();
        config(['tenants.api.rate_limit.enabled' => true]);

        [$id, $answer] = $this->seedCaptcha();
        $this->postJson('/api/public/contact-enquiries', $this->validPayload($id, $answer))
            ->assertCreated()
            ->assertHeader('X-RateLimit-Limit', '5');
    }

    public function test_marketing_bff_secret_scopes_throttle_to_visitor_ip(): void
    {
        Mail::fake();
        config([
            'tenants.api.rate_limit.enabled' => true,
            'marketing.bff.shared_secret' => 'test-bff-secret',
        ]);

        for ($i = 0; $i < 5; $i++) {
            $id = sprintf('44444444-4444-4444-8444-%012d', $i);
            app(ContactCaptchaService::class)->seedForTesting($id, 'AB3DK');
            $payload = $this->validPayload($id, 'AB3DK');
            $payload['email'] = "visitor-a-{$i}@example.com";
            $this->postJson('/api/public/contact-enquiries', $payload, [
                'X-Marketing-Proxy-Secret' => 'test-bff-secret',
                'X-Marketing-Client-Ip' => '203.0.113.10',
            ])->assertCreated();
        }

        $blockedId = '44444444-4444-4444-8444-000000000099';
        app(ContactCaptchaService::class)->seedForTesting($blockedId, 'AB3DK');
        $this->postJson('/api/public/contact-enquiries', $this->validPayload($blockedId, 'AB3DK'), [
            'X-Marketing-Proxy-Secret' => 'test-bff-secret',
            'X-Marketing-Client-Ip' => '203.0.113.10',
        ])->assertStatus(429);

        $otherId = '55555555-5555-4555-8555-000000000001';
        app(ContactCaptchaService::class)->seedForTesting($otherId, 'AB3DK');
        $this->postJson('/api/public/contact-enquiries', $this->validPayload($otherId, 'AB3DK'), [
            'X-Marketing-Proxy-Secret' => 'test-bff-secret',
            'X-Marketing-Client-Ip' => '203.0.113.20',
        ])->assertCreated();
    }

    public function test_client_supplied_recipient_is_ignored(): void
    {
        Mail::fake();
        [$id, $answer] = $this->seedCaptcha();
        $payload = $this->validPayload($id, $answer);
        $payload['recipient'] = 'attacker@example.com';
        $payload['contact_recipient_email'] = 'attacker@example.com';

        $this->postJson('/api/public/contact-enquiries', $payload)->assertCreated();

        Mail::assertSent(ContactEnquiryNotificationMail::class, function (ContactEnquiryNotificationMail $mail) {
            return $mail->hasTo('contact@ekklesianexus.com')
                && ! $mail->hasTo('attacker@example.com');
        });
    }

    public function test_notification_mail_subject_matches_product_requirement(): void
    {
        Mail::fake();
        [$id, $answer] = $this->seedCaptcha();
        $this->postJson('/api/public/contact-enquiries', $this->validPayload($id, $answer))->assertCreated();

        Mail::assertSent(ContactEnquiryNotificationMail::class, function (ContactEnquiryNotificationMail $mail) {
            return $mail->envelope()->subject === 'New Contact Enquiry Received — EkklesiaNexus';
        });
    }

    public function test_enquiry_notification_is_sent_during_the_submit_request(): void
    {
        Mail::fake();
        [$id, $answer] = $this->seedCaptcha();

        $this->postJson('/api/public/contact-enquiries', $this->validPayload($id, $answer))
            ->assertCreated();

        $this->assertDatabaseCount('contact_enquiries', 1);
        $this->assertSame(ContactEnquiry::DELIVERY_SENT, ContactEnquiry::query()->value('delivery_status'));
        Mail::assertSent(ContactEnquiryNotificationMail::class);
    }

    public function test_mail_transport_timeout_still_accepts_the_enquiry(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException(
            'Connection could not be established with host "smtppro.zoho.in:587": Connection timed out'
        ));
        [$id, $answer] = $this->seedCaptcha();

        $this->postJson('/api/public/contact-enquiries', $this->validPayload($id, $answer))
            ->assertCreated()
            ->assertJsonPath('success', true);

        $enquiry = ContactEnquiry::query()->first();
        $this->assertNotNull($enquiry);
        $this->assertSame(ContactEnquiry::DELIVERY_FAILED, $enquiry->delivery_status);
        $this->assertNotSame('', (string) $enquiry->reference_number);
    }

    public function test_notification_uses_web_settings_recipient_not_super_admin_config(): void
    {
        Mail::fake();
        WebSetting::query()->update([
            'contact_recipient_email' => 'ops-zoho@example.com',
        ]);
        Cache::forget(WebSettingsService::CACHE_KEY);
        config(['authentication.super_admin.email' => 'super-admin-notify@example.test']);
        [$id, $answer] = $this->seedCaptcha();

        $this->postJson('/api/public/contact-enquiries', $this->validPayload($id, $answer))->assertCreated();

        Mail::assertSent(ContactEnquiryNotificationMail::class, function (ContactEnquiryNotificationMail $mail) {
            return $mail->hasTo('ops-zoho@example.com')
                && ! $mail->hasTo('super-admin-notify@example.test');
        });
    }

    public function test_organization_alias_is_stored_as_organisation(): void
    {
        Mail::fake();
        [$id, $answer] = $this->seedCaptcha();
        $payload = $this->validPayload($id, $answer);
        unset($payload['organisation']);
        $payload['organization'] = 'St Joseph Parish';

        $this->postJson('/api/public/contact-enquiries', $payload)->assertCreated();

        $this->assertSame(
            'St Joseph Parish',
            ContactEnquiry::query()->value('organisation'),
        );
    }
}
