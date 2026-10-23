<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Http\Requests\StoreFamilyContributionPlanAssignmentRequest;
use Modules\Donations\Services\ContributionPlanService;

class FamilyContributionPlanAssignmentsController extends Controller
{
    public function __construct(private readonly ContributionPlanService $planService)
    {
    }

    public function store(string $familyId, StoreFamilyContributionPlanAssignmentRequest $request): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();
        $validated = $request->validated();

        try {
            $assignment = $this->planService->saveIndividualFamilyAmount(
                $tenantId,
                $userId,
                $familyId,
                $validated['plan_id'],
                (string) $validated['amount']
            );
        } catch (\RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'does not belong')) {
                return response()->json([
                    'success' => false,
                    'message' => $exception->getMessage(),
                ], 404);
            }

            throw $exception;
        }

        return response()->json([
            'success' => true,
            'message' => 'Family plan amount saved successfully.',
            'data' => $assignment,
        ], 201);
    }
}
