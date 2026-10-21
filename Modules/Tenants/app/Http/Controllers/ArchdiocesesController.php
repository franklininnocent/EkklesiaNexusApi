<?php

namespace Modules\Tenants\Http\Controllers;

use App\Support\ApiErrorResponse;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\Tenants\Models\Archdiocese;

/**
 * Archdioceses Controller
 * 
 * Provides lookup data for archdioceses and dioceses.
 * Read-only API for dropdown lists and selection.
 */
class ArchdiocesesController extends Controller
{
    /**
     * Get all active archdioceses.
     * Supports filtering by country and denomination.
     * 
     * @route GET /api/archdioceses
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Archdiocese::with('denomination')->active();

            // Filter by country (legacy string field - for backward compatibility)
            if ($request->has('country') && !$request->has('country_id')) {
                $query->byCountry($request->country);
            }

            // Filter by country_id (preferred method)
            if ($request->has('country_id')) {
                $query->byCountryId($request->input('country_id'));
            }

            // Filter by state_id
            if ($request->has('state_id')) {
                $query->byStateId($request->input('state_id'));
            }

            // Filter by denomination
            // If filtering by denomination_id returns no results, include archdioceses with null denomination_id as fallback
            // This provides a better UX when archdioceses aren't properly linked to denominations in the database
            if ($request->has('denomination_id')) {
                $denominationId = $request->input('denomination_id');
                
                // Check if there are exact matches - build a separate query to avoid affecting main query
                $exactMatchesQuery = Archdiocese::active();
                
                // Apply same country filter if present
                if ($request->has('country_id')) {
                    $exactMatchesQuery->byCountryId($request->input('country_id'));
                } elseif ($request->has('country')) {
                    $exactMatchesQuery->byCountry($request->country);
                }
                
                // Apply same state filter if present
                if ($request->has('state_id')) {
                    $exactMatchesQuery->byStateId($request->input('state_id'));
                }
                
                $hasExactMatches = $exactMatchesQuery
                    ->where('denomination_id', $denominationId)
                    ->exists();
                
                // If no exact matches found, include archdioceses with null denomination_id as fallback
                if (!$hasExactMatches) {
                    $query->where(function ($q) use ($denominationId) {
                        $q->where('denomination_id', $denominationId)
                          ->orWhereNull('denomination_id');
                    });
                } else {
                    // Show only archdioceses with the exact denomination_id match
                    $query->where('denomination_id', $denominationId);
                }
            }

            // Optional search filter
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%")
                      ->orWhere('headquarters_city', 'like', "%{$search}%");
                });
            }

            $archdioceses = $query->orderBy('name')->get();

            return response()->json([
                'success' => true,
                'data' => $archdioceses,
                'total' => $archdioceses->count(),
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching archdioceses: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error fetching archdioceses',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get a specific archdiocese by ID.
     * 
     * @route GET /api/archdioceses/{id}
     */
    public function show($id): JsonResponse
    {
        try {
            $archdiocese = Archdiocese::with(['denomination', 'bishops'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $archdiocese,
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching archdiocese: ' . $e->getMessage(), [
                'archdiocese_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Archdiocese not found',
                'error' => config('app.debug') ? $e->getMessage() : 'Not found',
            ], 404);
        }
    }

    /**
     * Get list of countries with archdioceses.
     * 
     * @route GET /api/archdioceses/countries
     */
    public function countries(): JsonResponse
    {
        try {
            $countries = Archdiocese::active()
                ->select('country')
                ->distinct()
                ->orderBy('country')
                ->pluck('country');

            return response()->json([
                'success' => true,
                'data' => $countries,
                'total' => $countries->count(),
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching archdiocese countries: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error fetching countries',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}
