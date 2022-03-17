<?php

namespace Folklore\Eloquent;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Folklore\Support\Data;
use Folklore\Contracts\Resources\Resource;
use Folklore\Contracts\Eloquent\HasJsonDataRelations;
use Folklore\Contracts\Eloquent\HasJsonDataColumnExtract;
use ReflectionClass;

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
        $value = json_decode($value, true);

        if ($model instanceof HasJsonDataRelations) {
            $relations = $model->getJsonDataRelations($key, $value, $attributes);
            $pathsByRelations = self::getPathsByRelations($relations);
            foreach ($pathsByRelations as $relation => $paths) {
                $value = Data::reducePaths($paths, $value, function (
                    $newValue,
                    $path,
                    $itemPath
                ) use ($relation, $model) {
                    $id = self::getIdFromPath($itemPath, $relation);
                    $item = $model->{$relation}->find($id);
                    data_set($newValue, $path, $item);
                    return $newValue;
                });
            }
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
            $relations = $model->getJsonDataRelations($key, $value, $attributes);
            $pathsByRelations = self::getPathsByRelations($relations);

            foreach ($pathsByRelations as $relation => $paths) {
                $value = Data::reducePaths($paths, $value, function ($newValue, $path, $item) use (
                    $relation
                ) {
                    data_set($newValue, $path, self::getPathFromItem($item, $relation));
                    return $newValue;
                });
            }
        }

        if ($model instanceof HasJsonDataColumnExtract) {
            $columnsExtract = $model->getJsonDataColumnExtract($key, $value, $attributes);
            $return = [
                $key => json_encode($value),
            ];
            foreach ($columnsExtract as $path => $column) {
                $return[$column] = data_get($value, $path);
            }

            return $return;
        }

        return json_encode($value);
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
            $value = json_decode(data_get($attributes, $key), true);
            $relations = $model->getJsonDataRelations($key, $value, $attributes);
            $pathsByRelations = self::getPathsByRelations($relations);
            foreach ($pathsByRelations as $relation => $paths) {
                $ids = self::getRelationIds($paths, $value, $relation);
                data_set(
                    $idsByRelations,
                    $relation,
                    collect(data_get($idsByRelations, $relation, []))
                        ->merge($ids)
                        ->unique()
                        ->toArray()
                );
            }
        }

        foreach ($idsByRelations as $relation => $ids) {
            $model->{$relation}()->sync($ids);
        }
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

    protected static function getPathsByRelations($relations)
    {
        return collect($relations)->reduce(function ($map, $relation, $path) {
            data_set(
                $map,
                $relation,
                collect(data_get($map, $relation, []))
                    ->push($path)
                    ->unique()
                    ->toArray()
            );
            return $map;
        }, []);
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
        if (
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
        } elseif (is_array($item)) {
            return data_get($item, 'id');
        } elseif ($item instanceof Model) {
            return $item->getKey();
        } elseif ($item instanceof Resource) {
            return $item->id();
        }
        return null;
    }
}
