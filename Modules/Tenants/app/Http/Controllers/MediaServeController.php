<?php

namespace Modules\Tenants\Http\Controllers;

use App\Support\ApiErrorResponse;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Tenants\Services\Media\ImageMediaException;
use Modules\Tenants\Services\Media\ImageMediaPaths;
use Modules\Tenants\Services\Media\ImageMediaStorageResolver;
use Modules\Tenants\Services\Media\ImageMediaToken;
use Modules\Tenants\Support\TenantContext;
use Modules\Tenants\Support\TenantScopedPublicStorage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaServeController
{
    public function __construct(
        private readonly ImageMediaStorageResolver $storageResolver = new ImageMediaStorageResolver,
    ) {}

    public function serve(Request $request): BinaryFileResponse|StreamedResponse|JsonResponse
    {
        try {
            $rawToken = $request->query('token');
            if (! is_string($rawToken) || $rawToken === '') {
                return $this->deny('Token is required.', 400);
            }

            $payload = ImageMediaToken::decode($rawToken);

            if ($payload['e'] < time()) {
                return $this->deny('The image link has expired.', 403);
            }

            $variant = $payload['v'];
            $tenantId = $payload['t'];
            $displayKey = $payload['k'];

            if (! ImageMediaPaths::tenantOwnsKey($displayKey, $tenantId)) {
                return $this->deny('Unauthorized file access.', 403);
            }

            if ($denied = $this->denyCrossTenantAccess($request, $displayKey, $tenantId)) {
                return $denied;
            }

            $resolved = $this->storageResolver->resolveForVariant($displayKey, $variant);
            if ($resolved === null) {
                return $this->deny('File not found.', 404);
            }

            $disk = Storage::disk($resolved['disk']);
            $path = $resolved['path'];
            $checksum = hash('sha256', (string) $disk->get($path));
            $etag = '"'.substr($checksum, 0, 32).'"';

            if ($request->headers->get('If-None-Match') === $etag) {
                return response('', 304, [
                    'ETag' => $etag,
                    'Cache-Control' => 'private, max-age='.(int) config('tenants.media.cache_max_age_seconds', 300).', stale-while-revalidate=60',
                ]);
            }

            Log::info('Image media served', [
                'tenant_id' => $tenantId,
                'variant' => $variant,
                'user_id' => Auth::id(),
            ]);

            $headers = [
                'Content-Type' => 'image/webp',
                'Content-Disposition' => 'inline; filename="photo.webp"',
                'Cache-Control' => 'private, max-age='.(int) config('tenants.media.cache_max_age_seconds', 300).', stale-while-revalidate=60',
                'ETag' => $etag,
                'Referrer-Policy' => 'no-referrer',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'DENY',
            ];

            return $disk->response($path, 'photo.webp', $headers);
        } catch (ImageMediaException $e) {
            return $this->deny($e->publicMessage(), 403);
        } catch (\Throwable $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Image media serve failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return $this->deny('Error serving file.', 500);
        }
    }

    private function denyCrossTenantAccess(Request $request, string $displayKey, int $tokenTenantId): ?JsonResponse
    {
        if (str_starts_with($displayKey, 'platform/')) {
            return null;
        }

        // Signed URLs are capability tokens; authorization is enforced when the URL is issued.
        // <img> requests cannot send bearer tokens, and authenticated platform users may lack
        // an effective tenant context while browsing cross-tenant admin screens.
        if ($request->hasValidSignature()) {
            return null;
        }

        if (! Auth::check()) {
            return $this->deny('Authentication required.', 401);
        }

        $effectiveTenantId = app(TenantContext::class)->effectiveTenantId();
        if ($effectiveTenantId === null || (int) $effectiveTenantId !== $tokenTenantId) {
            Log::warning('Image media cross-tenant serve denied', [
                'user_id' => Auth::id(),
                'token_tenant_id' => $tokenTenantId,
                'effective_tenant_id' => $effectiveTenantId,
            ]);

            return $this->deny('Unauthorized file access.', 403);
        }

        if (! TenantScopedPublicStorage::userCanAccessPath($displayKey, Auth::user(), $tokenTenantId)) {
            return $this->deny('Unauthorized file access.', 403);
        }

        return null;
    }

    private function deny(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
