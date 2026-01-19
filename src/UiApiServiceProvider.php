<?php

namespace Ogp\UiApi;

use Illuminate\Support\ServiceProvider;

class UiApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/uiapi.php', 'uiapi');
    }

    public function boot(): void
    {
        // Load package routes
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');

        // Publish config
        $this->publishes([
            __DIR__ . '/../config/uiapi.php' => $this->app->configPath('uiapi.php'),
        ], 'uiapi-config');

        // Publish view configs to configured target path
        $target = base_path(config('uiapi.view_configs_path', 'app/Services/viewConfigs'));
        $this->publishes([
            __DIR__ . '/../resources/viewConfigs' => $target,
        ], 'uiapi-view-configs');
    }
}
