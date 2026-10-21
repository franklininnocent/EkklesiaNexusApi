<?php

namespace Modules\Tenants\Http\Controllers;

use App\Support\ApiErrorResponse;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Authentication\Models\User;
use Modules\Tenants\Http\Requests\UploadPopeImageRequest;
use Modules\Tenants\Models\PopeDetails;
use Modules\Tenants\Services\ChurchMediaImageService;
use Modules\Tenants\Services\Media\ImageMediaException;
use Modules\Tenants\Services\PopeLeadershipService;

/**
 * Pope Details Controller
 *
 * Manages global Pope image and details.
 * Viewing is available to any authenticated user.
 * Creating or changing the portrait and details is limited to Ekklesia roles.
 */
class PopeDetailsController extends Controller
{
    public function __construct(
        private readonly ChurchMediaImageService $churchMediaImageService,
    ) {}

    /**
     * Get global pope details.
     *
     * @route GET /api/church-profile/pope
     */
    public function show(): JsonResponse
    {
        try {
            $popeDetails = PopeDetails::getCurrent();

            if (! $popeDetails) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'pope_name' => null,
                        'pope_image_url' => null,
                        'pope_title' => null,
                        'pope_effective_from' => null,
                    ],
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'pope_name' => $popeDetails->pope_name,
                    'pope_image_url' => $this->churchMediaImageService->popeImageUrl($popeDetails->pope_image_path),
                    'pope_title' => $popeDetails->pope_title,
                    'pope_effective_from' => $popeDetails->pope_effective_from?->format('Y-m-d'),
                ],
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching pope details: '.$e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error fetching pope details',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update global pope details.
     *
     * @route PUT /api/church-profile/pope
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            if ($denied = $this->denyUnlessEkklesiaRole($user)) {
                return $denied;
            }

            $validated = $request->validate([
                'pope_name' => 'required|string|max:255',
                'pope_title' => 'nullable|string|max:100',
                'pope_effective_from' => 'nullable|date',
            ]);

            DB::beginTransaction();
            try {
                app(PopeLeadershipService::class)->succeed([
                    'pope_name' => $validated['pope_name'],
                    'pope_title' => $validated['pope_title'] ?? null,
                    'pope_effective_from' => $validated['pope_effective_from'] ?? null,
                ], (int) $user->id);

                $popeDetails = PopeDetails::getCurrent();

                DB::commit();

                Log::info('Pope details updated', [
                    'updated_by' => $user->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Pope details updated successfully',
                    'data' => [
                        'pope_name' => $popeDetails->pope_name,
                        'pope_image_url' => $this->churchMediaImageService->popeImageUrl($popeDetails->pope_image_path),
                        'pope_title' => $popeDetails->pope_title,
                        'pope_effective_from' => $popeDetails->pope_effective_from?->format('Y-m-d'),
                    ],
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
            Log::error('Error updating pope details: '.$e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error updating pope details',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Upload pope image.
     *
     * @route POST /api/church-profile/pope/upload-image
     */
    public function uploadImage(UploadPopeImageRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            if ($denied = $this->denyUnlessEkklesiaRole($user)) {
                return $denied;
            }

            $popeDetails = PopeDetails::getCurrent();

            if (! $popeDetails) {
                $popeDetails = new PopeDetails;
                $popeDetails->created_by = $user->id;
                $popeDetails->save();
            }

            $this->churchMediaImageService->replacePopeImage($request->file('image'), $popeDetails);
            $popeDetails->refresh();
            $popeDetails->updated_by = $user->id;
            $popeDetails->save();

            Log::info('Pope image uploaded', [
                'updated_by' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Pope image uploaded successfully',
                'data' => [
                    'pope_image_url' => $this->churchMediaImageService->popeImageUrl($popeDetails->pope_image_path),
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
            Log::error('Error uploading pope image: '.$e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error uploading pope image',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Delete pope image.
     *
     * @route DELETE /api/church-profile/pope/image
     */
    public function deleteImage(): JsonResponse
    {
        try {
            $user = auth()->user();

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            if ($denied = $this->denyUnlessEkklesiaRole($user)) {
                return $denied;
            }

            $popeDetails = PopeDetails::getCurrent();

            if ($popeDetails && $popeDetails->pope_image_path) {
                $this->churchMediaImageService->deletePopeImage($popeDetails);
                $popeDetails->updated_by = $user->id;
                $popeDetails->save();
            }

            Log::info('Pope image deleted', [
                'updated_by' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Pope image deleted successfully',
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error deleting pope image: '.$e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error deleting pope image',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    private function denyUnlessEkklesiaRole(mixed $user): ?JsonResponse
    {
        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        if (! $user->hasEkklesiaRole()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only Ekklesia roles can manage pope details.',
            ], 403);
        }

        return null;
    }
}
