<?php

namespace Folklore\JsonData;

use Folklore\JsonData\Contracts\ReferenceHandler;
use Folklore\JsonData\Exceptions\InvalidJsonReference;
use Folklore\JsonData\Exceptions\ReferenceNotAllowed;
use Folklore\JsonData\Support\Paths;
use Illuminate\Container\Container;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use WeakMap;

/**
 * References to models in JSON columns (docs/json-data.md). The part before `://` is a
 * key of the registry (`JsonData::map()`), or, when it isn't mapped, the name of the
 * relation it is declared with, as in 1.x.
 */
class JsonDataCast implements CastsAttributes
{
    /**
     * The handle of laravel-mediatheque's pivot under which laravel-folklore's medias
     * module syncs the media referenced in the JSON columns of a model, through
     * `JsonData::syncUsing()`.
     *
     * @deprecated Kept for the code that read it here before the package left
     * laravel-folklore. Removed in 2.0.
     */
    public const MEDIAS_HANDLE = 'json';

    /**
     * The values assigned to declared paths that are prepared when their model is saved
     * (docs/decisions/0013), by model, attribute and path.
     *
     * @var WeakMap<Model, array<string, array<string, array{value: mixed, relation: array<string, mixed>, relationName: string}>>>|null
     */
    private static ?WeakMap $pending = null;

    /**
     * The models that mapped keys resolved to, by model and attribute, for the raw value
     * they were resolved from.
     *
     * @var WeakMap<Model, array<string, array{raw: mixed, models: array<string, Model|null>}>>|null
     */
    private static ?WeakMap $resolved = null;

    /**
     * The model classes whose `saving` event prepares pending values, and whose `saved`
     * event syncs relations, by event dispatcher. Weak, so that a dispatcher that is
     * gone, and whose object id is reused, doesn't count.
     *
     * @var WeakMap<Dispatcher, array<string, true>>|null
     */
    private static ?WeakMap $listening = null;

    /**
     * Cast the given value.
     *
     * @param  Model  $model
     * @param  string  $key
     * @param  mixed  $value
     * @param  array  $attributes
     * @return array
     */
    public function get($model, $key, $value, $attributes)
    {
        $value = ! empty($value) ? json_decode($value, true) : null;

        $relations = self::relations($model, $key, $value, $attributes);
        if (count($relations) > 0) {
            $raw = $attributes[$key] ?? null;
            // The paths of the stored value, flattened once. Each pattern visits them, not the
            // value as the previous patterns left it: a flatten would walk the models they put
            // in it as their arrays, once per pattern
            $paths = Paths::all($value);
            $resolved = self::resolveMappedReferences($model, $key, $raw, $value, $relations, $paths);

            foreach ($relations as $relation) {
                foreach (self::paths($relation) as $pattern) {
                    $value = Paths::reduce([$pattern], $value, function (mixed $newValue, int|string $path, mixed $item) use (
                        $model,
                        $relation,
                        $pattern,
                        $resolved
                    ) {
                        $path = (string) $path;
                        if (
                            isset($relation['skip']) &&
                            is_callable($relation['skip']) &&
                            call_user_func($relation['skip'], $item, $path, 'get', $model, $relation)
                        ) {
                            return $newValue;
                        }
                        $relationName = self::relationName($model, $relation, $item, $path);
                        $getter = self::callback($model, $relation, $relationName, 'get');
                        if (isset($getter)) {
                            $newItem = $getter($item, $path, $model, $relation);
                            data_set($newValue, $path, $newItem);

                            return $newValue;
                        }
                        if (! is_string($item)) {
                            return $newValue;
                        }

                        $entry = JsonData::parse($item);
                        if (is_null($entry)) {
                            return $newValue;
                        }

                        if ($entry->isMapped()) {
                            $related = self::acceptedClass($model, $relation, $relationName);
                            if (is_null($related) || ! $entry->target?->accepts($related)) {
                                if (self::isRecursive($pattern)) {
                                    return $newValue;
                                }
                                throw InvalidJsonReference::keyForAnotherModel($entry->key, (string) $related);
                            }
                            data_set($newValue, $path, $resolved[self::memoKey($entry->key, $relation, $entry->identifier)] ?? null);

                            return $newValue;
                        }

                        // An unmapped key falls back to the relation of the same name, as in 1.x
                        if (self::isRecursive($pattern) || $entry->key !== $relationName || ! method_exists($model, $relationName)) {
                            return $newValue;
                        }
                        data_set($newValue, $path, self::resolveThroughRelation($model, $relationName, $relation, $entry->identifier));

                        return $newValue;
                    }, $paths);
                }
            }

            $value = self::withPendingValues($model, $key, $value);
        }

        return $value;
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  Model  $model
     * @param  string  $key
     * @param  array  $value
     * @param  array  $attributes
     * @return array<string, mixed>|string|null
     */
    public function set($model, $key, $value, $attributes)
    {
        $relations = self::relations($model, $key, $value, $attributes);
        if (count($relations) > 0) {
            self::listen($model);
            self::forgetPending($model, $key);
            $rawStored = $model->getAttributes()[$key] ?? null;
            $stored = is_string($rawStored) && $rawStored !== '' ? json_decode($rawStored, true) : null;

            foreach ($relations as $relation) {
                foreach (self::paths($relation) as $pattern) {
                    $recursive = self::isRecursive($pattern);
                    $value = Paths::reduce([$pattern], $value, function (
                        mixed $newValue,
                        int|string $path,
                        mixed $item
                    ) use ($model, $key, $relation, $recursive, $stored) {
                        $path = (string) $path;
                        if (
                            isset($relation['skip']) &&
                            is_callable($relation['skip']) &&
                            call_user_func($relation['skip'], $item, $path, 'set', $model, $relation)
                        ) {
                            return $newValue;
                        }
                        if (is_array($item) && ($recursive || array_is_list($item))) {
                            return $newValue;
                        }
                        $relationName = self::relationName($model, $relation, $item, $path);
                        // Already serialized, for instance data read raw and written back
                        if (self::isAcceptedReference($model, $item, $relation, $relationName)) {
                            return $newValue;
                        }
                        if ($recursive && ! self::isModelOrUnwrappable($item)) {
                            return $newValue;
                        }
                        $beforeSet = self::callback($model, $relation, $relationName, 'before_set');
                        if (isset($beforeSet)) {
                            $item = $beforeSet($item, $path, $model, $relation, $relationName);
                        }
                        $setter = self::callback($model, $relation, $relationName, 'set');
                        if (isset($setter)) {
                            $newItem = $setter($item, $path, $model, $relation, $relationName);
                            data_set($newValue, $path, $newItem);

                            return $newValue;
                        }
                        if (is_null($item) || self::isAcceptedReference($model, $item, $relation, $relationName)) {
                            data_set($newValue, $path, $item);

                            return $newValue;
                        }

                        if (self::defersPreparing($model)) {
                            self::addPending($model, $key, $path, $item, $relation, $relationName);
                            $previous = data_get($stored, $path);
                            data_set($newValue, $path, self::isAcceptedReference($model, $previous, $relation, $relationName) ? $previous : null);

                            return $newValue;
                        }

                        data_set($newValue, $path, self::prepareReference($model, $key, $path, $item, $relation, $relationName));

                        return $newValue;
                    });
                }
            }
        }

        $extracted = self::extractedColumns($model, $key, $value, $attributes);
        if (count($extracted) > 0) {
            return array_merge(
                [$key => ! is_null($value) ? json_encode($value, JSON_THROW_ON_ERROR) : null],
                $extracted,
            );
        }

        return ! is_null($value) ? json_encode($value, JSON_THROW_ON_ERROR) : null;
    }

    /**
     * The values of a JSON attribute that the model copies into columns, as declared.
     *
     * @param  array<array-key, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected static function extractedColumns(Model $model, string $key, mixed $value, array $attributes): array
    {
        $columns = JsonData::declarations($model, $key, $value, $attributes)['columns'];
        $extracted = [];
        foreach ($columns as $path => $column) {
            $extracted[$column] = data_get($value, $path);
        }

        return $extracted;
    }

    /**
     * The reference paths declared for a JSON attribute, normalized.
     *
     * @param  array<array-key, mixed>  $attributes
     * @return list<array<string, mixed>>
     */
    protected static function relations(Model $model, string $key, mixed $value, array $attributes): array
    {
        $declared = JsonData::declarations($model, $key, $value, $attributes)['references'];
        $relations = [];
        foreach (self::normalizeJsonDataRelations(is_array($declared) || $declared instanceof Collection ? $declared : []) as $relation) {
            if (is_array($relation)) {
                $relations[] = array_filter($relation, fn (mixed $option) => is_string($option), ARRAY_FILTER_USE_KEY);
            }
        }

        return $relations;
    }

    /**
     * The path patterns of a normalized relation.
     *
     * @param  array<string, mixed>  $relation
     * @return list<string>
     */
    protected static function paths(array $relation): array
    {
        $paths = $relation['path'] ?? [];

        return array_values(array_filter(is_array($paths) ? $paths : [$paths], 'is_string'));
    }

    /**
     * Turns the pending values of a model into references, before it is saved: the
     * handler of each value's key prepares it and checks that the model may refer to it.
     */
    public static function preparePending(Model $model): void
    {
        $pending = self::pendingStore()[$model] ?? [];
        if (count($pending) === 0) {
            return;
        }
        unset(self::pendingStore()[$model]);

        foreach ($pending as $key => $values) {
            $rawValue = $model->getAttributes()[$key] ?? null;
            $value = is_string($rawValue) && $rawValue !== '' ? json_decode($rawValue, true) : [];
            foreach ($values as $path => $item) {
                data_set($value, $path, self::prepareReference(
                    $model,
                    $key,
                    $path,
                    $item['value'],
                    $item['relation'],
                    $item['relationName']
                ));
            }
            // Written raw: the other paths were sorted when they were assigned
            $attributes = [$key => json_encode($value, JSON_THROW_ON_ERROR)];
            $attributes = array_merge($attributes, self::extractedColumns($model, $key, $value, $model->getAttributes()));
            $model->setRawAttributes(array_merge($model->getAttributes(), $attributes));
        }
    }

    /**
     * Resolves the references of a collection of models together: one call to each key's
     * handler for the whole collection, and, for keys that aren't mapped, the relation
     * eager-loaded on the collection. Each model's value is then read without a query
     * (docs/json-data.md, "Batch resolution").
     *
     * @param  iterable<mixed>  $models
     * @param  list<string>|null  $attributes  The JSON attributes, or every one.
     */
    public static function load(iterable $models, ?array $attributes = null): void
    {
        /** @var array<string, array{entry: JsonReferenceEntry, relation: array<string, mixed>, relationName: string|null, ownerClass: class-string<Model>, attribute: string, identifiers: list<string>, owners: list<array{Model, mixed, string}>}> $groups */
        $groups = [];
        /** @var array<class-string<Model>, array{models: array<int, Model>, relations: array<string, true>}> $eager */
        $eager = [];

        foreach ($models as $model) {
            if (! $model instanceof Model) {
                continue;
            }
            foreach (self::castAttributes($model, $attributes) as $attribute) {
                $raw = $model->getAttributes()[$attribute] ?? null;
                foreach (self::storedReferences($model, $attribute) as [$entry, $relation]) {
                    if ($entry->isMapped() && ! is_null($entry->target)) {
                        $group = $model::class.'|'.$attribute.'|'.self::memoKey($entry->key, $relation, '');
                        $groups[$group] ??= [
                            'entry' => $entry,
                            'relation' => $relation,
                            'relationName' => $entry->relation,
                            'ownerClass' => $model::class,
                            'attribute' => $attribute,
                            'identifiers' => [],
                            'owners' => [],
                        ];
                        $groups[$group]['identifiers'][] = $entry->identifier;
                        $groups[$group]['owners'][] = [$model, $raw, $entry->identifier];
                    } elseif (! is_null($entry->relation)) {
                        $eager[$model::class]['models'][spl_object_id($model)] = $model;
                        $eager[$model::class]['relations'][$entry->relation] = true;
                    }
                }
            }
        }

        foreach ($groups as $group) {
            $entry = $group['entry'];
            if (is_null($entry->target)) {
                continue;
            }
            $handler = self::handler($entry->target, $group['relation']);
            $found = $handler->resolveMany(array_values(array_unique($group['identifiers'])), new ReferenceContext(
                operation: ReferenceOperation::Read,
                key: $entry->key,
                target: $entry->target,
                ownerClass: $group['ownerClass'],
                owner: null,
                attribute: $group['attribute'],
                relation: $group['relationName'],
            ));
            foreach ($group['owners'] as [$owner, $raw, $identifier]) {
                $resolved = $found[$identifier] ?? null;
                $allowed = ! is_null($resolved) && $handler->allows($resolved, new ReferenceContext(
                    operation: ReferenceOperation::Read,
                    key: $entry->key,
                    target: $entry->target,
                    ownerClass: $group['ownerClass'],
                    owner: $owner,
                    attribute: $group['attribute'],
                    relation: $group['relationName'],
                ));
                self::seedResolved($owner, $group['attribute'], $raw, self::memoKey($entry->key, $group['relation'], $identifier), $allowed ? $resolved : null);
            }
        }

        foreach ($eager as $batch) {
            (new EloquentCollection(array_values($batch['models'])))->loadMissing(array_keys($batch['relations']));
        }
    }

    /**
     * The references stored in a model's JSON attributes, at their declared paths, whether
     * they resolve or not (docs/json-data.md, "Reference listing").
     *
     * @param  list<string>|null  $attributes  The JSON attributes, or every one.
     * @return Collection<int, JsonReferenceEntry>
     */
    public static function references(Model $model, ?array $attributes = null): Collection
    {
        $entries = [];
        foreach (self::castAttributes($model, $attributes) as $attribute) {
            foreach (self::storedReferences($model, $attribute) as [$entry]) {
                $entries[] = $entry;
            }
        }

        return new Collection($entries);
    }

    /**
     * The attributes of a model cast with this cast, or a subclass of it.
     *
     * @param  list<string>|null  $only
     * @return list<string>
     */
    protected static function castAttributes(Model $model, ?array $only = null): array
    {
        $attributes = [];
        foreach ($model->getCasts() as $attribute => $cast) {
            if (is_a($cast, self::class, true) && (is_null($only) || in_array($attribute, $only, true))) {
                $attributes[] = (string) $attribute;
            }
        }

        return $attributes;
    }

    /**
     * The attributes of a model cast with this cast, or a subclass of it.
     *
     * @return list<string>
     *
     * @internal
     */
    public static function castAttributesOf(Model $model): array
    {
        return self::castAttributes($model);
    }

    /**
     * The decoded raw value of a JSON attribute, with the values assigned since it was
     * saved and not prepared yet put back at their paths: what `JsonData::fill()` merges
     * its input into. References stay strings, whether they resolve or not.
     *
     * @return array<array-key, mixed>|null
     *
     * @internal
     */
    public static function storedWithPending(Model $model, string $attribute): ?array
    {
        $value = self::withPendingValues($model, $attribute, self::stored($model, $attribute));

        return is_array($value) ? $value : null;
    }

    /**
     * The references that a relation accepts at its declared paths, in a model's stored
     * JSON attribute: mapped keys of the related model (or, for a path declared with a key
     * and no relation, of the key's model), and, outside recursive paths, the key named
     * after the relation when it isn't mapped.
     *
     * @return list<array{JsonReferenceEntry, array<string, mixed>}>
     */
    protected static function storedReferences(Model $model, string $attribute): array
    {
        $raw = $model->getAttributes()[$attribute] ?? null;
        $value = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (! is_array($value)) {
            return [];
        }

        $references = [];
        foreach (self::relations($model, $attribute, $value, $model->getAttributes()) as $relation) {
            $relationName = self::relationName($model, $relation, null, null);
            $related = self::acceptedClass($model, $relation, $relationName);
            foreach (self::paths($relation) as $pattern) {
                foreach (Paths::matching([$pattern], $value) as $path) {
                    $item = data_get($value, $path);
                    $entry = is_string($item) ? JsonData::parse($item) : null;
                    if (is_null($entry)) {
                        continue;
                    }
                    $accepted = $entry->isMapped()
                        ? ! is_null($related) && (bool) $entry->target?->accepts($related)
                        : ! self::isRecursive($pattern) && $entry->key === $relationName && ! is_null($related);
                    if ($accepted) {
                        $references[] = [
                            new JsonReferenceEntry($entry->key, $entry->identifier, $entry->target, $attribute, (string) $path, $relationName !== '' ? $relationName : null),
                            $relation,
                        ];
                    }
                }
            }
        }

        return $references;
    }

    /**
     * Memoizes a resolved model of a model's attribute, for its raw value.
     */
    protected static function seedResolved(Model $model, string $attribute, mixed $raw, string $memoKey, ?Model $resolved): void
    {
        $memo = self::resolvedStore()[$model] ?? [];
        $current = $memo[$attribute] ?? null;
        $models = ! is_null($current) && $current['raw'] === $raw ? $current['models'] : [];
        $models[$memoKey] = $resolved;
        $memo[$attribute] = ['raw' => $raw, 'models' => $models];
        self::resolvedStore()[$model] = $memo;
    }

    /**
     * Syncs the relations of the references at their paths (docs/json-data.md, "Syncing").
     * The cast runs it after a model is saved, for the attributes that changed. A relation
     * gathers its references from every JSON attribute of the model.
     *
     * With `pretend`, nothing is written and the model is left as it is: the plan of each
     * relation also says what the sync would change, read from the database, and which
     * references don't resolve (docs/json-data.md, "Syncing stored rows"). Returns the
     * plan by relation, or null for a value that isn't a model.
     *
     * @param  list<string>|null  $only  The JSON attributes whose relations are synced, or every one.
     * @return array<string, array{relation: array<string, mixed>, ids: list<mixed>, current?: list<int|string>|null, attach?: list<int|string>, detach?: list<int|string>, delete?: list<int|string>, changed?: bool|null, unresolved?: list<JsonReferenceEntry>}>|null
     */
    public static function syncRelations($model, ?array $only = null, bool $pretend = false)
    {
        // Only models have casts and relations to sync
        if (! ($model instanceof Model)) {
            return null;
        }

        $castsWithRelations = self::castAttributes($model);

        $relationsMap = [];
        $declaredIn = [];
        $unresolved = [];
        $attributes = $model->getAttributes();
        foreach ($castsWithRelations as $key) {
            $attributeValue = data_get($attributes, $key);
            $value = ! empty($attributeValue) ? json_decode($attributeValue, true) : null;
            if (! is_array($value)) {
                continue;
            }
            foreach (self::relations($model, (string) $key, $value, $attributes) as $relation) {
                if (! ($relation['sync'] ?? true)) {
                    continue;
                }
                $relationName = self::relationName($model, $relation, null, null);
                // A path declared with a key and no relation is not synced
                if ($relationName === '') {
                    continue;
                }
                $declaredIn[$relationName][] = (string) $key;
                $found = self::referencedKeys($model, $relationName, self::paths($relation), $value, (string) $key);
                $unresolved[$relationName] = array_merge($unresolved[$relationName] ?? [], $found['unresolved']);
                $relationsMap[$relationName] = [
                    // The options of every declaration of the relation, the first one winning
                    'relation' => array_merge($relation, $relationsMap[$relationName]['relation'] ?? []),
                    'ids' => array_values(array_unique(
                        array_merge(
                            $relationsMap[$relationName]['ids'] ?? [],
                            $found['keys'],
                        ),
                        SORT_REGULAR,
                    )),
                ];
            }
        }

        if (! is_null($only)) {
            $relationsMap = array_filter(
                $relationsMap,
                fn (string $relationName) => count(array_intersect($declaredIn[$relationName] ?? [], $only)) > 0,
                ARRAY_FILTER_USE_KEY,
            );
        }

        if ($pretend) {
            foreach ($relationsMap as $relationName => $item) {
                $relationsMap[$relationName] = array_merge(
                    $item,
                    self::syncDiff($model, $relationName, $item['relation'], $item['ids']),
                    ['unresolved' => $unresolved[$relationName] ?? []],
                );
            }

            return $relationsMap;
        }

        foreach ($relationsMap as $relationName => $item) {
            if (! method_exists($model, $relationName)) {
                continue;
            }
            $relationClass = $model->{$relationName}();
            $ids = $item['ids'];
            $relation = $item['relation'];
            $delete = data_get($relation, 'delete', false);
            if ($delete) {
                $relationClass->whereNotIn('id', $ids)->delete();
            }
            if (isset($relation['sync'])) {
                $relation['sync']($relationClass, $ids, $relation);
            } elseif ($relationClass instanceof Relation && self::syncedByHook($model, $relationName, $relationClass, $ids)) {
                // A callback of JsonData::syncUsing() synced it
            } elseif ($relationClass instanceof BelongsToMany) {
                $relationClass->sync($ids);
            } elseif ($relationClass instanceof BelongsTo && count($ids) > 0) {
                $relationClass->associate($ids[0]);
                self::saveForeignKey($model, $relationClass);
            } elseif ($relationClass instanceof BelongsTo) {
                $relationClass->dissociate();
            } elseif ($relationClass instanceof MorphOneOrMany && count($ids) > 0) {
                // Only the rows without a parent: a row of another parent stays there
                $relationClass
                    ->getRelated()
                    ->newQuery()
                    ->whereIn('id', $ids)
                    ->whereNull($relationClass->getForeignKeyName())
                    ->update([
                        $relationClass->getMorphType() => $relationClass->getMorphClass(),
                        $relationClass->getForeignKeyName() => $relationClass->getParentKey(),
                    ]);
            } elseif ($relationClass instanceof HasOneOrMany && count($ids) > 0) {
                $relationClass
                    ->getRelated()
                    ->newQuery()
                    ->whereIn('id', $ids)
                    ->whereNull($relationClass->getForeignKeyName())
                    ->update([
                        $relationClass->getForeignKeyName() => $relationClass->getParentKey(),
                    ]);
            }
        }

        // No refresh(): the synced relations are read again when they are next used
        foreach (array_keys($relationsMap) as $relationName) {
            $model->unsetRelation($relationName);
        }
        unset(self::resolvedStore()[$model]);

        return $relationsMap;
    }

    /**
     * What syncing a relation to the given keys would change, read from the database
     * without writing, as the sync by kind of relation of syncRelations() would do it:
     * the keys the relation holds, those to attach, to detach and, with the `delete`
     * option, the related rows to delete. `changed` is null when a `sync` callable or a
     * callback of `JsonData::syncUsing()` that can't say what it has synced would sync it.
     *
     * @param  array<string, mixed>  $relation
     * @param  list<mixed>  $ids
     * @return array{current: list<int|string>|null, attach: list<int|string>, detach: list<int|string>, delete: list<int|string>, changed: bool|null}
     */
    protected static function syncDiff(Model $model, string $relationName, array $relation, array $ids): array
    {
        $diff = ['current' => null, 'attach' => [], 'detach' => [], 'delete' => [], 'changed' => false];
        if (! method_exists($model, $relationName)) {
            return $diff;
        }
        $relationClass = $model->{$relationName}();
        if (! $relationClass instanceof Relation) {
            return $diff;
        }
        if (data_get($relation, 'delete', false)) {
            $diff['delete'] = self::keyValues((clone $relationClass)
                ->whereNotIn('id', $ids)
                ->pluck($relationClass->getRelated()->getQualifiedKeyName()));
        }

        $synced = isset($relation['sync']) ? null : JsonData::syncedKeys($model, $relationName, $relationClass);
        if (is_null($synced)) {
            return array_merge($diff, ['changed' => null]);
        }
        if (is_array($synced)) {
            $current = self::keyValues($synced);
            $target = self::keyValues($ids);
        } elseif ($relationClass instanceof BelongsToMany) {
            $current = self::keyValues($relationClass->newPivotQuery()->pluck($relationClass->getRelatedPivotKeyName()));
            $target = self::keyValues($ids);
        } elseif ($relationClass instanceof BelongsTo) {
            $foreignKey = $model->getAttribute($relationClass->getForeignKeyName());
            $current = self::keyValues([$foreignKey]);
            $target = self::keyValues(array_slice($ids, 0, 1));
        } elseif ($relationClass instanceof HasOneOrMany) {
            // Only the rows without a parent are attached, and none is detached
            $current = self::keyValues((clone $relationClass)->pluck($relationClass->getRelated()->getQualifiedKeyName()));
            $attach = count($ids) === 0 ? [] : self::keyValues($relationClass
                ->getRelated()
                ->newQuery()
                ->whereIn('id', $ids)
                ->whereNull($relationClass->getForeignKeyName())
                ->pluck($relationClass->getRelated()->getKeyName()));
            $target = array_merge($current, $attach);
        } else {
            // Not a kind of relation that the cast syncs
            return $diff;
        }

        $diff['current'] = $current;
        $diff['attach'] = array_values(array_diff($target, $current));
        $diff['detach'] = array_values(array_diff($current, $target));
        $diff['changed'] = count($diff['attach']) + count($diff['detach']) + count($diff['delete']) > 0;

        return $diff;
    }

    /**
     * The keys among values read from the database or the JSON, compared as strings, as
     * `BelongsToMany::sync()` compares them. Null and values that aren't keys are left out.
     *
     * @param  iterable<mixed>  $values
     * @return list<int|string>
     */
    protected static function keyValues(iterable $values): array
    {
        $keys = [];
        foreach ($values as $value) {
            if (is_int($value) || is_string($value)) {
                $keys[] = $value;
            } elseif (is_float($value)) {
                $keys[] = (string) $value;
            }
        }

        return $keys;
    }

    /**
     * After a model is saved with events, syncs the relations declared in the JSON
     * attributes that changed. `saveQuietly()` and `withoutEvents()` skip it.
     */
    public static function syncSaved(Model $model): void
    {
        $changed = array_values(array_filter(
            self::castAttributes($model),
            fn (string $attribute) => $model->isDirty($attribute),
        ));
        if (count($changed) > 0) {
            self::syncRelations($model, $changed);
        }
    }

    /**
     * The keys of the related models referenced at a relation's paths: the identifiers of
     * the mapped keys whose model is the related model, converted to its key when their
     * column isn't the key, and, as in 1.x, the identifiers of the key named after the
     * relation when it isn't mapped. Only the models found through the related model's
     * query count (#5): a reference to a row that doesn't exist, or that a global scope
     * hides, isn't synced.
     *
     * @param  array<int, string>  $paths
     * @param  array<array-key, mixed>  $data
     * @return list<mixed>
     */
    protected static function relatedKeys(Model $model, string $relationName, array $paths, array $data): array
    {
        return self::referencedKeys($model, $relationName, $paths, $data)['keys'];
    }

    /**
     * The keys of relatedKeys(), and the references whose related model isn't found, which
     * aren't synced. The attribute of the data is given to the references in the list.
     *
     * @param  array<int, string>  $paths
     * @param  array<array-key, mixed>  $data
     * @return array{keys: list<mixed>, unresolved: list<JsonReferenceEntry>}
     */
    protected static function referencedKeys(Model $model, string $relationName, array $paths, array $data, ?string $attribute = null): array
    {
        $related = self::relatedClass($model, $relationName);
        if (is_null($related)) {
            return ['keys' => [], 'unresolved' => []];
        }
        $relatedModel = new $related;

        /** @var array<string, list<JsonReferenceEntry>> $byColumn */
        $byColumn = [];
        foreach ($paths as $pattern) {
            foreach (Paths::matching([$pattern], $data) as $path) {
                $item = data_get($data, $path);
                $entry = is_string($item) ? JsonData::parse($item) : null;
                if (is_null($entry)) {
                    continue;
                }
                $entry = new JsonReferenceEntry($entry->key, $entry->identifier, $entry->target, $attribute, (string) $path, $relationName);
                if ($entry->isMapped()) {
                    if ($entry->target?->accepts($related)) {
                        $byColumn[$entry->target->column()][] = $entry;
                    }
                } elseif (! self::isRecursive($pattern) && $entry->key === $relationName) {
                    // An unmapped key is the relation's name, by the related model's key
                    $byColumn[$relatedModel->getKeyName()][] = $entry;
                }
            }
        }

        $keys = [];
        $unresolved = [];
        $integerKey = in_array($relatedModel->getKeyType(), ['int', 'integer'], true);
        foreach ($byColumn as $column => $entries) {
            // An integer key only has digits: other strings aren't looked up, which databases with types refuse
            $identifiers = array_values(array_unique(array_filter(
                array_map(fn (JsonReferenceEntry $entry) => $entry->identifier, $entries),
                fn (string $identifier) => $column !== $relatedModel->getKeyName() || ! $integerKey || ctype_digit($identifier),
            )));
            $found = count($identifiers) === 0 ? [] : $relatedModel->newQuery()
                ->whereIn($column, $identifiers)
                ->pluck($relatedModel->getKeyName(), $column)
                ->all();
            foreach ($entries as $entry) {
                if (array_key_exists($entry->identifier, $found)) {
                    $keys[] = $found[$entry->identifier];
                } else {
                    $unresolved[] = $entry;
                }
            }
        }

        return [
            'keys' => array_values(array_unique($keys, SORT_REGULAR)),
            'unresolved' => $unresolved,
        ];
    }

    /**
     * Whether a callback of `JsonData::syncUsing()` synced the relation, such as
     * laravel-folklore's sync of the media into laravel-mediatheque's usage graph.
     *
     * @param  Relation<Model, Model, mixed>  $relation
     * @param  list<mixed>  $ids
     */
    protected static function syncedByHook(Model $model, string $relationName, Relation $relation, array $ids): bool
    {
        foreach (JsonData::syncers() as $syncer) {
            if ($syncer($model, $relationName, $relation, $ids)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Save the foreign key that associate() only sets on the model: the sync
     * runs after the model is saved, and a refresh would discard it.
     */
    protected static function saveForeignKey(Model $model, BelongsTo $relation): void
    {
        $foreignKey = $relation->getForeignKeyName();
        if (! $model->exists || ! $model->isDirty($foreignKey)) {
            return;
        }

        $model
            ->newQueryWithoutScopes()
            ->whereKey($model->getKey())
            ->update([$foreignKey => $model->getAttribute($foreignKey)]);
        $model->syncOriginalAttribute($foreignKey);
        // associate() with an id leaves the relation loaded as null
        $model->unsetRelation($relation->getRelationName());
    }

    public static function normalizeJsonDataRelations(array|Collection $relations): Collection
    {
        $relations = collect($relations)
            ->map(function ($relation, $path) {
                return is_string($relation)
                    ? ['relation' => $relation, 'path' => $path, 'lazy' => false]
                    : array_merge(['path' => $path], (array) $relation);
            })
            ->values()
            ->reduce(function ($relations, $relation) {
                $foundKey = $relations->search(function ($existing) use ($relation) {
                    return ($existing['relation'] ?? null) === ($relation['relation'] ?? null) &&
                        Arr::except($existing, ['path', 'relation']) ==
                            Arr::except($relation, ['path', 'relation']);
                });
                if ($foundKey !== false) {
                    $existing = $relations->get($foundKey);
                    $existing['path'] = collect($existing['path'])
                        ->merge(
                            is_array($relation['path']) ? $relation['path'] : [$relation['path']]
                        )
                        ->unique()
                        ->values()
                        ->toArray();

                    return $relations->put($foundKey, $existing);
                }

                return $relations->push(
                    array_merge($relation, [
                        'path' => is_array($relation['path'])
                            ? $relation['path']
                            : [$relation['path']],
                    ])
                );
            }, collect());

        return $relations;
    }

    /**
     * The 1.x reference of a value: `{prefix}://{id}`, from a model, a value that
     * `JsonData::unwrapUsing()` turns into one, an id or an array with an `id`.
     *
     * @param  mixed  $item
     * @param  string  $pathPrefix
     */
    public static function getPathFromItem($item, $pathPrefix): ?string
    {
        $item = JsonData::unwrap($item);
        $id = match (true) {
            is_array($item) => $item['id'] ?? null,
            $item instanceof Model => $item->getKey(),
            default => $item,
        };
        if ((is_int($id) || is_float($id) || is_string($id)) && ! empty($id)) {
            return $pathPrefix.'://'.$id;
        }

        return null;
    }

    /**
     * The decoded raw value of a JSON attribute cast with this cast: references stay
     * strings, and the paths of pending values keep the reference stored before, which
     * set() wrote there (JsonData::stored()).
     *
     * @return array<array-key, mixed>|null
     */
    public static function stored(Model $model, string $attribute): ?array
    {
        if (count(self::castAttributes($model, [$attribute])) === 0) {
            throw new InvalidArgumentException("[{$attribute}] of [".$model::class.'] is not cast with '.self::class.'.');
        }

        $raw = $model->getAttributes()[$attribute] ?? null;
        $value = is_string($raw) && $raw !== '' ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;

        return is_array($value) ? $value : null;
    }

    /**
     * The value of a JSON attribute to merge an update into: the resolved
     * value, with the stored references that don't resolve put back (a lazy
     * relation that isn't loaded, a related model that is gone), so that
     * writing the value back doesn't replace them with null.
     */
    public static function getValueWithUnresolvedReferences(Model $model, string $key): mixed
    {
        $value = $model->getAttribute($key);
        $castType = $model->getCasts()[$key] ?? null;
        if (
            ! is_string($castType) ||
            ! is_a($castType, self::class, true) ||
            ! is_array($value)
        ) {
            return $value;
        }

        $rawValue = $model->getAttributes()[$key] ?? null;
        $storedValue = is_string($rawValue) ? json_decode($rawValue, true) : null;
        if (! is_array($storedValue)) {
            return $value;
        }

        $paths = [];
        foreach (self::relations($model, $key, $storedValue, $model->getAttributes()) as $relation) {
            array_push($paths, ...self::paths($relation));
        }
        foreach (Paths::matching($paths, $storedValue) as $path) {
            $reference = data_get($storedValue, $path);
            if (! is_null(self::getRelationAndIdFromPath($reference)) && is_null(data_get($value, $path))) {
                data_set($value, (string) $path, $reference);
            }
        }

        return $value;
    }

    public static function getRelationAndIdFromPath($path): ?array
    {
        if (empty($path)) {
            return null;
        }
        if (is_string($path) && preg_match('/^([^:]+):\/\/(.*)$/', $path, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }

        return null;
    }

    /**
     * Resolves, in one call to each key's handler, the references of mapped keys at the
     * declared paths of a model's attribute, and memoizes them for its raw value.
     *
     * @param  list<array<string, mixed>>  $relations
     * @param  list<int|string>|null  $paths  The paths of the value (Paths::all()), when already known
     * @return array<string, Model|null> By key, handler and identifier (memoKey())
     */
    protected static function resolveMappedReferences(Model $model, string $key, mixed $raw, mixed $value, array $relations, ?array $paths = null): array
    {
        $memo = self::resolvedStore()[$model][$key] ?? null;
        $resolved = ! is_null($memo) && $memo['raw'] === $raw ? $memo['models'] : [];

        /** @var array<string, array{entry: JsonReferenceEntry, relation: array<string, mixed>, relationName: string, identifiers: list<string>}> $missing */
        $missing = [];
        if (is_array($value)) {
            foreach ($relations as $relation) {
                $relationName = self::relationName($model, $relation, null, null);
                foreach (self::paths($relation) as $pattern) {
                    foreach (Paths::matching([$pattern], $value, $paths) as $path) {
                        $item = data_get($value, $path);
                        $entry = is_string($item) ? JsonData::parse($item) : null;
                        if (is_null($entry) || ! $entry->isMapped()) {
                            continue;
                        }
                        $memoKey = self::memoKey($entry->key, $relation, $entry->identifier);
                        if (array_key_exists($memoKey, $resolved)) {
                            continue;
                        }
                        $group = self::memoKey($entry->key, $relation, '');
                        $missing[$group] ??= ['entry' => $entry, 'relation' => $relation, 'relationName' => $relationName, 'identifiers' => []];
                        $missing[$group]['identifiers'][] = $entry->identifier;
                    }
                }
            }
        }

        foreach ($missing as $group) {
            $entry = $group['entry'];
            $target = $entry->target;
            if (is_null($target)) {
                continue;
            }
            $context = new ReferenceContext(
                operation: ReferenceOperation::Read,
                key: $entry->key,
                target: $target,
                ownerClass: $model::class,
                owner: $model,
                attribute: $key,
                relation: $group['relationName'] !== '' ? $group['relationName'] : null,
            );
            $handler = self::handler($target, $group['relation']);
            $models = $handler->resolveMany(array_values(array_unique($group['identifiers'])), $context);
            foreach ($group['identifiers'] as $identifier) {
                $found = $models[$identifier] ?? null;
                $resolved[self::memoKey($entry->key, $group['relation'], $identifier)] =
                    ! is_null($found) && $handler->allows($found, $context) ? $found : null;
            }
        }

        if (count($missing) > 0 || is_null($memo) || $memo['raw'] !== $raw) {
            self::resolvedStore()[$model] = array_merge(self::resolvedStore()[$model] ?? [], [
                $key => ['raw' => $raw, 'models' => $resolved],
            ]);
        }

        return $resolved;
    }

    /**
     * A reference of an unmapped key, through the owner's relation of the same name, as in
     * 1.x: only the rows attached to the owner resolve (docs/decisions/0016).
     *
     * @param  array<string, mixed>  $relation
     */
    protected static function resolveThroughRelation(Model $model, string $relationName, array $relation, string $id): mixed
    {
        $relationClass = $model->{$relationName}();
        if ($relationClass instanceof BelongsTo) {
            return $model->{$relationName};
        }
        $lazy = data_get($relation, 'lazy', false);

        return ! $lazy || $model->relationLoaded($relationName)
            ? $model->{$relationName}->find($id)
            : null;
    }

    /**
     * Turns a value into a reference with the relation's write key, through the key's
     * handler: the relation's `key` option, else the first key mapped to the related model,
     * else the relation's name.
     *
     * @param  array<string, mixed>  $relation
     */
    protected static function prepareReference(Model $model, string $key, string $path, mixed $value, array $relation, string $relationName): ?string
    {
        [$writeKey, $target] = self::writeTarget($model, $relation, $relationName);
        if (is_null($target)) {
            return self::unpreparable($model, $relationName !== '' ? $relationName : $writeKey);
        }

        $context = new ReferenceContext(
            operation: ReferenceOperation::Write,
            key: $writeKey,
            target: $target,
            ownerClass: $model::class,
            owner: $model,
            attribute: $key,
            path: $path,
            relation: $relationName !== '' ? $relationName : null,
        );
        $handler = self::handler($target, $relation);
        $prepared = $handler->prepare($value, $context);
        if (is_null($prepared)) {
            return self::unpreparable($model, $relationName !== '' ? $relationName : $writeKey);
        }
        if (! $handler->allows($prepared, $context)) {
            throw ReferenceNotAllowed::for($prepared, $context);
        }
        $identifier = $target->identifierOf($prepared);

        return is_null($identifier) ? self::unpreparable($model, $relationName !== '' ? $relationName : $writeKey) : $writeKey.'://'.$identifier;
    }

    /**
     * The key a relation writes and its target.
     *
     * @param  array<string, mixed>  $relation
     * @return array{string, JsonReference|null}
     */
    protected static function writeTarget(Model $model, array $relation, string $relationName): array
    {
        $related = self::relatedClass($model, $relationName);
        $key = $relation['key'] ?? null;
        if (is_string($key)) {
            $target = JsonData::target($key) ?? throw InvalidJsonReference::unmappedKey($key);
            if (! is_null($related) && ! $target->accepts($related)) {
                throw InvalidJsonReference::keyForAnotherModel($key, $related);
            }

            return [$key, $target];
        }

        $mapped = is_null($related) ? null : JsonData::keyFor($related);
        if (! is_null($mapped)) {
            return [$mapped, JsonData::target($mapped)];
        }

        if (JsonData::enforcesMap()) {
            throw InvalidJsonReference::unmappedModel($related ?? $relationName);
        }

        return [$relationName, is_null($related) ? null : JsonReference::to($related)];
    }

    /**
     * @param  string  $declared  The relation of the path, or its key when it has no relation.
     */
    protected static function unpreparable(Model $model, string $declared): ?string
    {
        if (JsonData::enforcesMap()) {
            throw new InvalidJsonReference("A value at a path of [{$declared}] on [".$model::class.'] is not something its reference handler takes.');
        }

        return null;
    }

    /**
     * The handler of a key's target, or the given one: what a reference node's validation
     * rule resolves its values with.
     *
     * @param  class-string|null  $using
     *
     * @internal
     */
    public static function handlerFor(JsonReference $target, ?string $using = null): ReferenceHandler
    {
        return self::handler($target, is_null($using) ? [] : ['using' => $using]);
    }

    /**
     * The handler of a path's `using` option, or of its key, or the default one.
     *
     * @param  array<string, mixed>  $relation
     */
    protected static function handler(JsonReference $target, array $relation): ReferenceHandler
    {
        $using = $relation['using'] ?? null;
        $class = is_string($using) ? $using : ($target->handler ?? ModelReferences::class);
        $handler = Container::getInstance()->make($class);
        if (! $handler instanceof ReferenceHandler) {
            throw new InvalidJsonReference("[{$class}] does not implement ".ReferenceHandler::class.'.');
        }

        return $handler;
    }

    /**
     * Whether a value is a stored reference that the relation accepts: a mapped key whose
     * model is the related model (or the model of the path's key, without a relation), or,
     * without a map, the relation's own name.
     *
     * @param  array<string, mixed>  $relation
     */
    protected static function isAcceptedReference(Model $model, mixed $item, array $relation, mixed $relationName): bool
    {
        if (! is_string($item) || ! is_string($relationName)) {
            return false;
        }
        $entry = JsonData::parse($item);
        if (is_null($entry)) {
            return false;
        }
        if (! $entry->isMapped()) {
            return $relationName !== '' && $entry->key === $relationName;
        }
        $related = self::acceptedClass($model, $relation, $relationName);

        return ! is_null($related) && (bool) $entry->target?->accepts($related);
    }

    /**
     * A callback of a declaration (`get`, `set`, `before_set`): given in its options, or
     * else by its `callbacks` option, a resolver given the callback's name, the name of
     * the relation and the model, through which laravel-folklore passes its 1.x macros.
     *
     * @param  array<string, mixed>  $relation
     */
    protected static function callback(Model $model, array $relation, string $relationName, string $name): ?callable
    {
        $callback = $relation[$name] ?? null;
        if (is_null($callback) && isset($relation['callbacks']) && is_callable($relation['callbacks'])) {
            $callback = call_user_func($relation['callbacks'], $name, $relationName, $model);
        }

        return is_callable($callback) ? $callback : null;
    }

    /**
     * Whether a value is a model, or a value that `JsonData::unwrapUsing()` turns into a
     * model or an identifier (an entity).
     */
    protected static function isModelOrUnwrappable(mixed $item): bool
    {
        return $item instanceof Model || (is_object($item) && JsonData::unwrap($item) !== $item);
    }

    /**
     * Whether a path pattern is recursive (`body.**`): only mapped keys are references there.
     */
    protected static function isRecursive(string $pattern): bool
    {
        return str_contains($pattern, '**');
    }

    /**
     * @param  array<string, mixed>  $relation
     */
    protected static function relationName(Model $model, array $relation, mixed $item, mixed $path): string
    {
        $relationName = $relation['relation'] ?? null;
        $name = is_callable($relationName)
            ? call_user_func($relationName, $item, $path, $model, $relation)
            : $relationName;

        return is_string($name) ? $name : '';
    }

    /**
     * The class of a relation's related model, or null when the model has no such relation.
     *
     * @return class-string<Model>|null
     */
    protected static function relatedClass(Model $model, string $relationName): ?string
    {
        if ($relationName === '' || ! method_exists($model, $relationName)) {
            return null;
        }
        $relation = $model->{$relationName}();

        return $relation instanceof Relation ? $relation->getRelated()::class : null;
    }

    /**
     * The class of the models that a declared path refers to: its relation's related
     * model, or, for a path declared with a key and no relation (`Json::reference()`
     * without `relation()`), the model the key is mapped to.
     *
     * @param  array<string, mixed>  $relation
     * @return class-string<Model>|null
     */
    protected static function acceptedClass(Model $model, array $relation, string $relationName): ?string
    {
        if ($relationName !== '') {
            return self::relatedClass($model, $relationName);
        }
        $key = $relation['key'] ?? null;

        return is_string($key) ? JsonData::target($key)?->model : null;
    }

    /**
     * @param  array<string, mixed>  $relation
     */
    protected static function memoKey(string $key, array $relation, string $identifier): string
    {
        $using = $relation['using'] ?? '';

        return $key.'|'.(is_string($using) ? $using : '').'|'.$identifier;
    }

    /**
     * Whether values are prepared when the model is saved: the model's class has an event
     * dispatcher, where listen() registers its `saving` listener.
     */
    protected static function defersPreparing(Model $model): bool
    {
        return self::listen($model);
    }

    /**
     * Registers, once per model class and event dispatcher, the `saving` listener that
     * prepares pending values and the `saved` one that syncs relations. False without a
     * dispatcher.
     */
    protected static function listen(Model $model): bool
    {
        $dispatcher = $model::getEventDispatcher();
        if (is_null($dispatcher)) {
            return false;
        }
        self::$listening ??= new WeakMap;
        $classes = self::$listening[$dispatcher] ?? [];
        if (! isset($classes[$model::class])) {
            $dispatcher->listen('eloquent.saving: '.$model::class, function (Model $saving) {
                self::preparePending($saving);
            });
            $dispatcher->listen('eloquent.saved: '.$model::class, function (Model $saved) {
                self::syncSaved($saved);
            });
            $classes[$model::class] = true;
            self::$listening[$dispatcher] = $classes;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $relation
     */
    protected static function addPending(Model $model, string $key, string $path, mixed $value, array $relation, string $relationName): void
    {
        $pending = self::pendingStore()[$model] ?? [];
        $pending[$key][$path] = ['value' => $value, 'relation' => $relation, 'relationName' => $relationName];
        self::pendingStore()[$model] = $pending;
    }

    protected static function forgetPending(Model $model, string $key): void
    {
        $pending = self::pendingStore()[$model] ?? [];
        unset($pending[$key]);
        if (count($pending) === 0) {
            unset(self::pendingStore()[$model]);
        } else {
            self::pendingStore()[$model] = $pending;
        }
    }

    /**
     * Puts the pending values back where they were assigned, until the model is saved.
     */
    protected static function withPendingValues(Model $model, string $key, mixed $value): mixed
    {
        foreach (self::pendingStore()[$model][$key] ?? [] as $path => $item) {
            data_set($value, $path, $item['value']);
        }

        return $value;
    }

    /**
     * @return WeakMap<Model, array<string, array<string, array{value: mixed, relation: array<string, mixed>, relationName: string}>>>
     */
    private static function pendingStore(): WeakMap
    {
        return self::$pending ??= new WeakMap;
    }

    /**
     * @return WeakMap<Model, array<string, array{raw: mixed, models: array<string, Model|null>}>>
     */
    private static function resolvedStore(): WeakMap
    {
        return self::$resolved ??= new WeakMap;
    }
}
