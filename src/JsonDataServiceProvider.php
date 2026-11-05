<?php

namespace Folklore\JsonData;

use Folklore\JsonData\Console\JsonReferencesRewriteCommand;
use Folklore\JsonData\Console\JsonReferencesSyncCommand;
use Folklore\JsonData\Contracts\LocaleResolver;
use Folklore\JsonData\Schema\ConfigLocaleResolver;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the config, the locales of translatable nodes, the console commands, and the
 * listener that registers the cast's listeners when a model is saved. The registry of
 * keys and the hooks are static, set by the application or by a package in its own
 * service provider (docs/json-data.md).
 */
class JsonDataServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/json-data.php', 'json-data');

        // Not a singleton, so that the config is read when the locales are needed
        $this->app->bindIf(LocaleResolver::class, ConfigLocaleResolver::class);

        // The cast registers its listeners for a model class the first time a model of
        // the class is assigned a JSON value, or is saved with events: a JSON attribute
        // written raw is synced too, whatever ran before in the process (#37)
        $this->callAfterResolving('events', function (Dispatcher $events) {
            $events->listen('eloquent.saving: *', function (string $event, array $payload) {
                $model = $payload[0] ?? null;
                if ($model instanceof Model) {
                    JsonDataCast::listenWhenSaving($model);
                }
            });
        });
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
