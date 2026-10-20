<?php

namespace Modules\Marketing\Support\SeoMonitoring;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class MarketingSeoSafeHttpProbe
{
    public function __construct(
        private readonly MarketingSeoMonitoringConfig $config,
        private readonly MarketingSeoUrlPolicy $urlPolicy,
    ) {
    }

    /**
     * @return array{status: int, body: string, final_url: string, error: ?string, security_rejection: bool}
     */
    public function fetch(string $url, bool $leakProbe = false, int $maxBodyBytes = 0): array
    {
        if ($maxBodyBytes <= 0) {
            $maxBodyBytes = (int) config('marketing.seo-monitoring.max_body_bytes_html');
        }

        $maxRedirects = (int) config('marketing.seo-monitoring.max_redirects');
        $timeout = (int) config('marketing.seo-monitoring.request_timeout_seconds');
        $connectTimeout = (int) config('marketing.seo-monitoring.connect_timeout_seconds');
        $maxRetries = (int) config('marketing.seo-monitoring.max_retries');
        $retryDelayMs = (int) config('marketing.seo-monitoring.retry_delay_ms');

        $currentUrl = $url;
        $lastError = null;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                return $this->fetchOnce($currentUrl, $leakProbe, $maxBodyBytes, $maxRedirects, $timeout, $connectTimeout);
            } catch (InvalidArgumentException $e) {
                return [
                    'status' => 0,
                    'body' => '',
                    'final_url' => $currentUrl,
                    'error' => 'security_rejection',
                    'security_rejection' => true,
                ];
            } catch (ConnectionException|RequestException|\Throwable $e) {
                $lastError = $e;
                Log::debug('marketing.seo-monitoring.fetch_retry', [
                    'url' => $currentUrl,
                    'attempt' => $attempt,
                    'message' => $e->getMessage(),
                ]);
                if ($attempt < $maxRetries) {
                    usleep($retryDelayMs * 1000);
                }
            }
        }

        return [
            'status' => 0,
            'body' => '',
            'final_url' => $currentUrl,
            'error' => $lastError instanceof \Throwable ? $lastError->getMessage() : 'request_failed',
            'security_rejection' => false,
        ];
    }

    /**
     * @return array{status: int, body: string, final_url: string, error: ?string, security_rejection: bool}
     */
    private function fetchOnce(
        string $startUrl,
        bool $leakProbe,
        int $maxBodyBytes,
        int $maxRedirects,
        int $timeout,
        int $connectTimeout,
    ): array {
        $currentUrl = $startUrl;

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $this->urlPolicy->assertFetchAllowed($currentUrl, $leakProbe);

            $response = Http::withOptions([
                'allow_redirects' => false,
                'connect_timeout' => $connectTimeout,
                'timeout' => $timeout,
            ])
                ->withHeaders(['User-Agent' => $this->config->userAgent()])
                ->get($currentUrl);

            $status = $response->status();

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $location = $response->header('Location');
                if (! is_string($location) || trim($location) === '') {
                    return [
                        'status' => $status,
                        'body' => '',
                        'final_url' => $currentUrl,
                        'error' => 'invalid_redirect',
                        'security_rejection' => false,
                    ];
                }

                $next = $this->resolveRedirectUrl($currentUrl, trim($location));
                if (str_starts_with(strtolower($next), 'http://') && ! $leakProbe) {
                    throw new InvalidArgumentException('HTTPS downgrade redirect rejected.');
                }

                $currentUrl = $next;

                continue;
            }

            $body = $response->body();
            if (strlen($body) > $maxBodyBytes) {
                $body = substr($body, 0, $maxBodyBytes);
            }

            return [
                'status' => $status,
                'body' => $body,
                'final_url' => $currentUrl,
                'error' => null,
                'security_rejection' => false,
            ];
        }

        return [
            'status' => 0,
            'body' => '',
            'final_url' => $currentUrl,
            'error' => 'too_many_redirects',
            'security_rejection' => false,
        ];
    }

    private function resolveRedirectUrl(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        if (str_starts_with($location, '/')) {
            return $scheme.'://'.$host.$port.$location;
        }

        $path = $parts['path'] ?? '/';
        $dir = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/') + 1) : '/';

        return $scheme.'://'.$host.$port.$dir.$location;
    }
}
