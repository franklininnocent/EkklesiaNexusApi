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

        // #region agent log
        @file_put_contents('/var/www/html/EkklesiaNexus/.cursor/debug-876da3.log', json_encode(['sessionId' => '876da3', 'hypothesisId' => 'D', 'location' => 'PublicContactEnquiryController.php:store', 'message' => 'returning contact enquiry response', 'data' => ['http_status' => 201, 'headers_sent' => headers_sent(), 'enquiry_id' => $enquiry->id], 'timestamp' => (int) round(microtime(true) * 1000)])."\n", FILE_APPEND);
        // #endregion

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
