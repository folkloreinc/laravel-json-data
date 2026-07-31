<?php

namespace Folklore\Eloquent;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Folklore\Support\Data;
use Folklore\Contracts\Eloquent\HasJsonDataRelations;
use Folklore\Contracts\Eloquent\HasJsonDataColumnExtract;
use ReflectionClass;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Traits\Macroable;

class JsonDataCast implements CastsAttributes
{
    use Macroable;

    /**
     * Cast the given value.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @param  mixed  $value
     * @param  array  $attributes
     * @return array
     */
    public function get($model, $key, $value, $attributes)
    {
        $value = !empty($value) ? json_decode($value, true) : null;

        if ($model instanceof HasJsonDataRelations) {
            $value = self::normalizeJsonDataRelations(
                $model->getJsonDataRelations($key, $value, $attributes)
            )->reduce(function ($value, $relation) use ($model) {
                $paths = $relation['path'];
                return Data::reducePaths($paths, $value, function ($newValue, $path, $item) use (
                    $model,
                    $relation
                ) {
                    if (
                        isset($relation['skip']) &&
                        is_callable($relation['skip']) &&
                        call_user_func($relation['skip'], $item, $path, 'get', $model, $relation)
                    ) {
                        return $newValue;
                    }
                    $relationName = is_callable($relation['relation'])
                        ? call_user_func($relation['relation'], $item, $path, $model, $relation)
                        : $relation['relation'];
                    $getter =
                        data_get($relation, 'get') ??
                        (data_get(
                            static::$macros,
                            get_class($model) . ':' . $relationName . ':get'
                        ) ??
                            data_get(static::$macros, $relationName . ':get'));
                    if (isset($getter)) {
                        $newItem = $getter($item, $path, $model, $relation);
                        data_set($newValue, $path, $newItem);
                        return $newValue;
                    }
                    $lazy = data_get($relation, 'lazy', false);
                    if (!is_string($item)) {
                        return $newValue;
                    }
                    list($relation, $id) = self::getRelationAndIdFromPath($item) ?? [null, null];
                    if (empty($relation) || empty($id)) {
                        return $newValue;
                    }
                    $relationClass = $model->{$relation}();
                    if ($relationClass instanceof BelongsTo) {
                        $newItem = $model->{$relation};
                    } else {
                        $newItem =
                            !$lazy || $model->relationLoaded($relation)
                                ? $model->{$relation}->find($id)
                                : null;
                    }

                    data_set($newValue, $path, $newItem);
                    return $newValue;
                });
            }, $value);
        }

        return $value;
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @param  array  $value
     * @param  array  $attributes
     * @return string
     */
    public function set($model, $key, $value, $attributes)
    {
        if ($model instanceof HasJsonDataRelations) {
            $value = self::normalizeJsonDataRelations(
                $model->getJsonDataRelations($key, $value, $attributes)
            )->reduce(function ($value, $relation) use ($model) {
                return Data::reducePaths($relation['path'], $value, function (
                    $newValue,
                    $path,
                    $item
                ) use ($model, $relation) {
                    if (
                        isset($relation['skip']) &&
                        is_callable($relation['skip']) &&
                        call_user_func($relation['skip'], $item, $path, 'set', $model, $relation)
                    ) {
                        return $newValue;
                    }
                    if (is_array($item) && array_is_list($item)) {
                        return $newValue;
                    }
                    $relationName = is_callable($relation['relation'])
                        ? call_user_func($relation['relation'], $item, $path, $model, $relation)
                        : $relation['relation'];
                    $beforeSet =
                        data_get($relation, 'before_set') ??
                        (data_get(
                            static::$macros,
                            get_class($model) . ':' . $relationName . ':before_set'
                        ) ??
                            data_get(static::$macros, $relationName . ':before_set'));
                    if (isset($beforeSet)) {
                        $item = $beforeSet($item, $path, $model, $relation, $relationName);
                    }
                    $setter =
                        data_get($relation, 'set') ??
                        (data_get(
                            static::$macros,
                            get_class($model) . ':' . $relationName . ':set'
                        ) ??
                            data_get(static::$macros, $relationName . ':set'));
                    if (isset($setter)) {
                        $newItem = $setter($item, $path, $model, $relation, $relationName);
                        data_set($newValue, $path, $newItem);
                        return $newValue;
                    }
                    $newItem = self::getPathFromItem($item, $relationName);
                    data_set($newValue, $path, $newItem);
                    return $newValue;
                });
            }, $value);
        }

        if ($model instanceof HasJsonDataColumnExtract) {
            $columnsExtract = $model->getJsonDataColumnExtract($key, $value, $attributes);
            $return = [
                $key => !is_null($value) ? json_encode($value) : null,
            ];
            foreach ($columnsExtract as $path => $column) {
                $return[$column] = data_get($value, $path);
            }

            return $return;
        }

        return !is_null($value) ? json_encode($value) : null;
    }

    public static function syncRelations($model)
    {
        if (!($model instanceof HasJsonDataRelations)) {
            return;
        }

        $castsWithRelations = collect($model->getCasts())
            ->filter(function ($castType) {
                if (!class_exists($castType)) {
                    return false;
                }
                if ($castType === self::class) {
                    return true;
                }

                $reflectionClass = new ReflectionClass($castType);
                return $reflectionClass->isSubclassOf(self::class);
            })
            ->keys()
            ->values();

        $relationsMap = [];
        $attributes = $model->getAttributes();
        foreach ($castsWithRelations as $key) {
            $attributeValue = data_get($attributes, $key);
            $value = !empty($attributeValue) ? json_decode($attributeValue, true) : null;
            if (!is_array($value)) {
                continue;
            }
            $normalizedRelations = self::normalizeJsonDataRelations(
                $model->getJsonDataRelations($key, $value, $attributes)
            );
            $relationsMap = $normalizedRelations
                ->filter(function ($relation) {
                    return data_get($relation, 'sync', true);
                })
                ->reduce(function ($relationsMap, $relation) use ($model, $value) {
                    $relationName = is_callable($relation['relation'])
                        ? call_user_func($relation['relation'], null, null, $model, $relation)
                        : $relation['relation'];
                    if (!isset($relationsMap[$relationName])) {
                        $relationsMap[$relationName] = [
                            'relation' => $relation,
                            'ids' => [],
                        ];
                    }
                    $paths = $relation['path'];
                    $map = self::getRelationsAndsIdsFromPaths($paths, $value);
                    foreach ($map as $relationName => $ids) {
                        $relationsMap[$relationName] = [
                            'relation' => $relation,
                            'ids' => collect(data_get($relationsMap, $relationName . '.ids', []))
                                ->merge($ids)
                                ->unique()
                                ->values()
                                ->toArray(),
                        ];
                    }
                    return $relationsMap;
                }, $relationsMap);
        }

        foreach ($relationsMap as $relationName => $item) {
            $relationClass = $model->{$relationName}();
            $ids = $item['ids'];
            $relation = $item['relation'];
            $delete = data_get($relation, 'delete', false);
            if ($delete) {
                $relationClass->whereNotIn('id', $ids)->delete();
            }
            if (isset($relation['sync'])) {
                $relation['sync']($relationClass, $ids, $relation);
            } elseif ($relationClass instanceof BelongsToMany) {
                $relationClass->sync($ids);
            } elseif ($relationClass instanceof BelongsTo && sizeof($ids) > 0) {
                $relationClass->associate($ids[0]);
            } elseif ($relationClass instanceof BelongsTo && sizeof($ids) === 0) {
                $relationClass->dissociate();
            } elseif ($relationClass instanceof MorphOneOrMany && sizeof($ids) > 0) {
                $relationClass
                    ->getRelated()
                    ->newQuery()
                    ->whereIn('id', $ids)
                    ->update([
                        $relationClass->getMorphType() => $relationClass->getMorphClass(),
                        $relationClass->getForeignKeyName() => $relationClass->getParentKey(),
                    ]);
            } elseif ($relationClass instanceof HasOneOrMany && sizeof($ids) > 0) {
                $relationClass
                    ->getRelated()
                    ->newQuery()
                    ->whereIn('id', $ids)
                    ->update([
                        $relationClass->getForeignKeyName() => $relationClass->getParentKey(),
                    ]);
            }
        }

        return $relationsMap;
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

    protected static function getRelationsAndsIdsFromPaths($paths, array $data)
    {
        $ids = Data::matchingPaths($paths, $data)->reduce(function ($map, $path) use ($data) {
            $itemPath = data_get($data, $path);
            list($relation, $id) = self::getRelationAndIdFromPath($itemPath) ?? [null, null];
            return !empty($relation) && !empty($id)
                ? array_merge($map, [
                    $relation => collect(data_get($map, $relation, []))
                        ->push($id)
                        ->unique()
                        ->values()
                        ->toArray(),
                ])
                : $map;
        }, []);

        return $ids;
    }

    public static function getPathFromItem($item, $pathPrefix): ?string
    {
        $id = to_id($item);
        if (!empty($id)) {
            return $pathPrefix . '://' . $id;
        }
        return null;
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
}
