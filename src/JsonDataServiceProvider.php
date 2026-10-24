<?php

namespace Folklore\JsonData;

use Folklore\JsonData\Console\JsonReferencesRewriteCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the console commands. The registry of keys and the hooks are static, set by
 * the application or by a package in its own service provider (docs/json-data.md).
 */
class JsonDataServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                JsonReferencesRewriteCommand::class,
            ]);
        }
    }
}
