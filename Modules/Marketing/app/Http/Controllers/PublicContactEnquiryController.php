<?php

namespace Modules\Marketing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Marketing\Http\Requests\StoreContactEnquiryRequest;
use Modules\Marketing\Services\ContactCaptchaService;
use Modules\Marketing\Services\ContactEnquiryService;
use Modules\Marketing\Services\WebSettingsService;
use Modules\Marketing\Support\MarketingClientIp;
use RuntimeException;

class PublicContactEnquiryController extends Controller
{
    public function store(
        StoreContactEnquiryRequest $request,
        ContactCaptchaService $captcha,
        ContactEnquiryService $enquiries,
        WebSettingsService $settings,
    ): JsonResponse {
        $web = $settings->current();
        if (! $web->contact_form_enabled) {
            return $this->safeError('The contact form is temporarily unavailable.', 503);
        }

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

        try {
            $enquiry = $enquiries->submit(
                $request->validated(),
                MarketingClientIp::from($request),
                $request->validated('correlation_id') ?? $request->header('X-Correlation-Id'),
            );
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'contact_form_disabled') {
                return $this->safeError('The contact form is temporarily unavailable.', 503);
            }
            throw $e;
        }

        return response()->json([
            'success' => true,
            'message' => 'Thank you for contacting EkklesiaNexus. Your enquiry has been received successfully, and our team will be in touch.',
            'data' => [
                'reference_number' => $enquiry->reference_number,
            ],
        ], 201);
    }

    private function safeError(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
