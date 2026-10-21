<?php

namespace Modules\EcclesiasticalData\Http\Controllers;

use App\Support\ApiErrorResponse;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Sacraments\Models\SacramentType;

/**
 * SacramentTypeController - Master Data Management for Ekklesia Roles
 *
 * This controller handles CRUD operations for Sacrament Types (master data).
 * Only Ekklesia roles (SuperAdmin, EkklesiaAdmin, EkklesiaManager) can access.
 *
 * Sacrament Types are system-wide reference data used by all tenants.
 */
class SacramentTypeController extends Controller
{
    /**
     * Get all sacrament types
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Verify user has Ekklesia role
            $user = $request->user();
            if (! $user->hasEkklesiaRole()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only Ekklesia roles can manage Sacrament Types.',
                ], 403);
            }

            $query = SacramentType::query();

            // Search
            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'ilike', "%{$search}%")
                        ->orWhere('description', 'ilike', "%{$search}%")
                        ->orWhere('code', 'ilike', "%{$search}%");
                });
            }

            // Filter by active status
            if ($request->filled('active')) {
                $query->where('active', $request->input('active'));
            }

            // Filter by category
            if ($request->filled('category')) {
                $query->where('category', $request->input('category'));
            }

            $allowedSorts = ['display_order', 'name', 'code', 'category', 'active', 'created_at'];
            $requestedSort = $request->input('sort_by', 'display_order');
            $requestedDir = $request->input('sort_dir', 'asc');
            $sortBy = is_string($requestedSort) && in_array($requestedSort, $allowedSorts, true)
                ? $requestedSort
                : 'display_order';
            $sortDir = is_string($requestedDir) && in_array(strtolower($requestedDir), ['asc', 'desc'], true)
                ? strtolower($requestedDir)
                : 'asc';
            $query->orderBy($sortBy, $sortDir);

            // Pagination
            $perPage = $request->input('per_page', 15);

            if ($perPage === 'all') {
                $types = $query->get();

                return response()->json([
                    'success' => true,
                    'data' => $types,
                    'total' => $types->count(),
                ]);
            }

            $types = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $types->items(),
                'current_page' => $types->currentPage(),
                'per_page' => $types->perPage(),
                'total' => $types->total(),
                'last_page' => $types->lastPage(),
            ]);

        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching sacrament types', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve sacrament types',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred',
            ], 500);
        }
    }

    /**
     * Get single sacrament type
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            // Verify user has Ekklesia role
            $user = $request->user();
            if (! $user->hasEkklesiaRole()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only Ekklesia roles can manage Sacrament Types.',
                ], 403);
            }

            $type = SacramentType::find($id);

            if (! $type) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sacrament Type not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $type,
            ]);

        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching sacrament type', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve sacrament type',
            ], 500);
        }
    }

    /**
     * Create new sacrament type
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Verify user has Ekklesia role
            $user = $request->user();
            if (! $user->hasEkklesiaRole()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only Ekklesia roles can create Sacrament Types.',
                ], 403);
            }

            $validated = $request->validate([
                'name' => 'required|string|max:100|unique:sacrament_types,name',
                'code' => 'required|string|max:50|unique:sacrament_types,code',
                'description' => 'nullable|string',
                'category' => 'required|in:initiation,healing,service,other',
                'theological_significance' => 'nullable|string',
                'requires_minister' => 'boolean',
                'minister_type' => 'nullable|string|max:100',
                'repeatable' => 'boolean',
                'min_age_years' => 'nullable|integer|min:0',
                'typical_age_years' => 'nullable|integer|min:0',
                'display_order' => 'nullable|integer|min:1',
                'active' => 'boolean',
            ]);

            // Set defaults
            $validated['requires_minister'] = $validated['requires_minister'] ?? true;
            $validated['repeatable'] = $validated['repeatable'] ?? false;
            $validated['active'] = $validated['active'] ?? true;
            $validated['display_order'] = $validated['display_order'] ?? (SacramentType::max('display_order') + 1);

            $type = SacramentType::create($validated);

            Log::info('Sacrament Type created', [
                'type_id' => $type->id,
                'created_by' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Sacrament Type created successfully',
                'data' => $type,
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error creating sacrament type', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create sacrament type',
            ], 500);
        }
    }

    /**
     * Update sacrament type
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            // Verify user has Ekklesia role
            $user = $request->user();
            if (! $user->hasEkklesiaRole()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only Ekklesia roles can update Sacrament Types.',
                ], 403);
            }

            $type = SacramentType::find($id);

            if (! $type) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sacrament Type not found',
                ], 404);
            }

            $validated = $request->validate([
                'name' => 'sometimes|string|max:100|unique:sacrament_types,name,'.$id,
                'code' => 'sometimes|string|max:50|unique:sacrament_types,code,'.$id,
                'description' => 'nullable|string',
                'category' => 'sometimes|in:initiation,healing,service,other',
                'theological_significance' => 'nullable|string',
                'requires_minister' => 'sometimes|boolean',
                'minister_type' => 'nullable|string|max:100',
                'repeatable' => 'sometimes|boolean',
                'min_age_years' => 'nullable|integer|min:0',
                'typical_age_years' => 'nullable|integer|min:0',
                'display_order' => 'nullable|integer|min:1',
                'active' => 'sometimes|boolean',
            ]);

            $type->update($validated);

            Log::info('Sacrament Type updated', [
                'type_id' => $type->id,
                'updated_by' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Sacrament Type updated successfully',
                'data' => $type,
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error updating sacrament type', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update sacrament type',
            ], 500);
        }
    }

    /**
     * Delete sacrament type
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            // Verify user has Ekklesia role
            $user = $request->user();
            if (! $user->hasEkklesiaRole()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only Ekklesia roles can delete Sacrament Types.',
                ], 403);
            }

            $type = SacramentType::find($id);

            if (! $type) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sacrament Type not found',
                ], 404);
            }

            // Check if type is being used
            $usageCount = $type->sacraments()->count();
            if ($usageCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot delete Sacrament Type. It is being used by {$usageCount} sacrament record(s).",
                ], 422);
            }

            $type->delete();

            Log::info('Sacrament Type deleted', [
                'type_id' => $id,
                'deleted_by' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Sacrament Type deleted successfully',
            ]);

        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error deleting sacrament type', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete sacrament type',
            ], 500);
        }
    }

    /**
     * Get sacrament type statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            // Verify user has Ekklesia role
            $user = $request->user();
            if (! $user->hasEkklesiaRole()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized.',
                ], 403);
            }

            $total = SacramentType::count();
            $active = SacramentType::where('active', true)->count();
            $inactive = SacramentType::where('active', false)->count();

            $byCategory = SacramentType::selectRaw('category, COUNT(*) as count')
                ->groupBy('category')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'total' => $total,
                    'active' => $active,
                    'inactive' => $inactive,
                    'by_category' => $byCategory,
                ],
            ]);

        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching sacrament type statistics', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve statistics',
            ], 500);
        }
    }
}
