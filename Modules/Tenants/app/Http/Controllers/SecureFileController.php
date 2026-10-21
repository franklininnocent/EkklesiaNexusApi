<?php

namespace Modules\Tenants\Http\Controllers;

use App\Support\ApiErrorResponse;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Tenants\Support\TenantContext;
use Modules\Tenants\Support\TenantScopedPublicStorage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @deprecated Use {@see MediaServeController} with encrypted media tokens instead.
 * Legacy public-disk signed path serve — retained only during dual-read cutover.
 */
class SecureFileController
{
    /**
     * Serve a protected file with signature verification
     *
     * This endpoint requires a valid signed URL to access tenant files.
     * The signature ensures the URL hasn't been tampered with and hasn't expired.
     */
    public function serveFile(Request $request): Response|StreamedResponse|JsonResponse
    {
        try {
            $rawPath = $request->query('path');

            if (! is_string($rawPath) || $rawPath === '') {
                Log::warning('Secure file access attempted without path');

                return response()->json([
                    'success' => false,
                    'message' => 'File path is required',
                ], 400);
            }

            $filePath = TenantScopedPublicStorage::normalizePath($rawPath);

            if ($filePath === null) {
                Log::warning('Secure file access attempted with disallowed path', [
                    'path' => $rawPath,
                    'user_id' => Auth::id(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized file access',
                ], 403);
            }

            if (! Storage::disk('public')->exists($filePath)) {
                Log::warning('Secure file access attempted for non-existent file', [
                    'path' => $filePath,
                    'user_id' => Auth::id(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'File not found',
                ], 404);
            }

            if ($denied = $this->denyCrossTenantFileAccess($filePath)) {
                return $denied;
            }

            $mimeType = Storage::disk('public')->mimeType($filePath);
            $size = Storage::disk('public')->size($filePath);
            $lastModified = Storage::disk('public')->lastModified($filePath);

            Log::info('Secure file accessed', [
                'path' => $filePath,
                'user_id' => Auth::id(),
                'mime_type' => $mimeType,
                'size' => $size,
            ]);

            return Storage::disk('public')->response($filePath, null, [
                'Content-Type' => $mimeType,
                'Content-Length' => $size,
                'Cache-Control' => 'private, max-age=3600',
                'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified).' GMT',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'DENY',
                'X-XSS-Protection' => '1; mode=block',
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error serving secure file', [
                'path' => $request->query('path'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error serving file',
            ], 500);
        }
    }

    /**
     * Generate a signed URL for a file
     */
    public function generateSignedUrl(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'path' => 'required|string',
                'expiration_minutes' => 'nullable|integer|min:1|max:1440',
            ]);

            $rawPath = $request->input('path');
            $expirationMinutes = $request->input('expiration_minutes', 60);

            $filePath = TenantScopedPublicStorage::normalizePath((string) $rawPath);

            if ($filePath === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized file access',
                ], 403);
            }

            if (! Storage::disk('public')->exists($filePath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'File not found',
                ], 404);
            }

            if ($denied = $this->denyCrossTenantFileAccess($filePath)) {
                return $denied;
            }

            $signedUrl = \URL::temporarySignedRoute(
                'api.tenants.files.serve',
                now()->addMinutes($expirationMinutes),
                ['path' => $filePath]
            );

            Log::info('Signed URL generated', [
                'path' => $filePath,
                'user_id' => Auth::id(),
                'expiration_minutes' => $expirationMinutes,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'signed_url' => $signedUrl,
                    'expires_at' => now()->addMinutes($expirationMinutes)->toIso8601String(),
                    'expiration_minutes' => $expirationMinutes,
                ],
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error generating signed URL', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error generating signed URL',
            ], 500);
        }
    }

    private function denyCrossTenantFileAccess(string $filePath): ?JsonResponse
    {
        if (TenantScopedPublicStorage::isGlobalPath($filePath)) {
            return null;
        }

        $fileTenantId = TenantScopedPublicStorage::extractTenantId($filePath);

        if ($fileTenantId === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized file access',
            ], 403);
        }

        if (! Auth::check()) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required',
            ], 401);
        }

        $user = Auth::user();
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

        if (! TenantScopedPublicStorage::userCanAccessPath($filePath, $user, $tenantId)) {
            Log::warning('Secure file access attempted for different tenant', [
                'user_id' => $user->id,
                'user_tenant_id' => $tenantId,
                'file_tenant_id' => $fileTenantId,
                'path' => $filePath,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unauthorized file access',
            ], 403);
        }

        return null;
    }
}
