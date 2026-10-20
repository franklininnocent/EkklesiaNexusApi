<?php

namespace Modules\Marketing\Http\Controllers;

use App\Support\UserFacingDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Marketing\Http\Requests\UpdateWebSettingsRequest;
use Modules\Marketing\Mail\WebSettingsTestMail;
use Modules\Marketing\Services\WebSettingsService;
use Throwable;

class AdminWebSettingsController extends Controller
{
    public function show(WebSettingsService $settings): JsonResponse
    {
        $row = $settings->current();
        $diagnostics = $settings->diagnostics();

        return response()->json([
            'success' => true,
            'data' => [
                'contact_recipient_email' => $row->contact_recipient_email,
                'contact_form_enabled' => (bool) $row->contact_form_enabled,
                'captcha_enabled' => (bool) $row->captcha_enabled,
                'captcha_ttl_seconds' => (int) $row->captcha_ttl_seconds,
                'enquiry_retention_days' => (int) $row->enquiry_retention_days,
                'updated_at' => $row->updated_at?->toIso8601String(),
                'updated_at_label' => UserFacingDate::formatDateTime($row->updated_at),
                'diagnostics' => $diagnostics,
            ],
        ]);
    }

    public function update(UpdateWebSettingsRequest $request, WebSettingsService $settings): JsonResponse
    {
        $row = $settings->update($request->validated(), $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Web settings saved.',
            'data' => [
                'contact_recipient_email' => $row->contact_recipient_email,
                'contact_form_enabled' => (bool) $row->contact_form_enabled,
                'captcha_enabled' => (bool) $row->captcha_enabled,
                'captcha_ttl_seconds' => (int) $row->captcha_ttl_seconds,
                'enquiry_retention_days' => (int) $row->enquiry_retention_days,
                'diagnostics' => $settings->diagnostics(),
            ],
        ]);
    }

    public function sendTestEmail(WebSettingsService $settings): JsonResponse
    {
        $recipient = $settings->recipientEmail();
        if ($recipient === null) {
            return response()->json([
                'success' => false,
                'message' => 'Save a valid contact enquiry recipient before sending a test email.',
            ], 422);
        }

        try {
            Mail::to($recipient)->send(new WebSettingsTestMail);
        } catch (Throwable $e) {
            Log::warning('web.settings.test_email_failed', [
                'mailer' => (string) config('mail.default'),
                'host' => (string) config('mail.mailers.smtp.host'),
                'port' => (int) config('mail.mailers.smtp.port'),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $this->testEmailFailureMessage($e),
            ], 502);
        }

        return response()->json([
            'success' => true,
            'message' => 'A test message was handed to the mail transport. This does not confirm inbox delivery.',
        ]);
    }

    private function testEmailFailureMessage(Throwable $e): string
    {
        $raw = $e->getMessage();
        if (str_contains($raw, '535') || str_contains(strtolower($raw), 'authenticat')) {
            return 'The SMTP server rejected the username or password, or MAIL_HOST does not match this mailbox datacenter. In Zoho Mail open Settings → Mail Accounts → SMTP and copy that host (India accounts use smtppro.zoho.in, not smtppro.zoho.com). Update MAIL_HOST / MAIL_USERNAME / MAIL_PASSWORD on the API server, then run php artisan config:cache.';
        }
        if (str_contains(strtolower($raw), 'timed out') || str_contains($raw, 'Connection could not be established')) {
            return 'Could not connect to the mail server. Check MAIL_HOST, MAIL_PORT, and outbound SMTP access on the API server.';
        }

        return 'The mail transport rejected the test. The enquiry recipient was not changed.';
    }
}
