<?php

namespace Modules\Tenants\Http\Controllers;

use App\Support\ApiErrorResponse;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\ChurchSocialMedia;

/**
 * Church Social Media Controller
 * 
 * Manages church social media accounts (Facebook, Twitter, Instagram, etc.).
 * Full CRUD operations for tenant administrators.
 */
class ChurchSocialMediaController extends Controller
{
    /**
     * Get all social media accounts for the tenant.
     * 
     * @route GET /api/church-social-media
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

            $query = ChurchSocialMedia::where('tenant_id', app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId());

            // Filter by platform
            if ($request->has('platform')) {
                $query->byPlatform($request->platform);
            }

            // Filter by active status
            if ($request->has('active')) {
                $query->where('active', $request->active);
            }

            // Filter primary accounts only
            if ($request->has('primary') && $request->primary) {
                $query->primary();
            }

            $socialMedia = $query->ordered()->get();

            return response()->json([
                'success' => true,
                'data' => $socialMedia,
                'total' => $socialMedia->count(),
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching church social media: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error fetching church social media',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get a specific social media account.
     * 
     * @route GET /api/church-social-media/{id}
     */
    public function show($id): JsonResponse
    {
        try {
            $user = auth()->user();
            
            $socialMedia = ChurchSocialMedia::where('tenant_id', app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId())
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $socialMedia,
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching social media account: ' . $e->getMessage(), [
                'social_media_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Social media account not found',
                'error' => config('app.debug') ? $e->getMessage() : 'Not found',
            ], 404);
        }
    }

    /**
     * Create a new social media account.
     * 
     * @route POST /api/church-social-media
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
                    'message' => 'Unauthorized. Only church administrators can manage social media.',
                ], 403);
            }

            // Validation
            $validated = $request->validate([
                'platform' => 'required|string|max:50|in:facebook,twitter,instagram,youtube,linkedin,tiktok,whatsapp',
                'url' => 'required|url|max:255',
                'username' => 'nullable|string|max:100',
                'follower_count' => 'nullable|integer|min:0',
                'is_primary' => 'nullable|boolean',
                'display_order' => 'nullable|integer|min:0',
                'active' => 'nullable|boolean',
            ]);

            $validated['tenant_id'] = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
            $validated['is_primary'] = $validated['is_primary'] ?? 0;
            $validated['active'] = $validated['active'] ?? 1;
            $validated['display_order'] = $validated['display_order'] ?? 0;

            // If this is being set as primary, unset other primary accounts for this platform
            if ($validated['is_primary']) {
                ChurchSocialMedia::where('tenant_id', app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId())
                    ->where('platform', $validated['platform'])
                    ->where('is_primary', 1)
                    ->update(['is_primary' => 0]);
            }

            DB::beginTransaction();
            try {
                $socialMedia = ChurchSocialMedia::create($validated);

                DB::commit();

                Log::info('Church social media account created', [
                    'social_media_id' => $socialMedia->id,
                    'tenant_id' => app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId(),
                    'platform' => $socialMedia->platform,
                    'created_by' => $user->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Social media account added successfully',
                    'data' => $socialMedia,
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
            Log::error('Error creating social media account: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error creating social media account',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update a social media account.
     * 
     * @route PUT /api/church-social-media/{id}
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
                    'message' => 'Unauthorized. Only church administrators can manage social media.',
                ], 403);
            }

            $socialMedia = ChurchSocialMedia::where('tenant_id', app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId())
                ->findOrFail($id);

            // Validation
            $validated = $request->validate([
                'platform' => 'sometimes|required|string|max:50|in:facebook,twitter,instagram,youtube,linkedin,tiktok,whatsapp',
                'url' => 'sometimes|required|url|max:255',
                'username' => 'nullable|string|max:100',
                'follower_count' => 'nullable|integer|min:0',
                'is_primary' => 'nullable|boolean',
                'display_order' => 'nullable|integer|min:0',
                'active' => 'nullable|boolean',
            ]);

            // If this is being set as primary, unset other primary accounts for this platform
            if (isset($validated['is_primary']) && $validated['is_primary']) {
                $platform = $validated['platform'] ?? $socialMedia->platform;
                ChurchSocialMedia::where('tenant_id', app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId())
                    ->where('platform', $platform)
                    ->where('is_primary', 1)
                    ->where('id', '!=', $id)
                    ->update(['is_primary' => 0]);
            }

            DB::beginTransaction();
            try {
                $socialMedia->update($validated);

                DB::commit();

                Log::info('Church social media account updated', [
                    'social_media_id' => $socialMedia->id,
                    'tenant_id' => app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId(),
                    'platform' => $socialMedia->platform,
                    'updated_by' => $user->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Social media account updated successfully',
                    'data' => $socialMedia,
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
            Log::error('Error updating social media account: ' . $e->getMessage(), [
                'social_media_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error updating social media account',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Delete a social media account.
     * 
     * @route DELETE /api/church-social-media/{id}
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
                    'message' => 'Unauthorized. Only church administrators can manage social media.',
                ], 403);
            }

            $socialMedia = ChurchSocialMedia::where('tenant_id', app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId())
                ->findOrFail($id);

            DB::beginTransaction();
            try {
                $platform = $socialMedia->platform;
                $socialMedia->delete();

                DB::commit();

                Log::info('Church social media account deleted', [
                    'social_media_id' => $id,
                    'tenant_id' => app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId(),
                    'platform' => $platform,
                    'deleted_by' => $user->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Social media account deleted successfully',
                ]);
            } catch (\Exception $e) {
                ApiErrorResponse::rethrowExpected($e);
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error deleting social media account: ' . $e->getMessage(), [
                'social_media_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error deleting social media account',
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
