<?php

namespace Modules\Marketing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Modules\Marketing\Mail\DemoRequestNotificationMail;
use Modules\Marketing\Models\MarketingDemoRequest;
use Modules\Marketing\Services\ContactCaptchaService;
use RuntimeException;
use Tests\TestCase;

class PublicDemoRequestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array{0: string, 1: string}
     */
    private function seedCaptcha(string $answer = 'AB3DK'): array
    {
        $id = '11111111-1111-4111-8111-111111111111';
        app(ContactCaptchaService::class)->seedForTesting($id, $answer);

        return [$id, $answer];
    }

    private function validPayload(?string $challengeId = null, ?string $answer = null): array
    {
        if ($challengeId === null || $answer === null) {
            [$challengeId, $answer] = $this->seedCaptcha();
        }

        return [
            'name' => 'Maria Thomas',
            'church_parish' => 'St. Mary Parish',
            'diocese_organization' => 'Sample Diocese',
            'country' => 'India',
            'email' => 'maria@example.com',
            'phone' => '+91 90000 00000',
            'role' => 'Parish Secretary',
            'message' => 'We would like to learn more about EkklesiaNexus for our parish.',
            'company_website' => '',
            'captcha_challenge_id' => $challengeId,
            'captcha_response' => $answer,
        ];
    }

    public function test_valid_demo_request_persists_and_returns_success(): void
    {
        Mail::fake();
        config(['email.support_address' => 'support@example.com']);

        $response = $this->postJson('/api/public/demo-requests', $this->validPayload());

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Your demo request has been received.')
            ->assertJsonMissingPath('data.id');

        $this->assertDatabaseCount('marketing_demo_requests', 1);
        $record = MarketingDemoRequest::query()->first();
        $this->assertNotNull($record);
        $this->assertNull($record->source_ip ?? null);
        $this->assertNotNull($record->source_ip_hash);
        $this->assertSame(64, strlen((string) $record->source_ip_hash));
        Mail::assertSent(DemoRequestNotificationMail::class);
    }

    public function test_mail_transport_timeout_still_accepts_the_demo_request(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException(
            'Connection could not be established with host "smtppro.zoho.in:587": Connection timed out'
        ));
        config(['email.support_address' => 'support@example.com']);

        $this->postJson('/api/public/demo-requests', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('marketing_demo_requests', 1);
    }

    public function test_required_fields_are_validated(): void
    {
        $response = $this->postJson('/api/public/demo-requests', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'church_parish', 'country', 'email', 'role', 'message', 'captcha_challenge_id', 'captcha_response']);
    }

    public function test_honeypot_rejects_spam(): void
    {
        $payload = $this->validPayload();
        $payload['company_website'] = 'https://spam.example';

        $response = $this->postJson('/api/public/demo-requests', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['company_website']);
        $this->assertDatabaseCount('marketing_demo_requests', 0);
    }

    public function test_unauthenticated_access_is_allowed(): void
    {
        Mail::fake();
        config(['email.support_address' => 'support@example.com']);

        $this->postJson('/api/public/demo-requests', $this->validPayload())
            ->assertCreated();
    }

    public function test_throttle_limits_repeated_submissions(): void
    {
        Mail::fake();
        config([
            'email.support_address' => 'support@example.com',
            'tenants.api.rate_limit.enabled' => true,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $id = sprintf('66666666-6666-4666-8666-%012d', $i);
            app(ContactCaptchaService::class)->seedForTesting($id, 'AB3DK');
            $payload = $this->validPayload($id, 'AB3DK');
            $payload['email'] = "user{$i}@example.com";
            $this->postJson('/api/public/demo-requests', $payload)->assertCreated();
        }

        $blockedId = '66666666-6666-4666-8666-000000000099';
        app(ContactCaptchaService::class)->seedForTesting($blockedId, 'AB3DK');
        $this->postJson('/api/public/demo-requests', $this->validPayload($blockedId, 'AB3DK'))
            ->assertStatus(429);
    }

    public function test_invalid_captcha_is_rejected(): void
    {
        [$id] = $this->seedCaptcha();

        $this->postJson('/api/public/demo-requests', $this->validPayload($id, 'WRONG'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['captcha_response']);
        $this->assertDatabaseCount('marketing_demo_requests', 0);
    }

    public function test_header_injection_is_stripped_from_stored_fields(): void
    {
        Mail::fake();
        config(['email.support_address' => 'support@example.com']);

        $payload = $this->validPayload();
        $payload['name'] = "Maria\r\nBcc: attacker@example.com";
        $payload['role'] = "Secretary\nBcc: evil@example.com";

        $this->postJson('/api/public/demo-requests', $payload)->assertCreated();

        $record = MarketingDemoRequest::query()->first();
        $this->assertNotNull($record);
        $this->assertStringNotContainsString("\n", (string) $record->name);
        $this->assertStringNotContainsString("\r", (string) $record->name);
        $this->assertStringNotContainsString("\n", (string) $record->role);
        $this->assertStringNotContainsString("\r", (string) $record->role);
    }

    public function test_record_has_no_tenant_id(): void
    {
        Mail::fake();
        config(['email.support_address' => 'support@example.com']);

        $this->postJson('/api/public/demo-requests', $this->validPayload())->assertCreated();

        $record = MarketingDemoRequest::query()->first();
        $this->assertNotNull($record);
        $this->assertFalse(isset($record->tenant_id));
    }
}
