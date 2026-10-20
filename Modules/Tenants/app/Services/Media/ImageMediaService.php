<?php

namespace Modules\Tenants\Services\Media;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Tenants\Contracts\TenantLimitGuard;
use Modules\Tenants\Support\TenantPrivateStorage;
use Modules\Tenants\Support\Usage\StorageUsageProvider;
use RuntimeException;

class ImageMediaService
{
    public function __construct(
        private readonly ImageMediaPolicy $policy = new ImageMediaPolicy,
    ) {}

    private function assertWithinStorageLimitMb(int $tenantId, int $addingMb): void
    {
        if ($tenantId <= 0 || ! app()->bound(TenantLimitGuard::class)) {
            return;
        }

        try {
            app(TenantLimitGuard::class)->assertTenantCanAdd($tenantId, 'STORAGE_MB', $addingMb);
        } catch (RuntimeException $e) {
            if ($e instanceof Responsable) {
                throw new ImageMediaException(
                    ImageMediaException::CODE_STORAGE_LIMIT,
                    'Your parish has reached its storage limit. Remove unused files or contact EkklesiaNexus to upgrade.'
                );
            }
            throw $e;
        }
    }

    public function store(UploadedFile $file, int $tenantId, string $category): ImageMediaStoreResult
    {
        if ($tenantId <= 0 && ! in_array($category, ImageMediaPolicy::PLATFORM_CATEGORIES, true)) {
            throw new ImageMediaException(
                ImageMediaException::CODE_INVALID_PATH,
                'The image could not be stored.'
            );
        }

        $this->policy->assertPreUpload($file, $category);

        $realPath = $file->getRealPath();
        if ($realPath === false) {
            throw new ImageMediaException(
                ImageMediaException::CODE_INVALID_IMAGE,
                'The selected file is not a valid image.'
            );
        }

        $detectedMime = $this->detectMime($realPath);
        if ($detectedMime === null || ! in_array($detectedMime, $this->policy->allowedMimes(), true)) {
            throw new ImageMediaException(
                ImageMediaException::CODE_UNSUPPORTED,
                'The selected image format is not supported.'
            );
        }

        $imageInfo = @getimagesize($realPath);
        if ($imageInfo === false) {
            throw new ImageMediaException(
                ImageMediaException::CODE_INVALID_IMAGE,
                'The selected file is not a valid image.'
            );
        }

        [$width, $height] = $imageInfo;
        $this->policy->assertDimensions($width, $height);

        $image = @imagecreatefromstring((string) file_get_contents($realPath));
        if ($image === false) {
            throw new ImageMediaException(
                ImageMediaException::CODE_INVALID_IMAGE,
                'The selected file is not a valid image.'
            );
        }

        try {
            $image = $this->applyExifOrientation($image, $realPath, $detectedMime);
            [$displayBytes, $width, $height] = $this->encodeVariant($image, $this->policy->displayMaxEdgePx());
            [$thumbBytes] = $this->encodeVariant($image, $this->policy->thumbMaxEdgePx());
        } finally {
            imagedestroy($image);
        }

        $checksum = hash('sha256', $displayBytes);
        $uuid = Str::uuid()->toString();
        $displayKey = ImageMediaPaths::displayKey($tenantId, $category, $uuid);
        $thumbKey = ImageMediaPaths::thumbKey($tenantId, $category, $uuid);
        $addingMb = max(1, (int) ceil(strlen($displayBytes) / 1048576));

        $persist = function () use ($displayKey, $displayBytes, $thumbKey, $thumbBytes): void {
            if (! TenantPrivateStorage::put($displayKey, $displayBytes) || ! TenantPrivateStorage::put($thumbKey, $thumbBytes)) {
                $this->deleteQuietly($displayKey);
                $this->deleteQuietly($thumbKey);

                throw new ImageMediaException(
                    ImageMediaException::CODE_STORAGE,
                    'The image could not be stored.'
                );
            }
        };

        if ($tenantId > 0 && app()->bound(TenantLimitGuard::class)) {
            DB::transaction(function () use ($tenantId, $addingMb, $persist): void {
                DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first(['id']);
                StorageUsageProvider::forget($tenantId);
                $this->assertWithinStorageLimitMb($tenantId, $addingMb);
                $persist();
                StorageUsageProvider::forget($tenantId);
            });
        } else {
            $persist();
        }

        Log::info('Image media stored', [
            'tenant_id' => $tenantId,
            'category' => $category,
            'checksum_prefix' => substr($checksum, 0, 12),
            'uploaded_by' => auth()->id(),
        ]);

        return new ImageMediaStoreResult(
            storageKey: $displayKey,
            thumbStorageKey: $thumbKey,
            checksum: $checksum,
            width: $width,
            height: $height,
            byteSize: strlen($displayBytes),
        );
    }

    /**
     * @param  callable(string): void  $commitNewKey  Must persist the new storage key inside a lockForUpdate transaction.
     */
    public function replace(
        UploadedFile $file,
        int $tenantId,
        string $category,
        ?string $previousStorageKey,
        callable $commitNewKey,
    ): ImageMediaStoreResult {
        $result = $this->store($file, $tenantId, $category);

        try {
            $commitNewKey($result->storageKey);
        } catch (\Throwable $e) {
            $this->deletePair($result->storageKey, $tenantId);
            throw $e;
        }

        if ($previousStorageKey !== null && $previousStorageKey !== $result->storageKey) {
            $this->deletePair($previousStorageKey, $tenantId);
        }

        return $result;
    }

    public function deletePair(?string $storageKey, int $tenantId): void
    {
        if ($storageKey === null || $storageKey === '') {
            return;
        }

        if (! ImageMediaPaths::tenantOwnsKey($storageKey, $tenantId)) {
            Log::warning('Image media delete denied for tenant mismatch', [
                'tenant_id' => $tenantId,
                'user_id' => auth()->id(),
            ]);

            return;
        }

        $thumbKey = ImageMediaPaths::thumbKeyForDisplayKey($storageKey);
        $this->deleteQuietly($storageKey);
        $this->deleteQuietly($thumbKey);
        if ($tenantId > 0) {
            StorageUsageProvider::forget($tenantId);
        }
    }

    public function checksumForKey(string $storageKey): ?string
    {
        if (! TenantPrivateStorage::exists($storageKey)) {
            return null;
        }

        return hash('sha256', TenantPrivateStorage::get($storageKey));
    }

    public function shouldSkipReplace(string $newChecksum, ?string $existingStorageKey): bool
    {
        if ($existingStorageKey === null) {
            return false;
        }

        $existingChecksum = $this->checksumForKey($existingStorageKey);

        return $existingChecksum !== null && hash_equals($existingChecksum, $newChecksum);
    }

    private function detectMime(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return is_string($mime) ? strtolower($mime) : null;
    }

    /**
     * @param  \GdImage|resource  $image
     * @return \GdImage|resource
     */
    private function applyExifOrientation($image, string $realPath, string $detectedMime)
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        if (! in_array($detectedMime, ['image/jpeg', 'image/jpg'], true)) {
            return $image;
        }

        $exif = @exif_read_data($realPath);
        if (! is_array($exif) || ! isset($exif['Orientation'])) {
            return $image;
        }

        return match ((int) $exif['Orientation']) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
    }

    /**
     * @param  \GdImage|resource  $source
     * @return array{0: string, 1: int, 2: int}
     */
    private function encodeVariant($source, int $maxEdge): array
    {
        $width = imagesx($source);
        $height = imagesy($source);

        if ($width <= $maxEdge && $height <= $maxEdge) {
            $canvas = $source;
            $outWidth = $width;
            $outHeight = $height;
        } else {
            $ratio = min($maxEdge / $width, $maxEdge / $height);
            $outWidth = max(1, (int) round($width * $ratio));
            $outHeight = max(1, (int) round($height * $ratio));
            $canvas = imagecreatetruecolor($outWidth, $outHeight);
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagecopyresampled(
                $canvas,
                $source,
                0,
                0,
                0,
                0,
                $outWidth,
                $outHeight,
                $width,
                $height
            );
        }

        ob_start();
        imagewebp($canvas, null, $this->policy->webpQuality());
        $bytes = (string) ob_get_clean();

        if ($canvas !== $source) {
            imagedestroy($canvas);
        }

        if ($bytes === '') {
            throw new ImageMediaException(
                ImageMediaException::CODE_PROCESSING,
                'The image could not be processed.'
            );
        }

        return [$bytes, $outWidth, $outHeight];
    }

    private function deleteQuietly(string $storageKey): void
    {
        $normalized = TenantPrivateStorage::normalize($storageKey);
        if ($normalized === null) {
            return;
        }

        TenantPrivateStorage::disk()->delete($normalized);
    }
}
