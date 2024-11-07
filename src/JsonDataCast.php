<?php

namespace Folklore\Eloquent;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Folklore\Support\Data;
use Folklore\Contracts\Resources\Resource;
use Folklore\Contracts\Eloquent\HasJsonDataRelations;
use Folklore\Contracts\Eloquent\HasJsonDataColumnExtract;
use ReflectionClass;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Arr;

class JsonDataCast implements CastsAttributes
{
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
            )->reduce(function ($value, $item) use ($model) {
                $paths = $item['path'];
                $lazy = data_get($item, 'lazy', false);
                return Data::reducePaths($paths, $value, function (
                    $newValue,
                    $path,
                    $itemPath
                ) use ($model, $lazy) {
                    if (!is_string($itemPath)) {
                        return $newValue;
                    }
                    list($relation, $id) = self::getRelationAndIdFromPath($itemPath) ?? [
                        null,
                        null,
                    ];
                    if (empty($relation) || empty($id)) {
                        return $newValue;
                    }
                    $relationClass = $model->{$relation}();
                    if ($relationClass instanceof BelongsTo) {
                        $item = $model->{$relation};
                    } else {
                        $item =
                            !$lazy || $model->relationLoaded($relation)
                                ? $model->{$relation}->find($id)
                                : null;
                    }

                    data_set($newValue, $path, $item);
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
            )->reduce(function ($value, $item) {
                $relation = $item['relation'];
                $paths = $item['path'];
                return Data::reducePaths($paths, $value, function ($newValue, $path, $item) use (
                    $relation
                ) {
                    if (is_array($item) && array_is_list($item)) {
                        return $newValue;
                    }
                    $relationName = is_callable($relation)
                        ? call_user_func($relation, $item, $path)
                        : $relation;
                    $itemPath = self::getPathFromItem($item, $relationName);
                    data_set($newValue, $path, $itemPath);
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
                ->filter(function ($item) {
                    return data_get($item, 'sync', true);
                })
                ->reduce(function ($relationsMap, $item) use ($value) {
                    $relationName = is_callable($item['relation'])
                        ? call_user_func($item['relation'], null, null)
                        : $item['relation'];
                    if (!isset($relationsMap[$relationName])) {
                        $relationsMap[$relationName] = [
                            'relation' => $item,
                            'ids' => [],
                        ];
                    }
                    $paths = $item['path'];
                    $map = self::getRelationsAndsIdsFromPaths($paths, $value);
                    foreach ($map as $relation => $ids) {
                        $relationsMap[$relation] = [
                            'relation' => $item,
                            'ids' => collect(data_get($relationsMap, $relation . '.ids', []))
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

    public static function normalizeJsonDataRelations($relations): Collection
    {
        $relations = collect($relations)
            ->map(function ($relation, $path) {
                return is_string($relation)
                    ? ['relation' => $relation, 'path' => $path, 'lazy' => false]
                    : array_merge(['path' => $path], $relation);
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

    protected static function getPathFromItem($item, $pathPrefix): ?string
    {
        $id = self::getIdFromItem($item);
        if (!empty($id)) {
            return $pathPrefix . '://' . $id;
        }
        return null;
    }

    protected static function getRelationAndIdFromPath($path): ?array
    {
        if (empty($path)) {
            return null;
        }
        if (is_string($path) && preg_match('/^([^:]+):\/\/(.*)$/', $path, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }
        return null;
    }

    protected static function getIdFromItem($item)
    {
        if (is_numeric($item) || is_string($item)) {
            return $item;
        } elseif (is_array($item) && isset($item['id'])) {
            return $item['id'];
        } elseif ($item instanceof Model) {
            return $item->getKey();
        } elseif ($item instanceof Resource) {
            return $item->id();
        }
        return null;
    }
}
