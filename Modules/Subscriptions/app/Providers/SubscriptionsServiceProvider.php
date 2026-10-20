<?php

namespace Modules\Subscriptions\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Modules\Subscriptions\Console\Commands\AlignLaunchPlanFeatures;
use Modules\Subscriptions\Console\Commands\RebuildLaunchPlanCatalog;
use Modules\Subscriptions\Console\Commands\ApplyScheduledBillingMaintenance;
use Modules\Subscriptions\Console\Commands\ReleaseExpiredBillingReservations;
use Modules\Subscriptions\Console\Commands\RunBillingReconciliation;
use Modules\Subscriptions\Console\Commands\ApplyScheduledSubscriptionChanges;
use Modules\Subscriptions\Console\Commands\BackfillTenantSubscriptions;
use Modules\Subscriptions\Console\Commands\SendUsageThresholdAlerts;
use Modules\Subscriptions\Console\Commands\SnapshotTenantUsage;
use Modules\Subscriptions\Console\Commands\VerifyEntitlementParity;
use Modules\Subscriptions\Http\Middleware\EnsureEntitlement;
use Modules\Subscriptions\Http\Middleware\EnsureSubscriptionsPlatformPermission;
use Modules\Subscriptions\Http\Middleware\EnsureSuperAdminEntitlementOverride;
use Modules\Subscriptions\Http\Middleware\EnsureWithinLimit;
use Modules\Subscriptions\Services\Entitlements\EntitlementCatalog;
use Modules\Subscriptions\Services\Entitlements\EntitlementResolver;
use Modules\Subscriptions\Services\Entitlements\LegacyEntitlementSource;
use Modules\Subscriptions\Services\Entitlements\SubscriptionEntitlementGate;
use Modules\Subscriptions\Services\LimitEnforcementService;
use Modules\Subscriptions\Services\SubscriptionPlanChangeService;
use Modules\Tenants\Contracts\TenantEntitlementGate;
use Modules\Tenants\Contracts\TenantLimitGuard;
use Modules\Tenants\Contracts\TenantPlanAssigner;
use Nwidart\Modules\Traits\PathNamespace;

class SubscriptionsServiceProvider extends ServiceProvider
{
    use PathNamespace;

    protected string $name = 'Subscriptions';

    protected string $nameLower = 'subscriptions';

    public function register(): void
    {
        $this->mergeConfigFrom(module_path($this->name, 'config/config.php'), $this->nameLower);

        // Request-scoped: memoised entitlements never leak between requests / queue jobs.
        $this->app->scoped(EntitlementCatalog::class);
        $this->app->scoped(LegacyEntitlementSource::class);
        $this->app->scoped(EntitlementResolver::class);
        $this->app->scoped(SubscriptionEntitlementGate::class);
        $this->app->scoped(TenantEntitlementGate::class, static fn ($app) => $app->make(SubscriptionEntitlementGate::class));
        $this->app->bind(TenantPlanAssigner::class, SubscriptionPlanChangeService::class);
        $this->app->bind(TenantLimitGuard::class, LimitEnforcementService::class);

        $this->app->register(RouteServiceProvider::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));

        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('subscriptions.platform.permission', EnsureSubscriptionsPlatformPermission::class);
        $router->aliasMiddleware('subscriptions.super_admin.override', EnsureSuperAdminEntitlementOverride::class);
        $router->aliasMiddleware('entitlement', EnsureEntitlement::class);
        $router->aliasMiddleware('entitlement.limit', EnsureWithinLimit::class);

        $this->commands([
            BackfillTenantSubscriptions::class,
            VerifyEntitlementParity::class,
            ApplyScheduledSubscriptionChanges::class,
            SnapshotTenantUsage::class,
            SendUsageThresholdAlerts::class,
            AlignLaunchPlanFeatures::class,
            RebuildLaunchPlanCatalog::class,
            RunBillingReconciliation::class,
            ReleaseExpiredBillingReservations::class,
            ApplyScheduledBillingMaintenance::class,
        ]);

        $this->registerCommandSchedules();
    }

    protected function registerCommandSchedules(): void
    {
        $this->app->booted(function (): void {
            /** @var Schedule $schedule */
            $schedule = $this->app->make(Schedule::class);

            if (config('subscriptions.lifecycle.scheduler_enabled', true)) {
                $schedule->command('subscriptions:apply-scheduled')
                    ->everyFifteenMinutes()
                    ->withoutOverlapping();
            }

            if (config('subscriptions.usage.snapshot_scheduler_enabled', true)) {
                $schedule->command('subscriptions:snapshot-usage')
                    ->dailyAt((string) config('subscriptions.usage.snapshot_time', '02:10'))
                    ->withoutOverlapping();
            }

            if (config('subscriptions.usage.alerts_scheduler_enabled', true)) {
                $schedule->command('subscriptions:usage-alerts')
                    ->dailyAt((string) config('subscriptions.usage.alerts_time', '02:40'))
                    ->withoutOverlapping();
            }

            if (config('subscriptions.billing.reconciliation_scheduler_enabled', true)) {
                $schedule->command('billing:reconcile')
                    ->dailyAt((string) config('subscriptions.billing.reconciliation_time', '03:15'))
                    ->withoutOverlapping();
                $schedule->command('billing:release-expired-reservations')
                    ->hourly()
                    ->withoutOverlapping();
                $schedule->command('billing:apply-scheduled-maintenance')
                    ->everyFifteenMinutes()
                    ->withoutOverlapping();
            }
        });
    }
}
