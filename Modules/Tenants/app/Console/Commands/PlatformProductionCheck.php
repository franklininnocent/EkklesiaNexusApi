<?php

namespace Modules\Tenants\Console\Commands;

use App\Support\CorsAllowedOrigins;
use Illuminate\Console\Command;
use Modules\Tenants\Support\TenantRlsManager;

class PlatformProductionCheck extends Command
{
    protected $signature = 'platform:production-check {--strict : Fail on warnings as well as errors}';

    protected $description = 'Validate production security and operability configuration.';

    public function handle(): int
    {
        $strict = (bool) $this->option('strict');
        $checks = [
            $this->check('APP_ENV is production', fn () => config('app.env') === 'production', true),
            $this->check('APP_DEBUG is false', fn () => config('app.debug') === false, true),
            $this->check('MARKETING_BFF_SECRET is set', fn () => (string) config('marketing.bff.shared_secret', '') !== '', $strict),
            $this->check('APP_KEY is set', fn () => (string) config('app.key') !== '', true),
            $this->check('TENANT_ORM_GLOBAL_SCOPE enabled', fn () => (bool) config('tenants.isolation.orm_global_scope', false), true),
            $this->check('TENANT_RLS_ENABLED on PostgreSQL', function (): bool {
                return TenantRlsManager::isEnabled();
            }, TenantRlsManager::driverIsPgsql()),
            $this->check('PostgreSQL wave-1 RLS policies present', function (): bool {
                return TenantRlsManager::waveOnePoliciesPresent();
            }, TenantRlsManager::driverIsPgsql()),
            $this->check('TENANT_SUBSCRIPTION_VIEW_ONLY_LOCKED_ENFORCEMENT is true', fn () => (bool) config('tenants.subscription.view_only_locked_enforcement', false), true),
            $this->check('CORS origins exclude localhost', function (): bool {
                if (! $this->hostsArePublic([env('FRONTEND_URL'), env('MARKETING_URL')])) {
                    return false;
                }

                $origins = CorsAllowedOrigins::resolve(
                    (string) config('app.env'),
                    env('FRONTEND_URL'),
                    env('MARKETING_URL'),
                );

                return $this->hostsArePublic($origins);
            }, true),
            $this->check('CORS allows Idempotency-Key header', function (): bool {
                $headers = config('cors.allowed_headers', []);

                return is_array($headers) && in_array('Idempotency-Key', $headers, true);
            }, true),
            $this->check('Queue driver is not sync', fn () => config('queue.default') !== 'sync', $strict),
            $this->check('Cache store is redis', fn () => config('cache.default') === 'redis', $strict),
            $this->check('Platform health token configured', fn () => (string) config('tenants.platform.health.token', '') !== '', $strict),
            $this->check('Session secure cookie in production', fn () => (bool) config('session.secure', false), $strict),
            $this->check('Export cleanup scheduled', fn () => (bool) config('tenants.platform.scheduler.export_cleanup_enabled', true), false),
        ];

        $rows = [];
        $failed = 0;
        $warned = 0;

        foreach ($checks as $check) {
            $rows[] = [$check['label'], $check['status']];

            if ($check['status'] === 'FAIL') {
                $failed++;
            }

            if ($check['status'] === 'WARN') {
                $warned++;
            }
        }

        $this->table(['Check', 'Result'], $rows);

        if ($failed > 0 || ($strict && $warned > 0)) {
            $this->error('Production check failed.');

            return self::FAILURE;
        }

        $this->info('Production check passed.');

        return self::SUCCESS;
    }

    /**
     * @return array{label: string, status: string}
     */
    private function check(string $label, callable $callback, bool $strict): array
    {
        $passed = (bool) $callback();

        if ($passed) {
            return ['label' => $label, 'status' => 'PASS'];
        }

        return ['label' => $label, 'status' => $strict ? 'FAIL' : 'WARN'];
    }

    /**
     * @param  iterable<int, mixed>  $urls
     */
    private function hostsArePublic(iterable $urls): bool
    {
        foreach ($urls as $url) {
            if (! is_string($url) || trim($url) === '') {
                continue;
            }

            $host = parse_url($url, PHP_URL_HOST);
            if (in_array($host, ['localhost', '127.0.0.1'], true)) {
                return false;
            }
        }

        return true;
    }
}
