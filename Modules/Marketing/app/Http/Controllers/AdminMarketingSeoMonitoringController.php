<?php

namespace Modules\Marketing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Marketing\Http\Requests\StoreMarketingSeoMonitoringScanRequest;
use Modules\Marketing\Services\MarketingSeoMonitoringService;

class AdminMarketingSeoMonitoringController extends Controller
{
    public function snapshot(MarketingSeoMonitoringService $monitoring): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $monitoring->getSnapshot(),
        ]);
    }

    public function store(
        StoreMarketingSeoMonitoringScanRequest $request,
        MarketingSeoMonitoringService $monitoring,
    ): JsonResponse {
        $user = $request->user();
        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
                'data' => null,
            ], 401);
        }

        $result = $monitoring->runScan($user, $request->includeProduction());

        if ($result['conflict']) {
            return response()->json([
                'success' => false,
                'message' => 'A scan is already running.',
                'data' => $result['data'],
            ], 409);
        }

        if ($result['status'] >= 500) {
            return response()->json([
                'success' => false,
                'message' => 'Scan failed.',
                'data' => $result['data'],
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Scan completed.',
            'data' => $result['data'],
        ]);
    }
}
