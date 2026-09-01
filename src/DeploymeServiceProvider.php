<?php

namespace Wizcoders\Deployme;

use Illuminate\Support\ServiceProvider;

class DeploymeServiceProvider extends ServiceProvider
{
    /**
     * Merge so the package works with zero host configuration; a published
     * config/deployme.php in the host app then overrides key by key.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/deployme.php', 'deployme');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/deployme.php' => config_path('deployme.php'),
            ], 'deployme-config');
        }

        // A site that deploys by hand keeps the package but not the endpoint.
        if (config('deployme.route.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        }
    }
}
