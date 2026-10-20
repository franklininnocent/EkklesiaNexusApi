<?php

namespace Modules\Marketing\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Marketing\Models\WebSetting;
use Modules\Marketing\Services\ContactEnquiryNotificationRecipient;
use Modules\Marketing\Services\WebSettingsService;
use Tests\TestCase;

class ContactEnquiryNotificationRecipientTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_email_from_web_settings(): void
    {
        config([
            'mail.from.name' => 'EkklesiaNexus',
            'app.frontend_url' => 'https://app.ekklesianexus.com',
        ]);

        WebSetting::query()->update(['contact_recipient_email' => 'Contact@Example.COM']);
        Cache::forget(WebSettingsService::CACHE_KEY);

        $resolver = app(ContactEnquiryNotificationRecipient::class);

        $this->assertSame('contact@example.com', $resolver->email());
        $this->assertSame('EkklesiaNexus', $resolver->name());
        $this->assertSame(
            'https://app.ekklesianexus.com/settings/contact-enquiries',
            $resolver->adminEnquiriesUrl(),
        );
    }

    public function test_invalid_email_returns_null(): void
    {
        WebSetting::query()->update(['contact_recipient_email' => 'not-valid']);
        Cache::forget(WebSettingsService::CACHE_KEY);

        $this->assertNull(app(ContactEnquiryNotificationRecipient::class)->email());
    }
}
