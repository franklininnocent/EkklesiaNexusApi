<?php

namespace Modules\Marketing\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Modules\Authentication\Models\User;
use Modules\Marketing\Models\ContactEnquiry;
use Modules\Marketing\Models\WebSetting;
use Modules\Marketing\Models\WebSettingsAuditLog;

class WebSettingsService
{
    public const CACHE_KEY = 'web_settings.singleton';

    public function current(): WebSetting
    {
        /** @var WebSetting $settings */
        $settings = Cache::remember(self::CACHE_KEY, 60, function () {
            $row = WebSetting::query()->orderBy('id')->first();
            if ($row !== null) {
                return $row;
            }

            return WebSetting::query()->create([
                'contact_recipient_email' => (string) config('marketing.web.default_contact_recipient', 'contact@ekklesianexus.com'),
                'contact_form_enabled' => true,
                'captcha_enabled' => true,
                'captcha_ttl_seconds' => (int) config('marketing.web.captcha.ttl_seconds', 300),
                'enquiry_retention_days' => (int) config('marketing.web.enquiry.retention_days', 730),
            ]);
        });

        return $settings;
    }

    public function recipientEmail(): ?string
    {
        $email = strtolower(trim((string) $this->current()->contact_recipient_email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(array $payload, User $actor): WebSetting
    {
        $settings = $this->current();
        $before = $settings->only([
            'contact_recipient_email',
            'contact_form_enabled',
            'captcha_enabled',
            'captcha_ttl_seconds',
            'enquiry_retention_days',
        ]);

        $settings->fill([
            'contact_recipient_email' => strtolower(trim((string) $payload['contact_recipient_email'])),
            'contact_form_enabled' => (bool) $payload['contact_form_enabled'],
            'captcha_enabled' => (bool) $payload['captcha_enabled'],
            'captcha_ttl_seconds' => (int) $payload['captcha_ttl_seconds'],
            'enquiry_retention_days' => (int) $payload['enquiry_retention_days'],
            'updated_by' => $actor->id,
        ]);
        $settings->save();

        Cache::forget(self::CACHE_KEY);

        $after = $settings->fresh()?->only(array_keys($before)) ?? [];
        $changes = [];
        foreach ($before as $key => $value) {
            if (($after[$key] ?? null) != $value) {
                $changes[$key] = ['from' => $value, 'to' => $after[$key] ?? null];
            }
        }

        if ($changes !== []) {
            WebSettingsAuditLog::query()->create([
                'actor_id' => $actor->id,
                'action' => 'updated',
                'changes' => $changes,
            ]);
        }

        return $settings->fresh();
    }

    public function isValidRecipient(?string $email): bool
    {
        if (! is_string($email) || trim($email) === '') {
            return false;
        }

        $validator = Validator::make(
            ['email' => strtolower(trim($email))],
            ['email' => ['required', 'email:rfc', 'max:255']]
        );

        return ! $validator->fails();
    }

    /**
     * Safe diagnostics — no secrets, passwords, or API keys.
     *
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        $settings = $this->current();
        $mailer = (string) config('mail.default');
        $fromAddress = (string) config('mail.from.address');
        $smtp = config('mail.mailers.smtp', []);

        $recipient = $this->recipientEmail();
        $apiKeyConfigured = trim((string) config('services.resend.key')) !== '';
        $transportReady = $this->transportAppearsReady($mailer, $fromAddress, is_array($smtp) ? $smtp : []);
        $mailerWritesToLogOnly = in_array($mailer, ['log', 'array'], true);
        $stuckGrace = max(60, (int) config('marketing.web.mail.stuck_grace_seconds', 120));
        $failedDeliveryCount = ContactEnquiry::query()
            ->where('delivery_status', ContactEnquiry::DELIVERY_FAILED)
            ->count();
        $stuckReceivedCount = ContactEnquiry::query()
            ->where('delivery_status', ContactEnquiry::DELIVERY_RECEIVED)
            ->where('created_at', '<=', now()->subSeconds($stuckGrace))
            ->count();

        return [
            'contact_form_enabled' => (bool) $settings->contact_form_enabled,
            'captcha_enabled' => (bool) $settings->captcha_enabled,
            'captcha_ttl_seconds' => (int) $settings->captcha_ttl_seconds,
            'recipient_configured' => $recipient !== null,
            'mailer' => $mailer,
            'from_address_configured' => $fromAddress !== '' && filter_var($fromAddress, FILTER_VALIDATE_EMAIL) !== false,
            'from_name_configured' => trim((string) config('mail.from.name')) !== '',
            'queue_connection' => (string) config('queue.default'),
            'api_key_configured' => $mailer !== 'resend' || $apiKeyConfigured,
            'smtp_host_configured' => $mailer !== 'smtp' || trim((string) ($smtp['host'] ?? '')) !== '',
            'smtp_host' => $mailer === 'smtp' ? (trim((string) ($smtp['host'] ?? '')) ?: null) : null,
            'smtp_port' => $mailer === 'smtp' ? (int) ($smtp['port'] ?? 0) : null,
            'smtp_username_configured' => $mailer !== 'smtp' || trim((string) ($smtp['username'] ?? '')) !== '',
            'smtp_password_configured' => $mailer !== 'smtp' || trim((string) ($smtp['password'] ?? '')) !== '',
            'transport_ready' => $transportReady,
            'mailer_writes_to_log_only' => $mailerWritesToLogOnly,
            'contact_email_will_deliver' => $recipient !== null && $transportReady && ! $mailerWritesToLogOnly,
            'failed_delivery_count' => $failedDeliveryCount,
            'stuck_received_count' => $stuckReceivedCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $smtp
     */
    private function transportAppearsReady(string $mailer, string $fromAddress, array $smtp): bool
    {
        if (filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        if (in_array($mailer, ['log', 'array'], true)) {
            return true;
        }

        if ($mailer === 'smtp') {
            return trim((string) ($smtp['host'] ?? '')) !== ''
                && (int) ($smtp['port'] ?? 0) > 0;
        }

        if ($mailer === 'resend') {
            return trim((string) config('services.resend.key')) !== '';
        }

        return $mailer !== '';
    }
}
