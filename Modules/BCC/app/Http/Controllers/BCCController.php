<?php

namespace Modules\BCC\Http\Controllers;

use App\Support\ApiErrorResponse;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\BCC\Exceptions\BccDomainException;
use Modules\BCC\Http\Requests\StoreBCCRequest;
use Modules\BCC\Http\Requests\UpdateBCCRequest;
use Modules\BCC\Http\Requests\StoreBCCLeaderRequest;
use Modules\BCC\Http\Requests\UpdateBCCLeaderRequest;
use Modules\BCC\Http\Requests\AssignFamiliesRequest;
use Modules\BCC\Models\BCC;
use Modules\BCC\Services\BCCService;

class BCCController extends Controller
{
    use AuthorizesRequests;

    /**
     * @var BCCService
     */
    protected BCCService $bccService;

    /**
     * BCCController constructor.
     *
     * @param BCCService $bccService
     */
    public function __construct(BCCService $bccService)
    {
        $this->bccService = $bccService;
    }

    /**
     * Display a listing of BCCs
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $this->authorize('viewAny', BCC::class);
            $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
            
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required'
                ], 403);
            }

            $filters = [
                'search' => $request->input('search'),
                'status' => $request->input('status'),
                'meeting_day' => $request->input('meeting_day'),
                'parish_zone_id' => $request->input('parish_zone_id'),
                'has_space' => $request->input('has_space'),
                'sort_by' => $request->input('sort_by', 'created_at'),
                'sort_order' => $request->input('sort_order', 'desc'),
            ];

            $perPage = $request->input('per_page', 15);
            $bccs = $this->bccService->getPaginatedBCCs($tenantId, $filters, $perPage);

            return response()->json([
                'success' => true,
                'data' => $bccs->items(),
                'total' => $bccs->total(),
                'current_page' => $bccs->currentPage(),
                'last_page' => $bccs->lastPage(),
                'per_page' => $bccs->perPage(),
                'from' => $bccs->firstItem(),
                'to' => $bccs->lastItem(),
            ]);

        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve BCCs',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created BCC
     *
     * @param StoreBCCRequest $request
     * @return JsonResponse
     */
    public function store(StoreBCCRequest $request): JsonResponse
    {
        $this->authorize('create', BCC::class);

        try {
            $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
            $userId = Auth::id();
            
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required'
                ], 403);
            }

            $bcc = $this->bccService->createBCC(
                $request->validated(),
                $tenantId,
                $userId
            );

            return response()->json([
                'success' => true,
                'message' => 'BCC created successfully',
                'data' => $bcc
            ], 201);

        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to create BCC',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified BCC
     *
     * @param string $id
     * @return JsonResponse
     */
    public function show(string $id): JsonResponse
    {
        try {
            $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
            
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required'
                ], 403);
            }

            $bcc = $this->bccService->getBCCById($id, $tenantId);

            if (!$bcc) {
                return response()->json([
                    'success' => false,
                    'message' => 'BCC not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $bcc
            ]);

        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve BCC',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified BCC
     *
     * @param UpdateBCCRequest $request
     * @param string $id
     * @return JsonResponse
     */
    public function update(UpdateBCCRequest $request, string $id): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();

        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant ID is required',
            ], 403);
        }

        $existing = $this->bccService->getBCCById($id, (string) $tenantId);
        if (!$existing) {
            return response()->json([
                'success' => false,
                'message' => 'BCC not found',
            ], 404);
        }

        $this->authorize('update', $existing);

        try {
            $userId = Auth::id();

            $bcc = $this->bccService->updateBCC(
                $id,
                $request->validated(),
                $tenantId,
                $userId
            );

            if (!$bcc) {
                return response()->json([
                    'success' => false,
                    'message' => 'BCC not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'BCC updated successfully',
                'data' => $bcc
            ]);

        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to update BCC',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified BCC
     * 
     * SECURITY: Only Tenant Admins can delete BCCs
     *
     * @param string $id
     * @return JsonResponse
     */
    public function destroy(string $id): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();

        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant ID is required',
            ], 403);
        }

        $existing = $this->bccService->getBCCById($id, (string) $tenantId);
        if (!$existing) {
            return response()->json([
                'success' => false,
                'message' => 'BCC not found or does not belong to your tenant',
            ], 404);
        }

        $this->authorize('delete', $existing);

        try {
            $userId = Auth::id();

            $result = $this->bccService->deleteBCC($id, $tenantId, $userId);

            if (!$result) {
                return response()->json([
                    'success' => false,
                    'message' => 'BCC not found or does not belong to your tenant'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'BCC deleted successfully'
            ]);

        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete BCC',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get BCC statistics
     *
     * @return JsonResponse
     */
    public function statistics(): JsonResponse
    {
        try {
            $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
            
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required'
                ], 403);
            }

            $statistics = $this->bccService->getStatistics($tenantId);

            return response()->json([
                'success' => true,
                'data' => $statistics
            ]);

        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get BCCs with available space
     *
     * @return JsonResponse
     */
    public function withSpace(): JsonResponse
    {
        try {
            $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
            
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required'
                ], 403);
            }

            $bccs = $this->bccService->getBCCsWithSpace($tenantId);

            return response()->json([
                'success' => true,
                'data' => $bccs
            ]);

        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve BCCs',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ==================== LEADER OPERATIONS ====================

    /**
     * Get all leaders for a BCC
     *
     * @param string $bccId
     * @return JsonResponse
     */
    public function leaders(string $bccId): JsonResponse
    {
        try {
            $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
            
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required'
                ], 403);
            }

            $leaders = $this->bccService->getLeaders($bccId, $tenantId);

            if ($leaders === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'BCC not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $leaders
            ]);

        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve leaders',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add a leader to a BCC
     *
     * @param StoreBCCLeaderRequest $request
     * @param string $bccId
     * @return JsonResponse
     */
    public function addLeader(StoreBCCLeaderRequest $request, string $bccId): JsonResponse
    {
        try {
            $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
            $userId = Auth::id();
            
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required'
                ], 403);
            }

            $leader = $this->bccService->addLeader(
                $bccId,
                $request->validated(),
                $tenantId,
                $userId
            );

            if (!$leader) {
                return response()->json([
                    'success' => false,
                    'message' => 'BCC not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Leader added successfully',
                'data' => $leader
            ], 201);

        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to add leader',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a BCC leader
     *
     * @param UpdateBCCLeaderRequest $request
     * @param string $bccId
     * @param string $leaderId
     * @return JsonResponse
     */
    public function updateLeader(UpdateBCCLeaderRequest $request, string $bccId, string $leaderId): JsonResponse
    {
        try {
            $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
            $userId = Auth::id();
            
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required'
                ], 403);
            }

            $leader = $this->bccService->updateLeader(
                $bccId,
                $leaderId,
                $request->validated(),
                $tenantId,
                $userId
            );

            if (!$leader) {
                return response()->json([
                    'success' => false,
                    'message' => 'Leader not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Leader updated successfully',
                'data' => $leader
            ]);

        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to update leader',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a BCC leader
     *
     * @param string $bccId
     * @param string $leaderId
     * @return JsonResponse
     */
    public function deleteLeader(string $bccId, string $leaderId): JsonResponse
    {
        try {
            $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
            $userId = Auth::id();
            
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required'
                ], 403);
            }

            $result = $this->bccService->deleteLeader($bccId, $leaderId, $tenantId, $userId);

            if (!$result) {
                return response()->json([
                    'success' => false,
                    'message' => 'Leader not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Leader deleted successfully'
            ]);

        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete leader',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ==================== FAMILY ASSIGNMENT ====================

    /**
     * Assign families to BCC
     *
     * @param AssignFamiliesRequest $request
     * @param string $bccId
     * @return JsonResponse
     */
    public function assignFamilies(AssignFamiliesRequest $request, string $bccId): JsonResponse
    {
        try {
            $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
            $userId = Auth::id();
            
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required'
                ], 403);
            }

            $result = $this->bccService->assignFamilies(
                $bccId,
                $request->validated()['family_ids'],
                $tenantId,
                $userId
            );

            if (!$result['success']) {
                return response()->json($result, 400);
            }

            return response()->json($result);

        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign families',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove families from BCC
     *
     * @param AssignFamiliesRequest $request
     * @return JsonResponse
     */
    public function removeFamilies(AssignFamiliesRequest $request): JsonResponse
    {
        try {
            $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
            $userId = Auth::id();
            
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required'
                ], 403);
            }

            $count = $this->bccService->removeFamilies(
                $request->validated()['family_ids'],
                $tenantId,
                $userId
            );

            return response()->json([
                'success' => true,
                'message' => "{$count} families removed from BCC",
                'removed_count' => $count
            ]);

        } catch (BccDomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors,
            ], $e->httpStatus);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove families',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
