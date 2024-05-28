<?php

namespace Folklore\Support;

use Illuminate\Support\Collection;
use Closure;
use Illuminate\Contracts\Support\Arrayable;

class Data
{
    public static function reducePaths($paths, $data, Closure $reducer): array
    {
        return (array) self::matchingPaths($paths, $data)->reduce(function ($newData, $path) use (
            $reducer
        ) {
            return $reducer($newData, $path, data_get($newData, $path));
        },
        $data);
    }

    public static function setPaths($data, $paths, $set)
    {
        return self::reducePaths($paths, $data, function ($value, $path, $pathValue) use ($set) {
            $newValue = $set instanceof Closure ? $set($pathValue, $path, $value) : $set;
            data_set($value, $path, $newValue);
            return $value;
        });
    }

    public static function matchingPaths($paths, $data): Collection
    {
        $pathPatterns = collect($paths)->map(function ($path) {
            return self::getPathPattern($path);
        });
        $dataPaths = array_keys(self::dot($data));

        return collect($dataPaths)->filter(function ($path) use ($pathPatterns) {
            return $pathPatterns->contains(function ($pattern) use ($path) {
                return preg_match($pattern, $path) === 1;
            });
        });
    }

    public static function getPathPattern(string $path): string
    {
        $pattern = preg_replace('/[*]{2}/', '__full_wildcard__', $path);
        $pattern = preg_replace('/[*]/', '__wildcard__', $pattern);
        $pattern = preg_quote($pattern, '/');
        $pattern = preg_replace('/__full_wildcard__/', '.*?', $pattern);
        $pattern = preg_replace('/__wildcard__/', '[^\.]+', $pattern);
        return '/^' . $pattern . '$/';
    }

    public static function dot($array, $prepend = '')
    {
        $results = [];
        if (is_null($array)) {
            return $results;
        }
        foreach ($array as $key => $value) {
            if ($value instanceof Arrayable) {
                $value = $value->toArray();
            }
            // prettier-ignore
            if ((is_array($value) && !empty($value)) ||
                ($value instanceof Collection && $value->count() > 0)
            ) {
                $results[$prepend . $key] = null;
                $results = array_merge($results, self::dot($value, $prepend . $key . '.'));
            } else {
                $results[$prepend . $key] = $value;
            }
        }

        return $results;
    }
}
