<?php

namespace Folklore\JsonData\Console;

use Folklore\JsonData\Console\Concerns\FindsJsonDataModels;
use Folklore\JsonData\JsonDataCast;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Syncs the relations of the references stored in the JSON attributes of models, as a save
 * would, for rows written without model events: imports, `saveQuietly()`, raw SQL
 * (docs/json-data.md, "Syncing stored rows").
 */
class JsonReferencesSyncCommand extends Command
{
    use FindsJsonDataModels;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'json-data:sync-references
        {--model=* : A model to sync; by default, the models of app/Models, and those added with JsonReferencesRewriteCommand::discoverUsing(), that cast a JSON attribute with JsonDataCast}
        {--chunk=500 : How many rows to read at a time}
        {--dry-run : Report what would change, without writing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync the relations of the JSON references of stored rows';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));
        $rows = [];
        foreach ($this->models() as $class) {
            $rows[] = [$class, ...$this->sync($class, $chunk, $dryRun)];
        }

        $this->table([
            'Model',
            'Rows',
            $dryRun ? 'Rows to change' : 'Rows changed',
            $dryRun ? 'To attach' : 'Attached',
            $dryRun ? 'To detach' : 'Detached',
            'Synced by callbacks, not compared',
            'Unresolved references',
        ], $rows);
        if ($dryRun) {
            $this->info('Dry run: nothing was written.');
        }

        return self::SUCCESS;
    }

    /**
     * Syncs the relations of one model class, in chunks, without saving the models and
     * without model events. A row is synced when its plan changes a relation, or has a
     * relation that a callback syncs without saying what it has synced.
     *
     * @param  class-string<Model>  $class
     * @return array{int, int, int, int, int, int}
     */
    protected function sync(string $class, int $chunk, bool $dryRun): array
    {
        $read = 0;
        $changed = 0;
        $attached = 0;
        $detached = 0;
        $notCompared = 0;
        $unresolved = 0;

        (new $class)->newQueryWithoutScopes()->chunkById($chunk, function (Collection $models) use (
            $dryRun,
            &$read,
            &$changed,
            &$attached,
            &$detached,
            &$notCompared,
            &$unresolved
        ) {
            foreach ($models as $model) {
                $read++;
                $rowChanged = false;
                $rowNotCompared = false;
                foreach (JsonDataCast::syncRelations($model, null, true) ?? [] as $relationName => $item) {
                    foreach ($item['unresolved'] ?? [] as $entry) {
                        $unresolved++;
                        $this->line($this->describe($model)." {$entry->attribute}.{$entry->path}: {$entry} doesn't resolve", null, 'v');
                    }
                    // Null when a callback syncs the relation without saying what it has synced
                    $relationChanged = array_key_exists('changed', $item) ? $item['changed'] : false;
                    if (is_null($relationChanged)) {
                        $rowNotCompared = true;
                        $this->line($this->describe($model)." {$relationName}: synced by a callback that can't say what it has synced", null, 'v');
                    } elseif ($relationChanged) {
                        $rowChanged = true;
                        $attached += count($item['attach'] ?? []);
                        $detached += count($item['detach'] ?? []);
                        $this->line($this->describe($model)." {$relationName}: ".$this->describeChanges($item), null, 'v');
                    }
                }
                $changed += $rowChanged ? 1 : 0;
                $notCompared += $rowNotCompared ? 1 : 0;
                if (! $dryRun && ($rowChanged || $rowNotCompared)) {
                    $model->getConnection()->transaction(fn () => JsonDataCast::syncRelations($model));
                }
            }
        });

        return [$read, $changed, $attached, $detached, $notCompared, $unresolved];
    }

    protected function describe(Model $model): string
    {
        $key = $model->getKey();

        return $model::class.' #'.(is_scalar($key) ? (string) $key : '?');
    }

    /**
     * @param  array{attach?: list<int|string>, detach?: list<int|string>, delete?: list<int|string>}  $item
     */
    protected function describeChanges(array $item): string
    {
        $changes = [];
        foreach (['attach', 'detach', 'delete'] as $change) {
            $keys = $item[$change] ?? [];
            if (count($keys) > 0) {
                $changes[] = $change.' '.implode(', ', $keys);
            }
        }

        return implode('; ', $changes);
    }
}
