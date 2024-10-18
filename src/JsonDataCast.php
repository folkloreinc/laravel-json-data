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
                $relation = $item['relation'];
                $paths = $item['path'];
                $lazy = data_get($item, 'lazy', false);
                return Data::reducePaths($paths, $value, function (
                    $newValue,
                    $path,
                    $itemPath
                ) use ($relation, $model, $lazy) {
                    if (!is_string($itemPath)) {
                        return $newValue;
                    }
                    $id = self::getIdFromPath($itemPath, $relation);
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
                    $itemPath = self::getPathFromItem($item, $relation);
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

        $idsByRelations = [];
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
            $idsByRelations = $normalizedRelations
                ->filter(function ($item) {
                    return data_get($item, 'sync', true);
                })
                ->reduce(function ($idsByRelations, $item) use ($value) {
                    $relation = $item['relation'];
                    $paths = $item['path'];
                    $ids = self::getRelationIds($paths, $value, $relation);
                    data_set(
                        $idsByRelations,
                        $relation,
                        collect(data_get($idsByRelations, $relation, []))
                            ->merge($ids)
                            ->unique()
                            ->toArray()
                    );
                    return $idsByRelations;
                }, $idsByRelations);
        }

        foreach ($idsByRelations as $relation => $ids) {
            $relationClass = $model->{$relation}();
            $relation = $normalizedRelations->first(function ($item) use ($relation) {
                return $item['relation'] === $relation;
            });
            $delete = data_get($relation, 'delete', false);
            if ($delete) {
                $relationClass->whereNotIn('id', $ids)->delete();
            }
            if (isset($relation['sync'])) {
                $relation['sync']($relationClass, $ids, $relation);
            } else if ($relationClass instanceof BelongsToMany) {
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

        return $idsByRelations;
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

    protected static function getRelationIds($paths, array $data, $relation)
    {
        $ids = Data::matchingPaths($paths, $data)
            ->map(function ($path) use ($data, $relation) {
                return self::getIdFromPath(data_get($data, $path), $relation);
            })
            ->filter(function ($id) {
                return !is_null($id);
            })
            ->unique()
            ->values()
            ->toArray();

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

    protected static function getIdFromPath($path, $pathPrefix): ?string
    {
        if (empty($path)) {
            return null;
        }
        if (
            is_string($path) &&
            preg_match('/^' . preg_quote($pathPrefix . '://', '/') . '(.*)$/', $path, $matches) ===
                1
        ) {
            return $matches[1];
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
