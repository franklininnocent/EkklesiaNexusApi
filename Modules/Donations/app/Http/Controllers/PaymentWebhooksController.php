<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Donations\Jobs\ProcessPaymentGatewayWebhookJob;
use Modules\Donations\Services\PaymentWebhookService;

class PaymentWebhooksController extends Controller
{
    public function __construct(private readonly PaymentWebhookService $webhookService)
    {
    }

    public function receive(string $provider, Request $request): JsonResponse
    {
        $signature = $request->header('X-Payment-Signature');
        if (!$this->webhookService->validateSignature($provider, $request->all(), $signature)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid webhook signature.',
            ], 401);
        }

        $event = $this->webhookService->receive($provider, $request->all(), $signature);
        if (config('queue.default') === 'sync') {
            ProcessPaymentGatewayWebhookJob::dispatchSync($event->id);
        } else {
            ProcessPaymentGatewayWebhookJob::dispatch($event->id);
        }

        return response()->json([
            'success' => true,
            'message' => 'Webhook received.',
            'data' => [
                'event_id' => $event->id,
                'status' => $event->status,
            ],
        ], 202);
    }
}
