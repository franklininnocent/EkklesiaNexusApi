<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Http\Requests\DashboardDateRangeRequest;
use Modules\Donations\Http\Requests\FamilyCollectDuesRequest;
use Modules\Donations\Services\DashboardPersonaService;
use Modules\Donations\Services\FamilyCollectDuesService;
use Modules\Donations\Services\DioceseRollupDashboardService;
use Modules\Donations\Services\DonationDashboardService;
use Modules\Donations\Services\DonationSavedViewService;
use Modules\Donations\Services\FamilyFinancialProfileService;
use Modules\Donations\Services\FamilyStatementPrintService;
use Modules\Donations\Services\FinancialCommandCenterService;
use Modules\Tenants\Support\TenantContext;

class DonationDashboardController extends Controller
{
    public function __construct(
        private readonly DonationDashboardService $dashboardService,
        private readonly DioceseRollupDashboardService $rollupService,
        private readonly FamilyFinancialProfileService $profileService,
        private readonly DashboardPersonaService $personaService,
        private readonly DonationSavedViewService $savedViewService,
        private readonly FamilyStatementPrintService $familyStatementPrintService,
        private readonly FinancialCommandCenterService $commandCenterService,
        private readonly FamilyCollectDuesService $collectDuesService
    ) {}

    public function summary(DashboardDateRangeRequest $request): JsonResponse
    {
        $user = Auth::user();
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $summary = $this->dashboardService->getSummary(
            $tenantId,
            $request->resolvedRange(),
            $request->resolvedBccFilter(),
            $request->resolvedProjectFilter()
        );
        $persona = $this->personaService->resolve($user, $summary['tenant_context'] ?? null);

        return response()->json([
            'success' => true,
            'data' => array_merge($summary, [
                'persona' => $persona,
                'saved_views' => $this->savedViewService->presets(),
            ]),
        ]);
    }

    public function commandCenter(): JsonResponse
    {
        $user = Auth::user();
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $period = (string) request()->query('period', 'month');
        $payload = $this->commandCenterService->build($tenantId, $user, $period);
        $persona = $this->personaService->resolve($user, $payload['tenant_context'] ?? null);

        return response()->json([
            'success' => true,
            'data' => array_merge($payload, [
                'persona' => $persona,
                'saved_views' => $this->savedViewService->presets(),
            ]),
        ]);
    }

    public function rollup(): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

        return response()->json([
            'success' => true,
            'data' => $this->rollupService->build($tenantId),
        ]);
    }

    public function familySummary(string $familyId): JsonResponse
    {
        try {
            $profile = $this->profileService->build(app(TenantContext::class)->requireEffectiveTenantId(), $familyId);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'family_id' => $familyId,
                'total_paid' => $profile['totals']['total_paid'],
                'pending_due' => $profile['totals']['pending_due'],
                'pending_mandatory_due' => $profile['totals']['pending_mandatory_due'],
                'pending_project_due' => $profile['totals']['pending_project_due'],
                'overdue_count' => $profile['totals']['overdue_count'],
            ],
        ]);
    }

    public function familyFinancialProfile(string $familyId): JsonResponse
    {
        try {
            $profile = $this->profileService->build(app(TenantContext::class)->requireEffectiveTenantId(), $familyId);
        } catch (\RuntimeException $exception) {
            $status = str_contains($exception->getMessage(), 'does not belong') ? 404 : 422;

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $status);
        }

        return response()->json([
            'success' => true,
            'data' => $profile,
        ]);
    }

    public function familyCollectDues(FamilyCollectDuesRequest $request, string $familyId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $validated = $request->validated();

        try {
            $data = $this->collectDuesService->build($tenantId, $familyId, [
                'project_id' => $validated['project_id'] ?? null,
                'page' => $validated['page'] ?? null,
                'per_page' => $validated['per_page'] ?? null,
                'scope' => $validated['scope'] ?? null,
                'bulk_select' => (bool) ($validated['bulk_select'] ?? false),
            ]);
        } catch (\RuntimeException $exception) {
            $status = str_contains($exception->getMessage(), 'does not belong') ? 404 : 422;

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $status);
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function familyStatementPrint(string $familyId)
    {
        try {
            $payload = $this->familyStatementPrintService->buildPayload(app(TenantContext::class)->requireEffectiveTenantId(), $familyId);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        $html = $this->familyStatementPrintService->renderHtml($payload);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }
}
