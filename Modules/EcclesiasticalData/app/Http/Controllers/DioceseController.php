<?php

namespace Modules\EcclesiasticalData\Http\Controllers;

use App\Support\ApiErrorResponse;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\EcclesiasticalData\Http\Requests\StoreDioceseRequest;
use Modules\EcclesiasticalData\Http\Requests\UpdateDioceseRequest;
use Modules\EcclesiasticalData\Services\DioceseService;
use Modules\Tenants\Support\AuditLogViewerAuthorization;

class DioceseController extends Controller
{
    protected DioceseService $service;

    public function __construct(DioceseService $service)
    {
        $this->service = $service;
    }

    /**
     * Display a paginated listing of dioceses using cursor pagination
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $params = $request->only([
                'search',
                'country_id',
                'denomination_id',
                'is_active',
                'sort_by',
                'sort_dir',
                'per_page',
                'page'
            ]);

            $paginator = $this->service->getPaginated($params);

            // Format relationships for each diocese
            $data = $paginator->getCollection()->map(function ($diocese) {
                $array = $diocese->toArray();
                
                // Handle country: Use relationship if available, otherwise fallback to legacy string field
                $countryRelation = null;
                if ($diocese->country_id) {
                    if (!$diocese->relationLoaded('country')) {
                        $diocese->load('country:id,name,iso2');
                    }
                    $countryRelation = $diocese->getRelation('country');
                }
                
                if ($countryRelation && is_object($countryRelation) && isset($countryRelation->id)) {
                    $array['country'] = [
                        'id' => $countryRelation->id,
                        'name' => $countryRelation->name,
                        'iso2' => $countryRelation->iso2 ?? null,
                    ];
                } else {
                    $legacyCountry = $diocese->getAttribute('country') ?? $array['country'] ?? null;
                    if ($legacyCountry && is_string($legacyCountry) && !empty(trim($legacyCountry))) {
                        $array['country'] = ['name' => trim($legacyCountry)];
                    } else {
                        $array['country'] = null;
                    }
                }
                
                // Handle state: Use relationship if available, otherwise fallback to legacy region field
                $stateRelation = null;
                if ($diocese->state_id) {
                    if (!$diocese->relationLoaded('state')) {
                        $diocese->load('state:id,name,state_code');
                    }
                    $stateRelation = $diocese->getRelation('state');
                }
                
                if ($stateRelation && is_object($stateRelation) && isset($stateRelation->id)) {
                    $array['state'] = [
                        'id' => $stateRelation->id,
                        'name' => $stateRelation->name,
                        'state_code' => $stateRelation->state_code ?? null,
                    ];
                } else {
                    $legacyRegion = $diocese->getAttribute('region') ?? $array['region'] ?? null;
                    if ($legacyRegion && is_string($legacyRegion) && !empty(trim($legacyRegion))) {
                        $array['state'] = ['name' => trim($legacyRegion)];
                    } else {
                        $array['state'] = null;
                    }
                }
                
                // Handle denomination: Use relationship if available
                $denominationRelation = null;
                if ($diocese->denomination_id) {
                    if (!$diocese->relationLoaded('denomination')) {
                        $diocese->load('denomination:id,name');
                    }
                    $denominationRelation = $diocese->getRelation('denomination');
                }
                
                if ($denominationRelation && is_object($denominationRelation) && isset($denominationRelation->id)) {
                    $array['denomination'] = [
                        'id' => $denominationRelation->id,
                        'name' => $denominationRelation->name,
                    ];
                } else {
                    $array['denomination'] = null;
                }
                
                return $array;
            });

            return response()->json([
                'success' => true,
                'data' => $data->values()->all(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
                'message' => 'Dioceses retrieved successfully'
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve dioceses',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created diocese
     */
    public function store(StoreDioceseRequest $request): JsonResponse
    {
        try {
            $diocese = $this->service->create($request->validated());

            return response()->json([
                'success' => true,
                'data' => $diocese,
                'message' => 'Diocese created successfully'
            ], 201);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to create diocese',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified diocese
     */
    public function show(string $id): JsonResponse
    {
        try {
            $diocese = $this->service->getById($id);

            return response()->json([
                'success' => true,
                'data' => $diocese,
                'message' => 'Diocese retrieved successfully'
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            return response()->json([
                'success' => false,
                'message' => 'Diocese not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update the specified diocese
     */
    public function update(UpdateDioceseRequest $request, string $id): JsonResponse
    {
        try {
            $diocese = $this->service->update($id, $request->validated());

            return response()->json([
                'success' => true,
                'data' => $diocese,
                'message' => 'Diocese updated successfully'
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to update diocese',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified diocese
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $this->service->delete($id);

            return response()->json([
                'success' => true,
                'message' => 'Diocese deleted successfully'
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete diocese',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get dioceses by country
     */
    public function byCountry(Request $request, int $countryId): JsonResponse
    {
        try {
            $dioceses = $this->service->getByCountry($countryId);

            return response()->json([
                'success' => true,
                'data' => $dioceses,
                'message' => 'Dioceses retrieved successfully'
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve dioceses',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get archdioceses only
     */
    public function archdioceses(): JsonResponse
    {
        try {
            $archdioceses = $this->service->getArchdioceses();

            return response()->json([
                'success' => true,
                'data' => $archdioceses,
                'message' => 'Archdioceses retrieved successfully'
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve archdioceses',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get diocese statistics
     */
    public function statistics(): JsonResponse
    {
        try {
            $stats = $this->service->getStatistics();

            return response()->json([
                'success' => true,
                'data' => $stats,
                'message' => 'Statistics retrieved successfully'
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
     * Get audit history for a diocese
     */
    public function auditHistory(Request $request, string $id): JsonResponse
    {
        if (! AuditLogViewerAuthorization::canViewPlatformCompleteAudit($request->user())) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Platform audit access is restricted to SuperAdmin.',
            ], 403);
        }

        try {
            $diocese = $this->service->getById($id);
            $history = $diocese->auditHistory();

            return response()->json([
                'success' => true,
                'data' => $history,
                'message' => 'Audit history retrieved successfully'
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve audit history',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

