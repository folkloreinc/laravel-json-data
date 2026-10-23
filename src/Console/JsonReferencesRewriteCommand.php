<?php

namespace Folklore\Console;

use Folklore\Contracts\Eloquent\HasJsonDataRelations;
use Folklore\Eloquent\JsonData;
use Folklore\Eloquent\JsonDataCast;
use Folklore\Eloquent\JsonReference;
use Folklore\Eloquent\JsonReferenceEntry;
use Folklore\Models\Block;
use Folklore\Models\Page;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Rewrites the references of one key to another in the JSON attributes of models, such as
 * `medias://12` to `media://med_…` (docs/json-data.md, "Rewriting references").
 */
class JsonReferencesRewriteCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'folklore:json-references:rewrite
        {--from= : The key of the references to rewrite}
        {--to= : The key to write instead, mapped to the same model}
        {--model=* : A model to rewrite; by default, the models of app/Models, and the pages module\'s, that cast a JSON attribute with JsonDataCast}
        {--chunk=500 : How many rows to read at a time}
        {--dry-run : Report what would change, without writing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rewrite the JSON references of one key to another';

    public function handle(): int
    {
        $from = $this->option('from');
        $to = $this->option('to');
        if (! is_string($from) || ! is_string($to) || $from === '' || $to === '' || $from === $to) {
            $this->error('Give two different keys: --from=medias --to=media.');

            return self::FAILURE;
        }
        $fromTarget = JsonData::target($from);
        $toTarget = JsonData::target($to);
        if (is_null($fromTarget) || is_null($toTarget)) {
            $this->error('Both keys must be mapped with JsonData::map(): '.implode(', ', array_filter([
                is_null($fromTarget) ? $from : null,
                is_null($toTarget) ? $to : null,
            ])).' is not.');

            return self::FAILURE;
        }
        if (! $fromTarget->accepts($toTarget->model) && ! $toTarget->accepts($fromTarget->model)) {
            $this->error("[{$from}] and [{$to}] are not mapped to the same model.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));
        $rows = [];
        foreach ($this->models() as $class) {
            [$changed, $rewritten, $unresolved] = $this->rewrite($class, $from, $fromTarget, $to, $toTarget, $chunk, $dryRun);
            $rows[] = [$class, $changed, $rewritten, $unresolved];
        }

        $this->table(['Model', $dryRun ? 'Rows to change' : 'Rows changed', 'References', 'Unresolved, left as they are'], $rows);
        if ($dryRun) {
            $this->info('Dry run: nothing was written.');
        }

        return self::SUCCESS;
    }

    /**
     * Rewrites the references of one model class, in chunks, without model events.
     *
     * @param  class-string<Model>  $class
     * @return array{int, int, int}
     */
    protected function rewrite(string $class, string $from, JsonReference $fromTarget, string $to, JsonReference $toTarget, int $chunk, bool $dryRun): array
    {
        $changed = 0;
        $rewritten = 0;
        $unresolved = 0;

        (new $class)->newQueryWithoutScopes()->chunkById($chunk, function (Collection $models) use (
            $from,
            $fromTarget,
            $to,
            $toTarget,
            $dryRun,
            &$changed,
            &$rewritten,
            &$unresolved
        ) {
            /** @var array<int, array{Model, list<JsonReferenceEntry>}> $owners */
            $owners = [];
            $identifiers = [];
            foreach ($models as $model) {
                $entries = JsonDataCast::references($model)->filter(fn (JsonReferenceEntry $entry) => $entry->key === $from)->values()->all();
                if (count($entries) > 0) {
                    $owners[] = [$model, $entries];
                    array_push($identifiers, ...array_map(fn (JsonReferenceEntry $entry) => $entry->identifier, $entries));
                }
            }
            if (count($owners) === 0) {
                return;
            }

            $converted = $this->convert(array_values(array_unique($identifiers)), $fromTarget, $toTarget);

            foreach ($owners as [$model, $entries]) {
                $values = [];
                foreach ($entries as $entry) {
                    $attribute = (string) $entry->attribute;
                    if (! isset($converted[$entry->identifier])) {
                        $unresolved++;

                        continue;
                    }
                    if (! array_key_exists($attribute, $values)) {
                        $raw = $model->getAttributes()[$attribute] ?? null;
                        $values[$attribute] = is_string($raw) ? json_decode($raw, true) : null;
                    }
                    data_set($values[$attribute], (string) $entry->path, $to.'://'.$converted[$entry->identifier]);
                    $rewritten++;
                }
                if (count($values) === 0) {
                    continue;
                }
                $changed++;
                if (! $dryRun) {
                    $model->newQueryWithoutScopes()->whereKey($model->getKey())->update(array_map(
                        fn (mixed $value) => json_encode($value, JSON_THROW_ON_ERROR),
                        $values,
                    ));
                }
            }
        });

        return [$changed, $rewritten, $unresolved];
    }

    /**
     * The identifiers of the `to` key for those of the `from` key, read from the model in
     * one query. Identifiers of models that don't exist are left out.
     *
     * @param  list<string>  $identifiers
     * @return array<string, string>
     */
    protected function convert(array $identifiers, JsonReference $fromTarget, JsonReference $toTarget): array
    {
        $model = new $toTarget->model;
        $converted = [];
        foreach ($model->newQueryWithoutScopes()->whereIn($fromTarget->column(), $identifiers)->get() as $found) {
            $fromIdentifier = $fromTarget->identifierOf($found);
            $toIdentifier = $toTarget->identifierOf($found);
            if (! is_null($fromIdentifier) && ! is_null($toIdentifier)) {
                $converted[$fromIdentifier] = $toIdentifier;
            }
        }

        return $converted;
    }

    /**
     * The models to rewrite: `--model`, or the models of app/Models and of the pages
     * module that cast a JSON attribute with JsonDataCast.
     *
     * @return list<class-string<Model>>
     */
    protected function models(): array
    {
        $option = $this->option('model');
        $given = array_values(array_filter(is_array($option) ? $option : [], 'is_string'));
        $models = [];
        foreach (count($given) > 0 ? $given : $this->discoveredModels() as $class) {
            if (is_subclass_of($class, Model::class) && is_subclass_of($class, HasJsonDataRelations::class) && $this->castsJsonData($class)) {
                $models[] = $class;
            } elseif (count($given) > 0) {
                $this->warn("[{$class}] is not a model with JSON references: skipped.");
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
        if ((bool) config('site.modules.pages')) {
            array_push($classes, Page::class, Block::class);
        }

        return array_values(array_filter($classes, 'class_exists'));
    }
}
