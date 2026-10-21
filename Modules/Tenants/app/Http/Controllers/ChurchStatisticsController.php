<?php

namespace Modules\Tenants\Http\Controllers;

use App\Support\ApiErrorResponse;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\ChurchStatistic;

/**
 * Church Statistics Controller
 * 
 * Manages church statistics (membership, attendance, sacraments, finances).
 * Full CRUD operations for tenant administrators.
 * Supports both monthly and annual statistics.
 */
class ChurchStatisticsController extends Controller
{
    /**
     * Get all church statistics for the tenant.
     * 
     * @route GET /api/church-statistics
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user || app(\Modules\Tenants\Support\TenantContext::class)->effectiveTenantId() === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with a tenant/church',
                ], 404);
            }

            $query = ChurchStatistic::where('tenant_id', app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId());

            // Filter by year
            if ($request->has('year')) {
                $query->forYear($request->year);
            }

            // Filter by month
            if ($request->has('month') && $request->has('year')) {
                $query->forMonth($request->year, $request->month);
            }

            // Filter annual only
            if ($request->has('annual') && $request->annual) {
                $query->annual();
            }

            // Filter monthly only
            if ($request->has('monthly') && $request->monthly) {
                $query->monthly();
            }

            // Limit results
            $limit = $request->get('limit', 50);
            $statistics = $query->latest()->limit($limit)->get();

            return response()->json([
                'success' => true,
                'data' => $statistics,
                'total' => $statistics->count(),
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching church statistics: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error fetching church statistics',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get a specific statistic record.
     * 
     * @route GET /api/church-statistics/{id}
     */
    public function show($id): JsonResponse
    {
        try {
            $user = auth()->user();
            
            $statistic = ChurchStatistic::where('tenant_id', app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId())
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $statistic,
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching church statistic: ' . $e->getMessage(), [
                'statistic_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Church statistic not found',
                'error' => config('app.debug') ? $e->getMessage() : 'Not found',
            ], 404);
        }
    }

    /**
     * Create a new statistic record.
     * 
     * @route POST /api/church-statistics
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user || app(\Modules\Tenants\Support\TenantContext::class)->effectiveTenantId() === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with a tenant/church',
                ], 404);
            }

            if (!$this->canManageChurchSettings($user, 'create')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only church administrators can manage statistics.',
                ], 403);
            }

            // Validation
            $validated = $request->validate([
                'year' => 'required|integer|min:1900|max:' . (date('Y') + 1),
                'month' => 'nullable|integer|min:1|max:12',
                'membership_count' => 'nullable|integer|min:0',
                'weekly_attendance' => 'nullable|integer|min:0',
                'baptisms' => 'nullable|integer|min:0',
                'confirmations' => 'nullable|integer|min:0',
                'marriages' => 'nullable|integer|min:0',
                'funerals' => 'nullable|integer|min:0',
                'tithes_offerings' => 'nullable|numeric|min:0',
                'notes' => 'nullable|string|max:2000',
            ]);

            $validated['tenant_id'] = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();

            // Check if record already exists for this period
            $existing = ChurchStatistic::where('tenant_id', app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId())
                ->where('year', $validated['year'])
                ->where('month', $validated['month'] ?? null)
                ->first();

            if ($existing) {
                return response()->json([
                    'success' => false,
                    'message' => 'Statistics already exist for this period. Please update the existing record.',
                ], 422);
            }

            DB::beginTransaction();
            try {
                $statistic = ChurchStatistic::create($validated);

                DB::commit();

                Log::info('Church statistic created', [
                    'statistic_id' => $statistic->id,
                    'tenant_id' => app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId(),
                    'period' => $statistic->period,
                    'created_by' => $user->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Church statistic added successfully',
                    'data' => $statistic,
                ], 201);
            } catch (\Exception $e) {
                ApiErrorResponse::rethrowExpected($e);
                DB::rollBack();
                throw $e;
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error creating church statistic: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error creating church statistic',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update a statistic record.
     * 
     * @route PUT /api/church-statistics/{id}
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user || app(\Modules\Tenants\Support\TenantContext::class)->effectiveTenantId() === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with a tenant/church',
                ], 404);
            }

            if (!$this->canManageChurchSettings($user, 'edit')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only church administrators can manage statistics.',
                ], 403);
            }

            $statistic = ChurchStatistic::where('tenant_id', app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId())
                ->findOrFail($id);

            // Validation
            $validated = $request->validate([
                'year' => 'sometimes|required|integer|min:1900|max:' . (date('Y') + 1),
                'month' => 'nullable|integer|min:1|max:12',
                'membership_count' => 'nullable|integer|min:0',
                'weekly_attendance' => 'nullable|integer|min:0',
                'baptisms' => 'nullable|integer|min:0',
                'confirmations' => 'nullable|integer|min:0',
                'marriages' => 'nullable|integer|min:0',
                'funerals' => 'nullable|integer|min:0',
                'tithes_offerings' => 'nullable|numeric|min:0',
                'notes' => 'nullable|string|max:2000',
            ]);

            // If year/month changed, check for duplicates
            if (isset($validated['year']) || isset($validated['month'])) {
                $year = $validated['year'] ?? $statistic->year;
                $month = $validated['month'] ?? $statistic->month;

                $existing = ChurchStatistic::where('tenant_id', app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId())
                    ->where('year', $year)
                    ->where('month', $month)
                    ->where('id', '!=', $id)
                    ->first();

                if ($existing) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Statistics already exist for this period.',
                    ], 422);
                }
            }

            DB::beginTransaction();
            try {
                $statistic->update($validated);

                DB::commit();

                Log::info('Church statistic updated', [
                    'statistic_id' => $statistic->id,
                    'tenant_id' => app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId(),
                    'period' => $statistic->period,
                    'updated_by' => $user->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Church statistic updated successfully',
                    'data' => $statistic,
                ]);
            } catch (\Exception $e) {
                ApiErrorResponse::rethrowExpected($e);
                DB::rollBack();
                throw $e;
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error updating church statistic: ' . $e->getMessage(), [
                'statistic_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error updating church statistic',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Delete a statistic record.
     * 
     * @route DELETE /api/church-statistics/{id}
     */
    public function destroy($id): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user || app(\Modules\Tenants\Support\TenantContext::class)->effectiveTenantId() === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with a tenant/church',
                ], 404);
            }

            if (!$this->canManageChurchSettings($user, 'delete')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only church administrators can manage statistics.',
                ], 403);
            }

            $statistic = ChurchStatistic::where('tenant_id', app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId())
                ->findOrFail($id);

            DB::beginTransaction();
            try {
                $period = $statistic->period;
                $statistic->delete();

                DB::commit();

                Log::info('Church statistic deleted', [
                    'statistic_id' => $id,
                    'tenant_id' => app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId(),
                    'period' => $period,
                    'deleted_by' => $user->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Church statistic deleted successfully',
                ]);
            } catch (\Exception $e) {
                ApiErrorResponse::rethrowExpected($e);
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error deleting church statistic: ' . $e->getMessage(), [
                'statistic_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error deleting church statistic',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
    private function canManageChurchSettings(User $user, string $action): bool
    {
        if ($user->isTenantAdmin() || $user->is_primary_admin) {
            return true;
        }

        $actionPermission = "church.settings.{$action}";
        return $user->hasPermission($actionPermission) || $user->hasPermission('church.settings.edit');
    }
}
