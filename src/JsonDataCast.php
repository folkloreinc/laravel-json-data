<?php

namespace Folklore\Eloquent;

use Folklore\Contracts\Eloquent\HasJsonDataColumnExtract;
use Folklore\Contracts\Eloquent\HasJsonDataRelations;
use Folklore\Contracts\Eloquent\ReferenceHandler;
use Folklore\Contracts\Entities\Entity;
use Folklore\Contracts\Entities\HasModel;
use Folklore\Contracts\Entities\ToEntity;
use Folklore\Eloquent\Exceptions\InvalidJsonReference;
use Folklore\Eloquent\Exceptions\ReferenceNotAllowed;
use Folklore\Support\Data;
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
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use WeakMap;

/**
 * References to models in JSON columns (docs/json-data.md). The part before `://` is a
 * key of the registry (`JsonData::map()`), or, when it isn't mapped, the name of the
 * relation it is declared with, as in 1.x.
 */
class JsonDataCast implements CastsAttributes
{
    use Macroable;

    /**
     * The handle of laravel-mediatheque's pivot under which the media referenced in the
     * JSON columns of a model are synced.
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

        if ($model instanceof HasJsonDataRelations) {
            $raw = $attributes[$key] ?? null;
            $relations = self::relations($model, $key, $value, $attributes);
            $resolved = self::resolveMappedReferences($model, $key, $raw, $value, $relations);

            foreach ($relations as $relation) {
                foreach (self::paths($relation) as $pattern) {
                    $value = Data::reducePaths([$pattern], $value, function (mixed $newValue, int|string $path, mixed $item) use (
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
                        $getter =
                            data_get($relation, 'get') ??
                            (data_get(
                                static::$macros,
                                get_class($model).':'.$relationName.':get'
                            ) ??
                                data_get(static::$macros, $relationName.':get'));
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
                            $related = self::relatedClass($model, $relationName);
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
                    });
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
        if ($model instanceof HasJsonDataRelations) {
            self::listen($model);
            self::forgetPending($model, $key);
            $rawStored = $model->getAttributes()[$key] ?? null;
            $stored = is_string($rawStored) && $rawStored !== '' ? json_decode($rawStored, true) : null;

            foreach (self::relations($model, $key, $value, $attributes) as $relation) {
                foreach (self::paths($relation) as $pattern) {
                    $recursive = self::isRecursive($pattern);
                    $value = Data::reducePaths([$pattern], $value, function (
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
                        if (self::isAcceptedReference($model, $item, $relationName)) {
                            return $newValue;
                        }
                        if ($recursive && ! self::isModelOrEntity($item)) {
                            return $newValue;
                        }
                        $beforeSet =
                            data_get($relation, 'before_set') ??
                            (data_get(
                                static::$macros,
                                get_class($model).':'.$relationName.':before_set'
                            ) ??
                                data_get(static::$macros, $relationName.':before_set'));
                        if (isset($beforeSet)) {
                            $item = $beforeSet($item, $path, $model, $relation, $relationName);
                        }
                        $setter =
                            data_get($relation, 'set') ??
                            (data_get(
                                static::$macros,
                                get_class($model).':'.$relationName.':set'
                            ) ??
                                data_get(static::$macros, $relationName.':set'));
                        if (isset($setter)) {
                            $newItem = $setter($item, $path, $model, $relation, $relationName);
                            data_set($newValue, $path, $newItem);

                            return $newValue;
                        }
                        if (is_null($item) || self::isAcceptedReference($model, $item, $relationName)) {
                            data_set($newValue, $path, $item);

                            return $newValue;
                        }

                        if (self::defersPreparing($model)) {
                            self::addPending($model, $key, $path, $item, $relation, $relationName);
                            $previous = data_get($stored, $path);
                            data_set($newValue, $path, self::isAcceptedReference($model, $previous, $relationName) ? $previous : null);

                            return $newValue;
                        }

                        data_set($newValue, $path, self::prepareReference($model, $key, $path, $item, $relation, $relationName));

                        return $newValue;
                    });
                }
            }
        }

        if ($model instanceof HasJsonDataColumnExtract) {
            return array_merge(
                [$key => ! is_null($value) ? json_encode($value, JSON_THROW_ON_ERROR) : null],
                self::extractedColumns($model, $key, $value, $attributes),
            );
        }

        return ! is_null($value) ? json_encode($value, JSON_THROW_ON_ERROR) : null;
    }

    /**
     * The values of a JSON attribute that the model copies into columns.
     *
     * @param  array<array-key, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected static function extractedColumns(HasJsonDataColumnExtract $model, string $key, mixed $value, array $attributes): array
    {
        $columns = $model->getJsonDataColumnExtract($key, $value, $attributes);
        $extracted = [];
        foreach (is_array($columns) ? $columns : [] as $path => $column) {
            if (is_string($column)) {
                $extracted[$column] = data_get($value, (string) $path);
            }
        }

        return $extracted;
    }

    /**
     * The relations a model declares for a JSON attribute, normalized.
     *
     * @param  array<array-key, mixed>  $attributes
     * @return list<array<string, mixed>>
     */
    protected static function relations(HasJsonDataRelations $model, string $key, mixed $value, array $attributes): array
    {
        $declared = $model->getJsonDataRelations($key, $value, $attributes);
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
            if ($model instanceof HasJsonDataColumnExtract) {
                $attributes = array_merge($attributes, self::extractedColumns($model, $key, $value, $model->getAttributes()));
            }
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
        /** @var array<string, array{entry: JsonReferenceEntry, relation: array<string, mixed>, relationName: string, ownerClass: class-string<Model>, attribute: string, identifiers: list<string>, owners: list<array{Model, mixed, string}>}> $groups */
        $groups = [];
        /** @var array<class-string<Model>, array{models: array<int, Model>, relations: array<string, true>}> $eager */
        $eager = [];

        foreach ($models as $model) {
            if (! $model instanceof Model || ! $model instanceof HasJsonDataRelations) {
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
                            'relationName' => (string) $entry->relation,
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
        if ($model instanceof HasJsonDataRelations) {
            foreach (self::castAttributes($model, $attributes) as $attribute) {
                foreach (self::storedReferences($model, $attribute) as [$entry]) {
                    $entries[] = $entry;
                }
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
     * The references that a relation accepts at its declared paths, in a model's stored
     * JSON attribute: mapped keys of the related model, and, outside recursive paths, the
     * key named after the relation when it isn't mapped.
     *
     * @return list<array{JsonReferenceEntry, array<string, mixed>}>
     */
    protected static function storedReferences(HasJsonDataRelations&Model $model, string $attribute): array
    {
        $raw = $model->getAttributes()[$attribute] ?? null;
        $value = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (! is_array($value)) {
            return [];
        }

        $references = [];
        foreach (self::relations($model, $attribute, $value, $model->getAttributes()) as $relation) {
            $relationName = self::relationName($model, $relation, null, null);
            $related = self::relatedClass($model, $relationName);
            foreach (self::paths($relation) as $pattern) {
                foreach (Data::matchingPaths([$pattern], $value) as $path) {
                    if (! is_string($path) && ! is_int($path)) {
                        continue;
                    }
                    $item = data_get($value, (string) $path);
                    $entry = is_string($item) ? JsonData::parse($item) : null;
                    if (is_null($entry)) {
                        continue;
                    }
                    $accepted = $entry->isMapped()
                        ? ! is_null($related) && (bool) $entry->target?->accepts($related)
                        : ! self::isRecursive($pattern) && $entry->key === $relationName && ! is_null($related);
                    if ($accepted) {
                        $references[] = [
                            new JsonReferenceEntry($entry->key, $entry->identifier, $entry->target, $attribute, (string) $path, $relationName),
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
     * @param  list<string>|null  $only  The JSON attributes whose relations are synced, or every one.
     */
    public static function syncRelations($model, ?array $only = null)
    {
        // Only models have casts and relations to sync
        if (! ($model instanceof HasJsonDataRelations) || ! ($model instanceof Model)) {
            return;
        }

        $castsWithRelations = self::castAttributes($model);

        $relationsMap = [];
        $declaredIn = [];
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
                $declaredIn[$relationName][] = (string) $key;
                $relationsMap[$relationName] = [
                    'relation' => $relation,
                    'ids' => array_values(array_unique(
                        array_merge(
                            $relationsMap[$relationName]['ids'] ?? [],
                            self::relatedKeys($model, $relationName, self::paths($relation), $value),
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
            } elseif (self::syncsMediasByHandle($model, $relationName) && method_exists($model, 'syncMedias')) {
                // Only the rows of the JSON references: the media attached otherwise stay.
                // Media keys are integers; a reference that isn't one can't be a media.
                $mediaIds = [];
                foreach ($ids as $id) {
                    if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                        $mediaIds[] = (int) $id;
                    }
                }
                $model->syncMedias([self::MEDIAS_HANDLE => $mediaIds]);
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
        if (count($relationsMap) > 0 && method_exists($model, 'syncMedias')) {
            $model->unsetRelation('medias');
        }
        unset(self::resolvedStore()[$model]);

        return $relationsMap;
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
     * relation when it isn't mapped.
     *
     * @param  array<int, string>  $paths
     * @param  array<array-key, mixed>  $data
     * @return list<mixed>
     */
    protected static function relatedKeys(Model $model, string $relationName, array $paths, array $data): array
    {
        $related = self::relatedClass($model, $relationName);
        $keys = [];
        $byKey = [];
        foreach ($paths as $pattern) {
            foreach (Data::matchingPaths([$pattern], $data) as $path) {
                $item = data_get($data, $path);
                $entry = is_string($item) ? JsonData::parse($item) : null;
                if (is_null($entry)) {
                    continue;
                }
                if ($entry->isMapped()) {
                    if (! is_null($related) && $entry->target?->accepts($related)) {
                        $byKey[$entry->key][] = $entry->identifier;
                    }
                } elseif (! self::isRecursive($pattern) && $entry->key === $relationName) {
                    $keys[] = $entry->identifier;
                }
            }
        }

        foreach ($byKey as $key => $identifiers) {
            $target = JsonData::target($key);
            if (is_null($target) || is_null($related)) {
                continue;
            }
            $relatedModel = new $related;
            if ($target->column() === $relatedModel->getKeyName()) {
                array_push($keys, ...$identifiers);

                continue;
            }
            $found = $relatedModel->newQuery()
                ->whereIn($target->column(), array_values(array_unique($identifiers)))
                ->pluck($relatedModel->getKeyName(), $target->column());
            foreach ($identifiers as $identifier) {
                if ($found->has($identifier)) {
                    $keys[] = $found->get($identifier);
                }
            }
        }

        return array_values(array_unique($keys, SORT_REGULAR));
    }

    /**
     * Whether the relation is the `medias()` of laravel-mediatheque 2's HasMedias, whose
     * pivot holds the usages of every media: the references of JSON data are synced under
     * their own handle, so that the media attached otherwise (`attachMedia()`) stay
     * (laravel-mediatheque's docs/decisions/0020).
     */
    protected static function syncsMediasByHandle(Model $model, mixed $relationName): bool
    {
        return $relationName === 'medias'
            && in_array('Folklore\\Mediatheque\\Concerns\\HasMedias', class_uses_recursive($model), true);
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
                    return $existing['relation'] === $relation['relation'] &&
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

    public static function getPathFromItem($item, $pathPrefix): ?string
    {
        $id = to_id($item);
        if (! empty($id)) {
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
            ! ($model instanceof HasJsonDataRelations) ||
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
        foreach (Data::matchingPaths($paths, $storedValue) as $path) {
            $reference = data_get($storedValue, $path);
            if (! is_null(self::getRelationAndIdFromPath($reference)) && is_null(data_get($value, $path))) {
                data_set($value, $path, $reference);
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
     * @return array<string, Model|null> By key, handler and identifier (memoKey())
     */
    protected static function resolveMappedReferences(Model $model, string $key, mixed $raw, mixed $value, array $relations): array
    {
        $memo = self::resolvedStore()[$model][$key] ?? null;
        $resolved = ! is_null($memo) && $memo['raw'] === $raw ? $memo['models'] : [];

        /** @var array<string, array{entry: JsonReferenceEntry, relation: array<string, mixed>, relationName: string, identifiers: list<string>}> $missing */
        $missing = [];
        if (is_array($value)) {
            foreach ($relations as $relation) {
                $relationName = self::relationName($model, $relation, null, null);
                foreach (self::paths($relation) as $pattern) {
                    foreach (Data::matchingPaths([$pattern], $value) as $path) {
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
                relation: $group['relationName'],
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
            return self::unpreparable($model, $relationName);
        }

        $context = new ReferenceContext(
            operation: ReferenceOperation::Write,
            key: $writeKey,
            target: $target,
            ownerClass: $model::class,
            owner: $model,
            attribute: $key,
            path: $path,
            relation: $relationName,
        );
        $handler = self::handler($target, $relation);
        $prepared = $handler->prepare($value, $context);
        if (is_null($prepared)) {
            return self::unpreparable($model, $relationName);
        }
        if (! $handler->allows($prepared, $context)) {
            throw ReferenceNotAllowed::for($prepared, $context);
        }
        $identifier = $target->identifierOf($prepared);

        return is_null($identifier) ? self::unpreparable($model, $relationName) : $writeKey.'://'.$identifier;
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

    protected static function unpreparable(Model $model, string $relationName): ?string
    {
        if (JsonData::enforcesMap()) {
            throw new InvalidJsonReference("A value at a path of [{$relationName}] on [".$model::class.'] is not something its reference handler takes.');
        }

        return null;
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
     * model is the related model, or, without a map, the relation's own name.
     */
    protected static function isAcceptedReference(Model $model, mixed $item, mixed $relationName): bool
    {
        if (! is_string($item) || ! is_string($relationName)) {
            return false;
        }
        $entry = JsonData::parse($item);
        if (is_null($entry)) {
            return false;
        }
        if (! $entry->isMapped()) {
            return $entry->key === $relationName;
        }
        $related = self::relatedClass($model, $relationName);

        return ! is_null($related) && (bool) $entry->target?->accepts($related);
    }

    protected static function isModelOrEntity(mixed $item): bool
    {
        return $item instanceof Model || $item instanceof Entity || $item instanceof HasModel || $item instanceof ToEntity;
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
