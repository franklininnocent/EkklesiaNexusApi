<?php

namespace Modules\Marketing\Services;

use Illuminate\Support\Str;

/**
 * Resolves the website contact enquiry notification recipient from web settings.
 * Values come from the server-side web_settings row — never from the public form.
 */
class ContactEnquiryNotificationRecipient
{
    public function __construct(private readonly WebSettingsService $webSettings) {}

    public function email(): ?string
    {
        return $this->webSettings->recipientEmail();
    }

    public function name(): string
    {
        $name = trim(str_replace(["\r", "\n"], ' ', (string) config('mail.from.name')));
        if ($name === '') {
            return 'EkklesiaNexus';
        }

        return Str::limit($name, 80, '');
    }

    public function adminEnquiriesUrl(): string
    {
        $base = config('app.frontend_url') ?: config('brand.application_url', 'https://app.ekklesianexus.com');

        return rtrim((string) $base, '/').'/settings/contact-enquiries';
    }
}
