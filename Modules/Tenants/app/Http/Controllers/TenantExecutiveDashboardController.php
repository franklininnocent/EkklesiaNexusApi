<?php

namespace Modules\Tenants\Http\Controllers;

use App\Support\ApiErrorResponse;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Tenants\Http\Concerns\VerifiesParishProductAccess;
use Modules\Tenants\Services\TenantExecutiveDashboardComposer;
use Modules\Tenants\Support\TenantContext;

class TenantExecutiveDashboardController extends Controller
{
    use VerifiesParishProductAccess;

    public function __construct(
        private readonly TenantExecutiveDashboardComposer $composer,
    ) {}

    public function executive(Request $request): JsonResponse
    {
        if ($error = $this->verifyParishProductUser(
            $request->user(),
            'Open a church to view the parish dashboard.',
            'Parish context is required. Start a Support Center session for the parish you are helping.',
        )) {
            return $error;
        }

        try {
            $validated = $request->validate([
                'bundle' => 'nullable|string|in:all,primary,secondary,snapshot,celebrations,quick_actions,stewardship,mass,pastoral,ministries',
            ]);
            $bundle = $validated['bundle'] ?? 'all';

            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $payload = $this->composer->build($request->user(), $tenantId, $bundle);

            return response()->json([
                'success' => true,
                'data' => $payload,
            ]);
        } catch (\Throwable $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Executive dashboard failed', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to load the dashboard right now.',
            ], 500);
        }
    }
}
