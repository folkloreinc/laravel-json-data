<?php

namespace Folklore\Support;

use Illuminate\Support\Collection;
use Closure;

class Data
{
    public static function reducePaths($paths, array $data, Closure $reducer): array
    {
        return (array) self::matchingPaths($paths, $data)->reduce(function ($newData, $path) use (
            $reducer
        ) {
            return $reducer($newData, $path, data_get($newData, $path));
        },
        $data);
    }

    public static function matchingPaths($paths, array $data): Collection
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
        foreach ($array as $key => $value) {
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
