<?php

namespace Modules\Family\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Modules\Family\app\Exceptions\HouseholdTransitionException;
use Modules\Family\app\Http\Requests\CorrectHouseholdHistoryRequest;
use Modules\Family\app\Http\Requests\FamilyUpdateRequestRequest;
use Modules\Family\app\Http\Requests\MarriageTransitionRequest;
use Modules\Family\app\Http\Requests\MergeFamiliesRequest;
use Modules\Family\app\Http\Requests\RelocateBccRequest;
use Modules\Family\app\Http\Requests\SplitFamilyMemberRequest;
use Modules\Family\app\Http\Requests\StoreFamilyMemberRequest;
use Modules\Family\app\Http\Requests\StoreFamilyRequest;
use Modules\Family\app\Http\Requests\UpdateFamilyMemberRequest;
use Modules\Family\app\Http\Requests\UpdateFamilyRequest;
use Modules\Family\app\Http\Requests\UploadFamilyHeadProfileImageRequest;
use Modules\Family\app\Http\Requests\UploadFamilyProfileImageRequest;
use Modules\Family\app\Services\BccRelocationService;
use Modules\Family\app\Services\FamilyMergeService;
use Modules\Family\app\Services\FamilyService;
use Modules\Family\app\Services\FamilySplitService;
use Modules\Family\app\Services\HouseholdTransitionHistoryRecorder;
use Modules\Family\app\Services\MarriageHouseholdService;
use Modules\Family\app\Http\Requests\FamilyDashboardRequest;
use Modules\Family\app\Http\Requests\MemberCelebrationsListRequest;
use Modules\Family\app\Services\FamilyDashboardService;
use Modules\Family\app\Services\MemberCelebrationsService;
use Modules\Family\app\Services\MemberDashboardService;
use Modules\Family\app\Services\ParishionerFamilyAccessService;
use Modules\Family\Models\Family;
use Modules\Tenants\Services\Media\ImageMediaException;
use Modules\Tenants\Services\SupportSessionAuthorizationService;
use Modules\Tenants\Support\TenantContext;

class FamilyController extends Controller
{
    protected FamilyService $familyService;

    protected FamilySplitService $familySplitService;

    protected FamilyMergeService $familyMergeService;

    protected ParishionerFamilyAccessService $parishionerAccessService;

    protected BccRelocationService $bccRelocationService;

    protected MarriageHouseholdService $marriageHouseholdService;

    protected HouseholdTransitionHistoryRecorder $historyRecorder;

    protected MemberCelebrationsService $memberCelebrationsService;

    protected MemberDashboardService $memberDashboardService;

    protected FamilyDashboardService $familyDashboardService;

    /**
     * FamilyController constructor.
     */
    public function __construct(
        FamilyService $familyService,
        FamilySplitService $familySplitService,
        FamilyMergeService $familyMergeService,
        ParishionerFamilyAccessService $parishionerAccessService,
        BccRelocationService $bccRelocationService,
        MarriageHouseholdService $marriageHouseholdService,
        HouseholdTransitionHistoryRecorder $historyRecorder,
        MemberCelebrationsService $memberCelebrationsService,
        MemberDashboardService $memberDashboardService,
        FamilyDashboardService $familyDashboardService,
    ) {
        $this->familyService = $familyService;
        $this->familySplitService = $familySplitService;
        $this->familyMergeService = $familyMergeService;
        $this->parishionerAccessService = $parishionerAccessService;
        $this->bccRelocationService = $bccRelocationService;
        $this->marriageHouseholdService = $marriageHouseholdService;
        $this->historyRecorder = $historyRecorder;
        $this->memberCelebrationsService = $memberCelebrationsService;
        $this->memberDashboardService = $memberDashboardService;
        $this->familyDashboardService = $familyDashboardService;
    }

    private function parishionerMutationForbidden(): ?JsonResponse
    {
        $user = Auth::user();
        if ($user && $this->parishionerAccessService->isParishioner($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Parishioners cannot modify family records. Submit an update request instead.',
            ], 403);
        }

        return null;
    }

    private function denyUnlessCanViewFamilies(): ?JsonResponse
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($this->parishionerAccessService->isParishioner($user)) {
            return null;
        }

        if (app(SupportSessionAuthorizationService::class)->grantsTenantProductAccess($user)) {
            return null;
        }

        if ($user->isTenantAdmin()) {
            return null;
        }

        if (! $user->hasPermission('families.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient permission for this tenant action.',
                'missing_permissions' => ['families.view'],
            ], 403);
        }

        return null;
    }

    private function transitionErrorResponse(HouseholdTransitionException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => $e->errorCode,
            'message' => $e->getMessage(),
            'errors' => $e->errors,
        ], $e->httpStatus);
    }

    public function previewBccRelocation(Request $request, string $id): JsonResponse
    {
        try {
            if ($response = $this->parishionerMutationForbidden()) {
                return $response;
            }

            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $request->validate([
                'target_bcc_id' => ['required', 'uuid'],
            ]);

            $preview = $this->bccRelocationService->previewImpact(
                $id,
                (string) $request->input('target_bcc_id'),
                $tenantId,
            );

            return response()->json([
                'success' => true,
                'data' => $preview,
            ]);
        } catch (HouseholdTransitionException $e) {
            return $this->transitionErrorResponse($e);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to preview BCC relocation',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function relocateBcc(RelocateBccRequest $request, string $id): JsonResponse
    {
        try {
            if ($response = $this->parishionerMutationForbidden()) {
                return $response;
            }

            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $userId = Auth::id();
            $validated = $request->validated();

            $result = $this->bccRelocationService->transferFamily(
                $id,
                $validated['target_bcc_id'],
                $validated['effective_date'],
                $validated['transition_id'],
                $tenantId,
                $userId,
                $validated['historical_note'] ?? null,
                hash('sha256', json_encode($validated)),
            );

            $status = isset($result['idempotent_replay']) && $result['idempotent_replay'] ? 200 : 200;

            return response()->json([
                'success' => true,
                'message' => isset($result['idempotent_replay']) && $result['idempotent_replay']
                    ? 'Transition already completed'
                    : 'Family relocated to new BCC successfully',
                'data' => $result,
            ], $status);
        } catch (HouseholdTransitionException $e) {
            return $this->transitionErrorResponse($e);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to relocate family BCC',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function marriageTransition(MarriageTransitionRequest $request): JsonResponse
    {
        try {
            if ($response = $this->parishionerMutationForbidden()) {
                return $response;
            }

            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $userId = Auth::id();
            $validated = $request->validated();
            $validated['request_hash'] = hash('sha256', json_encode($validated));

            $result = $this->marriageHouseholdService->processTransition($validated, $tenantId, $userId);

            return response()->json([
                'success' => true,
                'message' => isset($result['idempotent_replay']) && $result['idempotent_replay']
                    ? 'Transition already completed'
                    : 'Marriage household transition completed successfully',
                'data' => $result,
            ]);
        } catch (HouseholdTransitionException $e) {
            return $this->transitionErrorResponse($e);
        } catch (\Exception $e) {
            if ($e instanceof Responsable) {
                return $e->toResponse($request);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to process marriage transition',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function transitionHistory(string $id): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $family = $this->familyService->getFamilyById($id, $tenantId);

            if (! $family) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family not found',
                ], 404);
            }

            $histories = $this->historyRecorder->forFamily($tenantId, $id);

            return response()->json([
                'success' => true,
                'data' => $histories,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve transition history',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function correctHistory(CorrectHouseholdHistoryRequest $request): JsonResponse
    {
        try {
            if ($response = $this->parishionerMutationForbidden()) {
                return $response;
            }

            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $userId = Auth::id();

            $record = $this->historyRecorder->correct($request->validated(), $tenantId, $userId);

            return response()->json([
                'success' => true,
                'message' => 'History correction recorded successfully',
                'data' => $record,
            ], 201);
        } catch (HouseholdTransitionException $e) {
            return $this->transitionErrorResponse($e);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to record history correction',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display a listing of families
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->effectiveTenantId();

            if (! $tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required',
                ], 403);
            }

            if ($response = $this->denyUnlessCanViewFamilies()) {
                return $response;
            }

            $filters = [
                'search' => $request->input('search'),
                'status' => $request->input('status'),
                'bcc_id' => $request->input('bcc_id'),
                'missing_sacrament' => $request->input('missing_sacrament'),
                'progression' => $request->input('progression'),
                'parish_zone_id' => $request->input('parish_zone_id'),
                'city' => $request->input('city'),
                'city_exact' => $request->input('city_exact'),
                'missing' => $request->input('missing'),
                'size_band' => $request->input('size_band'),
                'household' => $request->input('household'),
                'created_from' => $request->input('created_from'),
                'created_to' => $request->input('created_to'),
                'sort_by' => $request->input('sort_by', 'family_code'),
                'sort_order' => $request->input('sort_order', 'asc'),
            ];

            $user = Auth::user();
            if ($user && $this->parishionerAccessService->isParishioner($user)) {
                $ownFamilyId = $this->parishionerAccessService->activeFamilyIdForUser($user);
                if ($ownFamilyId === null) {
                    return response()->json([
                        'success' => true,
                        'data' => [],
                        'total' => 0,
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => (int) $request->input('per_page', 15),
                        'from' => null,
                        'to' => null,
                    ]);
                }

                $filters['family_id'] = $ownFamilyId;
            }

            $perPage = max(1, min(100, (int) $request->input('per_page', 15)));
            $families = $this->familyService->getPaginatedFamilies($tenantId, $filters, $perPage);

            return response()->json([
                'success' => true,
                'data' => $families->items(),
                'total' => $families->total(),
                'current_page' => $families->currentPage(),
                'last_page' => $families->lastPage(),
                'per_page' => $families->perPage(),
                'from' => $families->firstItem(),
                'to' => $families->lastItem(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve families',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created family
     */
    public function store(StoreFamilyRequest $request): JsonResponse
    {
        try {
            if ($response = $this->parishionerMutationForbidden()) {
                return $response;
            }

            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $userId = Auth::id();

            if (! $tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required',
                ], 403);
            }

            $family = $this->familyService->createFamily(
                $request->validated(),
                $tenantId,
                $userId
            );

            return response()->json([
                'success' => true,
                'message' => 'Family created successfully',
                'data' => $family,
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            if ($e instanceof Responsable) {
                return $e->toResponse($request);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to create family',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified family
     */
    public function show(string $id): JsonResponse
    {
        try {
            if ($response = $this->denyUnlessCanViewFamilies()) {
                return $response;
            }

            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

            if (! $tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required',
                ], 403);
            }

            $family = $this->familyService->getFamilyById($id, $tenantId);

            if (! $family) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family not found',
                ], 404);
            }

            $this->authorize('view', $family);

            return response()->json([
                'success' => true,
                'data' => $family,
            ]);

        } catch (AuthorizationException) {
            return response()->json([
                'success' => false,
                'message' => 'Family not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve family',
            ], 500);
        }
    }

    /**
     * Update the specified family
     */
    public function update(UpdateFamilyRequest $request, string $id): JsonResponse
    {
        try {
            if ($response = $this->parishionerMutationForbidden()) {
                return $response;
            }

            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $userId = Auth::id();

            if (! $tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required',
                ], 403);
            }

            $family = $this->familyService->getFamilyById($id, $tenantId);
            if (! $family) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family not found',
                ], 404);
            }

            $this->authorize('update', $family);

            $validated = $request->validated();

            $family = $this->familyService->updateFamily(
                $id,
                $validated,
                $tenantId,
                $userId
            );

            if (! $family) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Family updated successfully',
                'data' => $family,
            ]);

        } catch (\RuntimeException $e) {
            if ($e->getCode() === 409) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 409);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to update family',
                'error' => $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            if ($e instanceof Responsable) {
                return $e->toResponse($request);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to update family',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified family
     *
     * SECURITY: Only Tenant Admins can delete families
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $user = Auth::user();
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $userId = $user->id;

            $family = Family::query()
                ->where('tenant_id', $tenantId)
                ->find($id);

            if (! $family) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family not found',
                ], 404);
            }

            $this->authorize('delete', $family);

            $result = $this->familyService->deleteFamily(
                $id,
                $tenantId,
                $userId,
                (bool) request()->boolean('force_delete')
            );

            if (! $result) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family not found or does not belong to your tenant',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Family deleted successfully',
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete family',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get family statistics
     */
    public function statistics(): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

            if (! $tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required',
                ], 403);
            }

            $statistics = $this->familyService->getStatistics($tenantId);

            return response()->json([
                'success' => true,
                'data' => $statistics,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve statistics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function dashboard(FamilyDashboardRequest $request): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

            $user = Auth::user();
            if ($user && $this->parishionerAccessService->isParishioner($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parishioners cannot view the family dashboard.',
                ], 403);
            }

            if ($response = $this->denyUnlessCanViewFamilies()) {
                return $response;
            }

            $scope = $request->dashboardScope();
            $includeContributions = $scope === 'full'
                && (bool) ($user && $user->hasPermission('donations.view'));
            $includePastoral = $scope === 'full'
                && (bool) ($user && $user->hasPermission('sacraments.view'));

            $data = $this->familyDashboardService->summary(
                (int) $tenantId,
                [
                    'bcc_id' => $request->input('bcc_id'),
                    'status' => $request->input('status'),
                    'period' => $request->input('period', '12m'),
                    'from' => $request->input('from'),
                    'to' => $request->input('to'),
                    'refresh' => $request->boolean('refresh'),
                    'scope' => $scope,
                ],
                $includeContributions,
                $includePastoral,
            );

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve family dashboard',
            ], 500);
        }
    }

    /**
     * Get families by BCC
     */
    public function byBcc(string $bccId): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

            if (! $tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required',
                ], 403);
            }

            $families = $this->familyService->getFamiliesByBCC($bccId, $tenantId);

            return response()->json([
                'success' => true,
                'data' => $families,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve families',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get families without BCC assignment
     */
    public function withoutBcc(): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

            if (! $tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required',
                ], 403);
            }

            $families = $this->familyService->getFamiliesWithoutBCC($tenantId);

            return response()->json([
                'success' => true,
                'data' => $families,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve families',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ==================== FAMILY MEMBER OPERATIONS ====================

    /**
     * Get all members of a family
     */
    public function members(string $familyId): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

            if (! $tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required',
                ], 403);
            }

            $family = $this->familyService->getFamilyById($familyId, $tenantId);

            if (! $family) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family not found',
                ], 404);
            }

            $user = Auth::user();
            if ($user && ! $this->parishionerAccessService->canViewFamily($user, $family)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family not found',
                ], 404);
            }

            $members = $this->familyService->getFamilyMembers($familyId, $tenantId);

            if ($members === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $members,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve family members',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add a member to a family
     */
    public function addMember(StoreFamilyMemberRequest $request, string $familyId): JsonResponse
    {
        try {
            if ($response = $this->parishionerMutationForbidden()) {
                return $response;
            }

            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $userId = Auth::id();

            if (! $tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required',
                ], 403);
            }

            $member = $this->familyService->addMember(
                $familyId,
                $request->validated(),
                $tenantId,
                $userId
            );

            if (! $member) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Family member added successfully',
                'data' => $member,
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            if ($e instanceof Responsable) {
                return $e->toResponse($request);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to add family member',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a family member
     */
    public function updateMember(UpdateFamilyMemberRequest $request, string $familyId, string $memberId): JsonResponse
    {
        try {
            // Sanitize UUIDs from route parameters - remove any whitespace or extra characters
            $familyId = trim($familyId);
            $memberId = trim($memberId);

            // Extract UUID pattern (36 characters with hyphens) if there's any extra text
            if (preg_match('/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $familyId, $matches)) {
                $familyId = $matches[1];
            }
            if (preg_match('/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $memberId, $matches)) {
                $memberId = $matches[1];
            }

            // Validate UUIDs are properly formatted
            if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $familyId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid family ID format',
                ], 400);
            }

            if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $memberId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid member ID format',
                ], 400);
            }

            if ($response = $this->parishionerMutationForbidden()) {
                return $response;
            }

            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $userId = Auth::id();

            if (! $tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required',
                ], 403);
            }

            // Get validated data - this ensures only valid fields are updated
            $validatedData = $request->validated();

            // Convert empty strings to null for nullable fields only
            // Fields that are 'sometimes|required' should not be converted to null if empty
            $updateData = [];
            foreach ($validatedData as $key => $value) {
                // Only convert to null if the value is an empty string AND the field is nullable
                // Required fields should keep their values even if they happen to be empty strings
                if ($value === '' && $this->isNullableField($key, $request)) {
                    $updateData[$key] = null;
                } else {
                    $updateData[$key] = $value;
                }
            }

            $member = $this->familyService->updateMember(
                $familyId,
                $memberId,
                $updateData,
                $tenantId,
                $userId
            );

            if (! $member) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family member not found or does not belong to your tenant',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Family member updated successfully',
                'data' => $member,
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Family member not found',
            ], 404);
        } catch (\Exception $e) {
            if ($e instanceof Responsable) {
                return $e->toResponse($request);
            }

            \Log::error('Error updating family member', [
                'family_id' => $familyId,
                'member_id' => $memberId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update family member: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check if a field is nullable in the validation rules
     */
    private function isNullableField(string $field, Request $request): bool
    {
        // List of fields that are explicitly nullable in UpdateFamilyMemberRequest
        $nullableFields = [
            'middle_name',
            'date_of_birth',
            'gender',
            'marital_status',
            'phone',
            'email',
            'is_primary_contact',
            'baptism_date',
            'baptism_place',
            'first_communion_date',
            'first_communion_place',
            'confirmation_date',
            'confirmation_place',
            'marriage_date',
            'marriage_place',
            'marriage_spouse_name',
            'occupation',
            'education',
            'skills_talents',
            'notes',
            'status',
            'deceased_date',
        ];

        return in_array($field, $nullableFields);
    }

    /**
     * Delete a family member
     */
    public function deleteMember(string $familyId, string $memberId): JsonResponse
    {
        try {
            if ($response = $this->parishionerMutationForbidden()) {
                return $response;
            }

            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $userId = Auth::id();

            if (! $tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required',
                ], 403);
            }

            $result = $this->familyService->deleteMember($familyId, $memberId, $tenantId, $userId);

            if (! $result) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family member not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Family member deleted successfully',
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete family member',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function splitMember(SplitFamilyMemberRequest $request, string $familyId): JsonResponse
    {
        try {
            if ($response = $this->parishionerMutationForbidden()) {
                return $response;
            }

            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $userId = Auth::id();

            $validated = $request->validated();
            $result = $this->familySplitService->splitMember(
                $familyId,
                $validated['member_id'],
                $validated['new_family'],
                $validated['member_overrides'] ?? [],
                $tenantId,
                $userId
            );

            return response()->json([
                'success' => true,
                'message' => 'Family member split into new household successfully',
                'data' => $result,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            if ($e instanceof Responsable) {
                return $e->toResponse($request);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to split family member',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function mergeFamilies(MergeFamiliesRequest $request): JsonResponse
    {
        try {
            if ($response = $this->parishionerMutationForbidden()) {
                return $response;
            }

            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $userId = Auth::id();
            $validated = $request->validated();

            $result = $this->familyMergeService->mergeFamilies(
                $validated['source_family_id'],
                $validated['target_family_id'],
                $tenantId,
                $userId
            );

            return response()->json([
                'success' => true,
                'message' => 'Families merged successfully',
                'data' => $result,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to merge families',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function submitUpdateRequest(FamilyUpdateRequestRequest $request, string $id): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $userId = Auth::id();
            $user = Auth::user();

            if (! $user || ! $this->parishionerAccessService->isParishioner($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only parishioner accounts can submit update requests.',
                ], 403);
            }

            $family = $this->familyService->getFamilyById($id, $tenantId);
            if (! $family || ! $this->parishionerAccessService->canViewFamily($user, $family)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family not found',
                ], 404);
            }

            $this->familyService->submitUpdateRequest($id, $request->validated(), $tenantId, $userId);

            return response()->json([
                'success' => true,
                'message' => 'Update request submitted successfully',
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit update request',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload profile image for family head
     */
    public function uploadProfileImage(UploadFamilyProfileImageRequest $request, string $id): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $userId = Auth::id();

            if (! $tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required',
                ], 403);
            }

            $family = $this->familyService->uploadProfileImage(
                $id,
                $request->file('profile_image'),
                $tenantId,
                $userId
            );

            if (! $family) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Profile image uploaded successfully',
                'data' => $family,
            ]);

        } catch (ImageMediaException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->publicMessage(),
            ], 422);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload profile image',
            ], 500);
        }
    }

    /**
     * Delete profile image for family
     */
    public function deleteProfileImage(string $id): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $userId = Auth::id();

            if (! $tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required',
                ], 403);
            }

            $family = $this->familyService->deleteProfileImage($id, $tenantId, $userId);

            if (! $family) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Profile image deleted successfully',
                'data' => $family,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete profile image',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload profile image for family head
     */
    public function uploadHeadProfileImage(UploadFamilyHeadProfileImageRequest $request, string $id): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $userId = Auth::id();

            if (! $tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required',
                ], 403);
            }

            $family = $this->familyService->uploadHeadProfileImage(
                $id,
                $request->file('head_profile_image'),
                $tenantId,
                $userId
            );

            if (! $family) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Head profile image uploaded successfully',
                'data' => $family,
            ]);

        } catch (ImageMediaException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->publicMessage(),
            ], 422);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload head profile image',
            ], 500);
        }
    }

    /**
     * Delete profile image for family head
     */
    public function deleteHeadProfileImage(string $id): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $userId = Auth::id();

            if (! $tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required',
                ], 403);
            }

            $family = $this->familyService->deleteHeadProfileImage($id, $tenantId, $userId);

            if (! $family) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Head profile image deleted successfully',
                'data' => $family,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete head profile image',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Members module dashboard — parish-wide statistics and demographics.
     */
    public function memberDashboard(): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

            if (! $tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required',
                ], 403);
            }

            $user = Auth::user();
            if ($user && $this->parishionerAccessService->isParishioner($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parishioners cannot view the parish member directory.',
                ], 403);
            }

            if ($response = $this->denyUnlessCanViewFamilies()) {
                return $response;
            }

            $data = $this->memberDashboardService->summary((int) $tenantId);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve member dashboard',
            ], 500);
        }
    }

    /**
     * Birthdays and wedding anniversaries for the current parish week (Mon–Sun, tenant timezone).
     */
    public function memberCelebrations(): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

            if (! $tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required',
                ], 403);
            }

            $user = Auth::user();
            if ($user && $this->parishionerAccessService->isParishioner($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parishioners cannot view the parish member directory.',
                ], 403);
            }

            if ($response = $this->denyUnlessCanViewFamilies()) {
                return $response;
            }

            $data = $this->memberCelebrationsService->weekCelebrations((int) $tenantId);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve member celebrations',
            ], 500);
        }
    }

    /**
     * Paginated birthdays or wedding anniversaries for the parish week (Mon–Sun) or an explicit from/to range.
     */
    public function memberCelebrationsList(MemberCelebrationsListRequest $request): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

            if (! $tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required',
                ], 403);
            }

            $user = Auth::user();
            if ($user && $this->parishionerAccessService->isParishioner($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parishioners cannot view the parish member directory.',
                ], 403);
            }

            if ($response = $this->denyUnlessCanViewFamilies()) {
                return $response;
            }

            $validated = $request->validated();
            $perPage = max(1, min(100, (int) ($validated['per_page'] ?? 20)));
            $page = max(1, (int) ($validated['page'] ?? 1));

            $payload = $this->memberCelebrationsService->paginatedCelebrations(
                (int) $tenantId,
                (string) $validated['type'],
                [
                    'from' => $validated['from'] ?? null,
                    'to' => $validated['to'] ?? null,
                    'search' => $validated['search'] ?? null,
                    'bcc_id' => $validated['bcc_id'] ?? null,
                    'event_date' => $validated['event_date'] ?? null,
                    'event_date_from' => $validated['event_date_from'] ?? null,
                    'event_date_to' => $validated['event_date_to'] ?? null,
                    'sort_by' => $validated['sort_by'] ?? 'event_date',
                    'sort_order' => $validated['sort_order'] ?? 'asc',
                ],
                $page,
                $perPage
            );

            return response()->json([
                'success' => true,
                'window' => $payload['window'],
                'type' => $payload['type'],
                'data' => $payload['data'],
                'total' => $payload['total'],
                'current_page' => $payload['current_page'],
                'last_page' => $payload['last_page'],
                'per_page' => $payload['per_page'],
                'from' => $payload['from'],
                'to' => $payload['to'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve member celebrations',
            ], 500);
        }
    }

    /**
     * Get all members across all families for the current tenant
     */
    public function allMembers(Request $request): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

            if (! $tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required',
                ], 403);
            }

            $user = Auth::user();
            if ($user && $this->parishionerAccessService->isParishioner($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parishioners cannot view the parish member directory.',
                ], 403);
            }

            if ($response = $this->denyUnlessCanViewFamilies()) {
                return $response;
            }

            $filters = [
                'search' => $request->input('search'),
                'status' => $request->input('status'),
                'family_status' => $request->input('family_status'),
                'bcc_id' => $request->input('bcc_id'),
                'is_head' => $request->input('is_head'),
                'progression' => $request->input('progression'),
                'age_band' => $request->input('age_band'),
                'gender' => $request->input('gender'),
                'missing' => $request->input('missing'),
                'occupation' => $request->input('occupation'),
                'education' => $request->input('education'),
                'sort_by' => $request->input('sort_by', 'name'),
                'sort_order' => $request->input('sort_order', 'asc'),
            ];

            $perPage = max(1, min(100, (int) $request->input('per_page', 10))); // Clamp between 1 and 100
            $page = max(1, (int) $request->input('page', 1)); // Ensure page is at least 1
            $members = $this->familyService->getAllMembers($tenantId, $filters, $perPage, $page);

            return response()->json([
                'success' => true,
                'data' => $members->items(),
                'total' => $members->total(),
                'current_page' => $members->currentPage(),
                'last_page' => $members->lastPage(),
                'per_page' => $members->perPage(),
                'from' => $members->firstItem(),
                'to' => $members->lastItem(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve members',
            ], 500);
        }
    }
}
