<?php

namespace Modules\Tenants\Http\Controllers;

use App\Support\ApiErrorResponse;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Models\State;

/**
 * Geography Controller
 * 
 * Provides API endpoints for geographic data used in cascading dropdowns.
 * Implements caching for better performance.
 */
class GeographyController extends Controller
{
    /**
     * Get all active countries
     * 
     * GET /api/geography/countries
     * 
     * @return JsonResponse
     */
    public function getCountries(): JsonResponse
    {
        try {
            $countries = Cache::get('countries:active');

            if ($countries === null) {
                $countries = $this->queryActiveCountries();
                if ($countries->isNotEmpty()) {
                    Cache::put('countries:active', $countries, 86400);
                }
            } elseif ($countries->isEmpty() && Country::active()->exists()) {
                // Self-heal stale empty cache from before geographic data was seeded.
                Cache::forget('countries:active');
                $countries = $this->queryActiveCountries();
                Cache::put('countries:active', $countries, 86400);
            }

            return response()->json([
                'success' => true,
                'data' => $countries,
                'count' => $countries->count(),
                'message' => 'Countries retrieved successfully'
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching countries: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve countries',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get states/provinces by country ID
     * 
     * GET /api/geography/countries/{countryId}/states
     * 
     * @param int $countryId
     * @return JsonResponse
     */
    public function getStatesByCountry(int $countryId): JsonResponse
    {
        try {
            // Validate country exists
            $country = Country::find($countryId);
            
            if (!$country) {
                return response()->json([
                    'success' => false,
                    'message' => 'Country not found'
                ], 404);
            }

            // Cache states for each country for 24 hours
            $states = Cache::remember("states:country:{$countryId}", 86400, function () use ($countryId) {
                return State::forCountry($countryId)
                    ->active()
                    ->ordered()
                    ->get(['id', 'country_id', 'name', 'state_code', 'type']);
            });

            return response()->json([
                'success' => true,
                'data' => $states,
                'count' => $states->count(),
                'country' => [
                    'id' => $country->id,
                    'name' => $country->name,
                    'iso2' => $country->iso2
                ],
                'message' => 'States retrieved successfully'
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error("Error fetching states for country {$countryId}: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve states',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Search countries by name
     * 
     * GET /api/geography/countries/search?q=search_term
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function searchCountries(Request $request): JsonResponse
    {
        try {
            $searchTerm = $request->query('q', '');
            
            if (empty($searchTerm) || strlen($searchTerm) < 2) {
                return response()->json([
                    'success' => false,
                    'message' => 'Search term must be at least 2 characters'
                ], 400);
            }

            $countries = Country::active()
                ->where(function ($query) use ($searchTerm) {
                    $query->where('name', 'ILIKE', "%{$searchTerm}%")
                          ->orWhere('iso2', 'ILIKE', "%{$searchTerm}%")
                          ->orWhere('iso3', 'ILIKE', "%{$searchTerm}%");
                })
                ->ordered()
                ->limit(50)
                ->get(['id', 'name', 'iso2', 'iso3', 'emoji']);

            return response()->json([
                'success' => true,
                'data' => $countries,
                'count' => $countries->count(),
                'message' => 'Search completed successfully'
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error searching countries: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Search failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Search states by name within a country
     * 
     * GET /api/geography/countries/{countryId}/states/search?q=search_term
     * 
     * @param int $countryId
     * @param Request $request
     * @return JsonResponse
     */
    public function searchStates(int $countryId, Request $request): JsonResponse
    {
        try {
            $searchTerm = $request->query('q', '');
            
            if (empty($searchTerm) || strlen($searchTerm) < 2) {
                return response()->json([
                    'success' => false,
                    'message' => 'Search term must be at least 2 characters'
                ], 400);
            }

            $states = State::forCountry($countryId)
                ->active()
                ->where(function ($query) use ($searchTerm) {
                    $query->where('name', 'ILIKE', "%{$searchTerm}%")
                          ->orWhere('state_code', 'ILIKE', "%{$searchTerm}%");
                })
                ->ordered()
                ->limit(100)
                ->get(['id', 'country_id', 'name', 'state_code', 'type']);

            return response()->json([
                'success' => true,
                'data' => $states,
                'count' => $states->count(),
                'message' => 'Search completed successfully'
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error("Error searching states for country {$countryId}: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Search failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Clear geography cache
     * 
     * POST /api/geography/clear-cache
     * 
     * @return JsonResponse
     */
    private function queryActiveCountries()
    {
        return Country::active()
            ->ordered()
            ->get(['id', 'name', 'iso2', 'iso3', 'phone_code', 'emoji']);
    }

    public function clearCache(): JsonResponse
    {
        try {
            Cache::forget('countries:active');
            
            // Clear all state caches (this is a simple approach)
            $countries = Country::all();
            foreach ($countries as $country) {
                Cache::forget("states:country:{$country->id}");
            }

            return response()->json([
                'success' => true,
                'message' => 'Geography cache cleared successfully'
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error clearing geography cache: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear cache',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}


