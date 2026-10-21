<?php

namespace Modules\Marketing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Marketing\Http\Requests\StoreDemoRequestRequest;
use Modules\Marketing\Mail\DemoRequestNotificationMail;
use Modules\Marketing\Models\MarketingDemoRequest;
use Modules\Marketing\Services\ContactCaptchaService;
use Modules\Marketing\Services\WebSettingsService;
use Modules\Marketing\Support\MarketingClientIp;
use Throwable;

class PublicDemoRequestController extends Controller
{
    public function store(
        StoreDemoRequestRequest $request,
        ContactCaptchaService $captcha,
        WebSettingsService $settings,
    ): JsonResponse {
        $web = $settings->current();
        if ($web->captcha_enabled && ! $captcha->verify(
            (string) $request->validated('captcha_challenge_id'),
            (string) $request->validated('captcha_response'),
        )) {
            return response()->json([
                'success' => false,
                'message' => 'The security check was incorrect or has expired. Please try again.',
                'errors' => [
                    'captcha_response' => ['The security check was incorrect or has expired. Please try again.'],
                ],
            ], 422);
        }

        $validated = $request->validated();

        $correlationId = $validated['correlation_id'] ?? $request->header('X-Correlation-Id');

        $record = MarketingDemoRequest::query()->create([
            'name' => $this->sanitizeLine((string) $validated['name']),
            'church_parish' => $this->sanitizeLine((string) $validated['church_parish']),
            'diocese_organization' => $this->nullableLine($validated['diocese_organization'] ?? null),
            'country' => $this->sanitizeLine((string) $validated['country']),
            'email' => strtolower($this->sanitizeLine((string) $validated['email'])),
            'phone' => $this->nullableLine($validated['phone'] ?? null),
            'role' => $this->sanitizeLine((string) $validated['role']),
            'message' => trim((string) $validated['message']),
            'correlation_id' => is_string($correlationId) ? substr($correlationId, 0, 64) : null,
            'source_ip_hash' => $this->hashIp(MarketingClientIp::from($request)),
        ]);

        Log::info('marketing.demo_request.received', [
            'demo_request_id' => $record->id,
            'correlation_id' => $record->correlation_id,
            'country' => $record->country,
        ]);

        $supportAddress = config('email.support_address');
        $demoRequestId = $record->id;
        if (is_string($supportAddress) && $supportAddress !== '') {
            dispatch(function () use ($demoRequestId, $supportAddress): void {
                $fresh = MarketingDemoRequest::query()->find($demoRequestId);
                if ($fresh === null) {
                    return;
                }

                try {
                    Mail::to($supportAddress)->send(new DemoRequestNotificationMail($fresh));
                } catch (Throwable $e) {
                    Log::warning('marketing.demo_request.mail_failed', [
                        'correlation_id' => $fresh->correlation_id,
                        'demo_request_id' => $fresh->id,
                        'message' => $e->getMessage(),
                    ]);
                }
            })->afterResponse();
        }

        return response()->json([
            'success' => true,
            'message' => 'Your demo request has been received.',
        ], 201);
    }

    private function sanitizeLine(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], ' ', $value));
    }

    private function nullableLine(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $clean = $this->sanitizeLine($value);

        return $clean === '' ? null : $clean;
    }

    private function hashIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }
}
