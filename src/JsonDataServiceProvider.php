<?php

namespace Folklore\JsonData;

use Folklore\JsonData\Console\JsonReferencesRewriteCommand;
use Folklore\JsonData\Console\JsonReferencesSyncCommand;
use Folklore\JsonData\Contracts\LocaleResolver;
use Folklore\JsonData\Schema\ConfigLocaleResolver;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the config, the locales of translatable nodes and the console commands. The
 * registry of keys and the hooks are static, set by the application or by a package in
 * its own service provider (docs/json-data.md).
 */
class JsonDataServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/json-data.php', 'json-data');

        // Not a singleton, so that the config is read when the locales are needed
        $this->app->bindIf(LocaleResolver::class, ConfigLocaleResolver::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/json-data.php' => $this->app->configPath('json-data.php'),
            ], 'json-data-config');

            $this->commands([
                JsonReferencesRewriteCommand::class,
                JsonReferencesSyncCommand::class,
            ]);
        }
    }
}
