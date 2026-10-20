<?php

namespace Modules\ApplicationAccess\Support;

use App\Support\OAuthAccessTokenId;

final class BearerTokenInspector
{
    public function extractAccessTokenId(?string $bearerToken): ?string
    {
        if ($bearerToken === null || $bearerToken === '') {
            return null;
        }

        $parts = explode('.', $bearerToken);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode($this->base64UrlDecode($parts[1]), true);
        if (! is_array($payload)) {
            return null;
        }

        $jti = $payload['jti'] ?? null;

        return is_string($jti) ? OAuthAccessTokenId::normalize($jti) : null;
    }

    private function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
