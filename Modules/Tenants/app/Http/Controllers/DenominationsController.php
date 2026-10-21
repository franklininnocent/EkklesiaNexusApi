<?php

namespace Modules\Tenants\Http\Controllers;

use App\Support\ApiErrorResponse;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\Tenants\Models\Denomination;

/**
 * Denominations Controller
 * 
 * Provides lookup data for church denominations.
 * Read-only API for dropdown lists and selection.
 */
class DenominationsController extends Controller
{
    /**
     * Get all active denominations.
     * 
     * @route GET /api/denominations
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Denomination::active()->ordered();

            // Optional search filter
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            $denominations = $query->get();

            return response()->json([
                'success' => true,
                'data' => $denominations,
                'total' => $denominations->count(),
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching denominations: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error fetching denominations',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get a specific denomination by ID.
     * 
     * @route GET /api/denominations/{id}
     */
    public function show($id): JsonResponse
    {
        try {
            $denomination = Denomination::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $denomination,
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching denomination: ' . $e->getMessage(), [
                'denomination_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Denomination not found',
                'error' => config('app.debug') ? $e->getMessage() : 'Not found',
            ], 404);
        }
    }
}
