<?php

namespace App\Support;

/**
 * Passport stores access token ids in a fixed-width char column; always normalize before compare or lookup.
 */
final class OAuthAccessTokenId
{
    public static function normalize(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $normalized = trim($id);

        return $normalized === '' ? null : $normalized;
    }
}
