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
                'message' => mb_substr($e->getMessage(), 0, 300),
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
        $mailer = (string) config('mail.default');
        $raw = $e->getMessage();
        $lower = strtolower($raw);

        if ($mailer === 'resend') {
            if (str_contains($lower, 'timed out') || str_contains($raw, 'Connection could not be established') || str_contains($lower, 'could not resolve')) {
                return 'Could not reach the Resend API over HTTPS. Confirm the production server can open outbound port 443 to api.resend.com, then run php artisan config:cache.';
            }
            if (str_contains($lower, 'domain') || str_contains($lower, 'not verified')) {
                return 'Resend has not verified the sending domain. Publish the DNS records Resend shows for ekklesianexus.com. Leave Zoho MX on the root domain so the mailbox still receives mail.';
            }
            if (str_contains($raw, '401') || str_contains($raw, '403') || str_contains($lower, 'api key') || str_contains($lower, 'unauthorized')) {
                return 'Resend rejected the API key. Set RESEND_KEY on the production API server, then run php artisan config:cache.';
            }

            return 'The Resend transport rejected the test. Confirm MAIL_MAILER=resend, RESEND_KEY, and MAIL_FROM_ADDRESS on the production server.';
        }

        if ($mailer === 'smtp' && (str_contains($raw, '535') || str_contains($lower, 'authenticat'))) {
            return 'The SMTP server rejected the username or password, or MAIL_HOST does not match this mailbox datacenter. In Zoho Mail open Settings → Mail Accounts → SMTP and copy that host (India accounts use smtppro.zoho.in, not smtppro.zoho.com). Update MAIL_HOST / MAIL_USERNAME / MAIL_PASSWORD on the API server, then run php artisan config:cache.';
        }
        if ($mailer === 'smtp' && (str_contains($lower, 'timed out') || str_contains($raw, 'Connection could not be established'))) {
            return 'Could not connect to the mail server. DigitalOcean blocks outbound SMTP on ports 25, 465, and 587. Production must use MAIL_MAILER=resend.';
        }

        return 'The mail transport rejected the test. The enquiry recipient was not changed.';
    }
}
