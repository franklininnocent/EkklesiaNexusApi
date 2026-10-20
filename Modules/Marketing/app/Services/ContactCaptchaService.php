<?php

namespace Modules\Marketing\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class ContactCaptchaService
{
    public const CACHE_PREFIX = 'web.contact_captcha.';

    public function issue(): array
    {
        $ttl = $this->ttlSeconds();
        $answer = $this->randomChallenge();
        $id = (string) Str::uuid();

        Cache::put($this->cacheKey($id), [
            'hash' => $this->hashAnswer($answer),
            'attempts' => 0,
            'expires_at' => $this->nowTimestamp() + $ttl,
        ], $ttl);

        return [
            'challenge_id' => $id,
            'expires_in' => $ttl,
            'image_svg' => $this->renderSvg($answer),
        ];
    }

    public function verify(string $challengeId, string $response): bool
    {
        $key = $this->cacheKey($challengeId);
        $lock = Cache::lock('lock:'.$key, 5);
        if (! $lock->get()) {
            return false;
        }

        try {
            $payload = Cache::get($key);

            if (! is_array($payload) || empty($payload['hash'])) {
                return false;
            }

            $expiresAt = (int) ($payload['expires_at'] ?? 0);
            if ($expiresAt > 0 && $expiresAt <= $this->nowTimestamp()) {
                Cache::forget($key);

                return false;
            }

            $attempts = (int) ($payload['attempts'] ?? 0);
            $maxAttempts = (int) config('marketing.web.captcha.max_attempts', 5);

            if ($attempts >= $maxAttempts) {
                Cache::forget($key);

                return false;
            }

            $normalized = $this->normalize($response);
            $ok = hash_equals((string) $payload['hash'], $this->hashAnswer($normalized));

            if ($ok) {
                Cache::forget($key);

                return true;
            }

            $payload['attempts'] = $attempts + 1;
            $remaining = $this->remainingTtlSeconds($payload);
            Cache::put($key, $payload, $remaining);

            if ($payload['attempts'] >= $maxAttempts) {
                Cache::forget($key);
            }

            return false;
        } finally {
            $lock->release();
        }
    }

    public function invalidate(string $challengeId): void
    {
        Cache::forget($this->cacheKey($challengeId));
    }

    /**
     * Store a known challenge in tests without brute-forcing the hash.
     */
    public function seedForTesting(string $challengeId, string $answer, int $ttl = 300): void
    {
        $allowed = app()->runningUnitTests()
            || config('app.env') === 'testing'
            || filter_var(env('MARKETING_CAPTCHA_TEST_SEEDING', false), FILTER_VALIDATE_BOOLEAN);

        if (! $allowed) {
            throw new RuntimeException('Cannot seed CAPTCHA outside testing.');
        }

        Cache::put($this->cacheKey($challengeId), [
            'hash' => $this->hashAnswer($this->normalize($answer)),
            'attempts' => 0,
            'expires_at' => $this->nowTimestamp() + $ttl,
        ], $ttl);
    }

    public function ttlSeconds(): int
    {
        $fromSettings = 0;
        try {
            $fromSettings = (int) app(WebSettingsService::class)->current()->captcha_ttl_seconds;
        } catch (\Throwable) {
            $fromSettings = 0;
        }

        $ttl = $fromSettings > 0 ? $fromSettings : (int) config('marketing.web.captcha.ttl_seconds', 300);

        return max(60, min(900, $ttl));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function remainingTtlSeconds(array $payload): int
    {
        $expiresAt = (int) ($payload['expires_at'] ?? 0);
        if ($expiresAt > 0) {
            return max(1, $expiresAt - $this->nowTimestamp());
        }

        return $this->ttlSeconds();
    }

    private function nowTimestamp(): int
    {
        return now()->getTimestamp();
    }

    private function cacheKey(string $id): string
    {
        return self::CACHE_PREFIX.$id;
    }

    private function randomChallenge(): string
    {
        $charset = (string) config('marketing.web.captcha.charset', 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789');
        $length = (int) config('marketing.web.captcha.length', 5);
        $out = '';
        $max = strlen($charset) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= $charset[random_int(0, $max)];
        }

        return $out;
    }

    private function normalize(string $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($value)) ?? '');
    }

    private function hashAnswer(string $answer): string
    {
        return hash_hmac('sha256', $this->normalize($answer), (string) config('app.key'));
    }

    private function renderSvg(string $answer): string
    {
        $chars = str_split($answer);
        $width = 220;
        $height = 64;
        $glyphs = '';
        $startX = 22;
        foreach ($chars as $i => $char) {
            $x = $startX + ($i * 38);
            $y = 42 + random_int(-3, 3);
            $rot = random_int(-8, 8);
            $safe = htmlspecialchars($char, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $glyphs .= '<text x="'.$x.'" y="'.$y.'" transform="rotate('.$rot.' '.$x.' '.$y.')" fill="#0f172a" font-size="28" font-family="ui-sans-serif, Segoe UI, Arial, sans-serif" font-weight="700">'.$safe.'</text>';
        }

        $noise = '';
        for ($i = 0; $i < 6; $i++) {
            $x1 = random_int(8, 40);
            $y1 = random_int(8, 56);
            $x2 = random_int(180, 212);
            $y2 = random_int(8, 56);
            $noise .= '<line x1="'.$x1.'" y1="'.$y1.'" x2="'.$x2.'" y2="'.$y2.'" stroke="#94a3b8" stroke-width="1" opacity="0.4"/>';
        }
        for ($i = 0; $i < 18; $i++) {
            $cx = random_int(12, 208);
            $cy = random_int(10, 54);
            $noise .= '<circle cx="'.$cx.'" cy="'.$cy.'" r="1" fill="#64748b" opacity="0.35"/>';
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="'.$height.'" viewBox="0 0 '.$width.' '.$height.'" role="img" aria-hidden="true">'
            .'<rect width="100%" height="100%" rx="10" fill="#f8fafc" stroke="#cbd5e1"/>'
            .$noise
            .$glyphs
            .'</svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
