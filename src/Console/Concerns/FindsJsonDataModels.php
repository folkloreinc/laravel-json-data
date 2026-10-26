<?php

namespace Folklore\JsonData\Console\Concerns;

use Folklore\JsonData\Console\JsonReferencesRewriteCommand;
use Folklore\JsonData\JsonDataCast;
use Illuminate\Database\Eloquent\Model;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The models that the package's commands look at: `--model`, or the models of app/Models
 * and of `JsonReferencesRewriteCommand::discoverUsing()` that cast a JSON attribute with
 * JsonDataCast.
 *
 * @internal
 */
trait FindsJsonDataModels
{
    /**
     * @return list<class-string<Model>>
     */
    protected function models(): array
    {
        $option = $this->option('model');
        $given = array_values(array_filter(is_array($option) ? $option : [], 'is_string'));
        $models = [];
        foreach (count($given) > 0 ? $given : $this->discoveredModels() as $class) {
            if (is_subclass_of($class, Model::class) && $this->castsJsonData($class)) {
                $models[] = $class;
            } elseif (count($given) > 0) {
                $this->warn("[{$class}] is not a model with a JSON attribute cast with JsonDataCast: skipped.");
            }
        }

        return $models;
    }

    /**
     * @param  class-string<Model>  $class
     */
    protected function castsJsonData(string $class): bool
    {
        foreach ((new $class)->getCasts() as $cast) {
            if (is_a($cast, JsonDataCast::class, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    protected function discoveredModels(): array
    {
        $classes = [];
        $directory = $this->laravel->basePath('app/Models');
        if (is_dir($directory)) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                    $relative = substr($file->getPathname(), strlen($directory) + 1, -4);
                    $classes[] = 'App\\Models\\'.str_replace('/', '\\', $relative);
                }
            }
        }
        foreach (JsonReferencesRewriteCommand::discoverers() as $discoverer) {
            foreach ($discoverer() as $class) {
                $classes[] = $class;
            }
        }

        return array_values(array_filter($classes, 'class_exists'));
    }
}
