<?php

namespace Modules\Marketing\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class MarketingServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Marketing';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'marketing';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        \Modules\Marketing\Console\ImportMarketingContentCommand::class,
    ];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    /**
     * Define module schedules.
     * 
     * @param $schedule
     */
    // protected function configureSchedules(Schedule $schedule): void
    // {
    //     $schedule->command('inspire')->hourly();
    // }

    public function boot(): void
    {
        parent::boot();

        $this->mergeConfigFrom(module_path($this->name, 'config/config.php'), 'marketing');
        $this->mergeConfigFrom(module_path($this->name, 'config/web.php'), 'marketing.web');
        $this->mergeConfigFrom(module_path($this->name, 'config/seo-monitoring.php'), 'marketing.seo-monitoring');
        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));
        $this->loadViewsFrom(module_path($this->name, 'resources/views'), 'marketing');
    }
}
