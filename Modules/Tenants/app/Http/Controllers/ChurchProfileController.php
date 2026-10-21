<?php

namespace Modules\Tenants\Http\Controllers;

use App\Support\ApiErrorResponse;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Authentication\Models\User;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Services\BishopFileUploadService;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Http\Requests\UploadPatronImageRequest;
use Modules\Tenants\Services\ChurchMediaImageService;
use Modules\Tenants\Services\ChurchStatusMetricsService;
use Modules\Tenants\Services\Media\ImageMediaException;

/**
 * Church Profile Controller
 * 
 * Manages the extended church profile information for tenants.
 * Allows tenant administrators to configure ecclesiastical details.
 */
class ChurchProfileController extends Controller
{
    public function __construct(
        private readonly ChurchMediaImageService $churchMediaImageService,
        private readonly ChurchStatusMetricsService $churchStatusMetricsService,
    ) {}
    /**
     * Get the church profile for the authenticated tenant user.
     * 
     * @route GET /api/church-profile
     */
    public function show(): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user || app(\Modules\Tenants\Support\TenantContext::class)->effectiveTenantId() === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with a tenant/church',
                ], 404);
            }

            // Get or create church profile
            $churchProfile = ChurchProfile::with([
                'denomination',
                'archdiocese.denomination',
                'bishop.archdiocese'
            ])->firstOrCreate(
                ['tenant_id' => app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId()],
                [
                    'founded_year' => null,
                    'country' => null,
                ]
            );

            // Generate full URL for patron image if exists
            $churchProfileData = $this->formatProfileData($churchProfile);

            return response()->json([
                'success' => true,
                'data' => $churchProfileData,
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching church profile: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error fetching church profile',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Authoritative Church Status metrics for the profile overview graph.
     *
     * @route GET /api/church-profile/status-metrics
     */
    public function statusMetrics(): JsonResponse
    {
        try {
            $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->effectiveTenantId();

            if ($tenantId === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with a tenant/church',
                ], 404);
            }

            $metrics = $this->churchStatusMetricsService->build((int) $tenantId);

            return response()->json([
                'success' => true,
                'data' => $metrics,
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching church status metrics: '.$e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error fetching church status metrics',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update the church profile for the authenticated tenant user.
     * Only tenant administrators can update.
     * 
     * @route PUT /api/church-profile
     */
    public function update(Request $request): JsonResponse
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
                    'message' => 'Unauthorized. Only church administrators can update church profile.',
                ], 403);
            }

            // Validation rules
            $validated = $request->validate([
                'denomination_id' => 'nullable|exists:denominations,id',
                'archdiocese_id' => 'nullable|exists:archdioceses,id',
                'bishop_id' => 'nullable|exists:bishops,id',
                'founded_year' => 'nullable|integer|min:1000|max:' . (date('Y') + 1),
                'country' => 'nullable|string|max:100',
                'phone' => 'nullable|string|max:20',
                'email' => 'nullable|email|max:255',
                'website' => 'nullable|url|max:255',
                'about' => 'nullable|string|max:5000',
                'vision' => 'nullable|string|max:2000',
                'mission' => 'nullable|string|max:2000',
                'core_values' => 'nullable|string|max:2000',
                'service_times' => 'nullable|string|max:1000',
                'patron_name' => 'nullable|string|max:255',
                'patron_image_path' => 'prohibited',
            ]);

            DB::beginTransaction();
            try {
                $churchProfile = ChurchProfile::updateOrCreate(
                    ['tenant_id' => app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId()],
                    $validated
                );

                DB::commit();

                // Reload relationships
                $churchProfile->load([
                    'denomination',
                    'archdiocese.denomination',
                    'bishop.archdiocese'
                ]);

                // Add patron_image_url to response
                $churchProfileData = $this->formatProfileData($churchProfile);

                Log::info('Church profile updated', [
                    'tenant_id' => app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId(),
                    'updated_by' => $user->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Church profile updated successfully',
                    'data' => $churchProfileData,
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
            Log::error('Error updating church profile: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error updating church profile',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Upload patron image for the church.
     * Tenant-specific: Each tenant can upload their own patron image.
     * 
     * @route POST /api/church-profile/upload-patron-image
     */
    public function uploadPatronImage(UploadPatronImageRequest $request): JsonResponse
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
                    'message' => 'Unauthorized. Only church administrators can upload patron image.',
                ], 403);
            }

            $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
            $churchProfile = ChurchProfile::firstOrCreate(
                ['tenant_id' => $tenantId],
                []
            );

            $this->churchMediaImageService->replacePatronImage(
                $request->file('image'),
                $churchProfile
            );

            $churchProfile->refresh();

            Log::info('Patron image uploaded', [
                'tenant_id' => $tenantId,
                'updated_by' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Patron image uploaded successfully',
                'data' => [
                    'patron_image_url' => $this->churchMediaImageService->patronImageUrl(
                        $churchProfile->patron_image_path,
                        $tenantId
                    ),
                ],
            ]);
        } catch (ImageMediaException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->publicMessage(),
            ], 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error uploading patron image: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error uploading patron image',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Delete patron image.
     * 
     * @route DELETE /api/church-profile/patron-image
     */
    public function deletePatronImage(): JsonResponse
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
                    'message' => 'Unauthorized. Only church administrators can delete patron image.',
                ], 403);
            }

            DB::beginTransaction();
            try {
                $churchProfile = ChurchProfile::where('tenant_id', app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId())->first();

                if ($churchProfile && $churchProfile->patron_image_path) {
                    $this->churchMediaImageService->deletePatronImage($churchProfile);
                }

                DB::commit();

                Log::info('Patron image deleted', [
                    'tenant_id' => app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId(),
                    'updated_by' => $user->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Patron image deleted successfully',
                ]);
            } catch (\Exception $e) {
                ApiErrorResponse::rethrowExpected($e);
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error deleting patron image: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error deleting patron image',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatProfileData(ChurchProfile $churchProfile): array
    {
        $churchProfile->loadMissing([
            'denomination',
            'archdiocese.denomination',
            'bishop.archdiocese',
        ]);

        $data = $churchProfile->toArray();
        unset($data['patron_image_path']);
        $data['patron_image_url'] = $this->churchMediaImageService->patronImageUrl(
            $churchProfile->patron_image_path,
            (int) $churchProfile->tenant_id
        );

        $bishop = $churchProfile->resolvePresidingBishop();
        $data['bishop'] = $bishop ? $this->formatBishopForResponse($bishop) : null;

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatBishopForResponse(BishopManagement $bishop): array
    {
        $title = $bishop->ecclesiasticalTitle?->title ?? '';
        $uploadService = app(BishopFileUploadService::class);

        return array_merge($bishop->toArray(), [
            'title' => $title,
            'full_title' => trim($title.' '.$bishop->full_name),
            'photo_public_url' => $uploadService->resolvePhotoUrl($bishop->photo_path, $bishop->photo_url),
            'has_photo' => $uploadService->hasPhoto($bishop->photo_path, $bishop->photo_url),
        ]);
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
