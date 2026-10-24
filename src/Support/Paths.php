<?php

namespace Folklore\JsonData\Support;

use Closure;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Path patterns over decoded JSON data, as the cast's declarations give them
 * (docs/json-data.md, "Paths"): `logo` and `share.image` match that exact path, `*` one
 * segment (`image.*` matches `image.fr`), and `**` any number of segments (`body.**`
 * matches every value under `body`).
 *
 * @internal
 */
final class Paths
{
    /**
     * Calls the reducer for each path of the data that matches one of the patterns, in
     * turn, with the data so far, the path and its value in the data so far. The paths
     * are those of the data it is given: a value the reducer adds isn't visited.
     *
     * @param  array<array-key, string>  $patterns
     * @param  Closure(mixed, int|string, mixed): mixed  $reducer
     * @return array<array-key, mixed>
     */
    public static function reduce(array $patterns, mixed $data, Closure $reducer): array
    {
        foreach (self::matching($patterns, $data) as $path) {
            $data = $reducer($data, $path, data_get($data, $path));
        }

        return (array) $data;
    }

    /**
     * The paths of the data that match one of the patterns, in the order of the data,
     * each once. A path is a key at the top level, which may be an integer, or keys
     * joined with dots. Branches are paths too: `body.**` matches `body.content` and
     * `body.content.0`.
     *
     * @param  array<array-key, string>  $patterns
     * @return list<int|string>
     */
    public static function matching(array $patterns, mixed $data): array
    {
        $expressions = array_map(self::expression(...), array_values($patterns));
        $matching = [];
        foreach (array_keys(self::flatten($data)) as $path) {
            foreach ($expressions as $expression) {
                if (preg_match($expression, (string) $path) === 1) {
                    $matching[] = $path;

                    break;
                }
            }
        }

        return $matching;
    }

    /**
     * The regular expression of a pattern: `**` matches any characters, `*` one segment
     * without a dot, and the rest of the pattern matches literally.
     */
    public static function expression(string $pattern): string
    {
        $quoted = preg_quote(str_replace(['**', '*'], ['__full_wildcard__', '__wildcard__'], $pattern), '/');

        return '/^'.str_replace(['__full_wildcard__', '__wildcard__'], ['.*?', '[^\.]+'], $quoted).'$/';
    }

    /**
     * Every path of the data, with its value, branches included: a branch, a non-empty
     * array, maps to null, and its children follow it. An arrayable value, such as a
     * model or a collection, is walked as its array. Data that isn't iterable has no
     * paths.
     *
     * @return array<int|string, mixed>
     */
    private static function flatten(mixed $data, string $prefix = ''): array
    {
        if (! is_iterable($data)) {
            return [];
        }

        $paths = [];
        foreach ($data as $key => $value) {
            if (! is_int($key) && ! is_string($key)) {
                continue;
            }
            if ($value instanceof Arrayable) {
                $value = $value->toArray();
            }
            $path = $prefix.$key;
            if (is_array($value) && count($value) > 0) {
                $paths[$path] = null;
                // Not array_merge(), which would renumber the integer keys of an object
                foreach (self::flatten($value, $path.'.') as $child => $childValue) {
                    $paths[$child] = $childValue;
                }
            } else {
                $paths[$path] = $value;
            }
        }

        return $paths;
    }
}
