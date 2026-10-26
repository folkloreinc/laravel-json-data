<?php

namespace Folklore\JsonData\Schema;

use Folklore\JsonData\Contracts\LocaleResolver;
use Folklore\JsonData\Exceptions\InvalidJsonData;
use Folklore\JsonData\JsonDataCast;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;

/**
 * Puts a flat input into the JSON attributes of a model that have a schema
 * (`JsonData::fill()`, docs/json-data.md, "Filling").
 *
 * @internal
 */
final class Filler
{
    private ?LocaleResolver $locales = null;

    /**
     * @param  array<array-key, mixed>  $input
     */
    public function fill(Model $model, array $input): void
    {
        $values = [];
        $consumed = [];
        $strict = false;
        foreach (JsonDataCast::castAttributesOf($model) as $attribute) {
            $schema = Schemas::for($model, $attribute);
            if (is_null($schema)) {
                continue;
            }
            $strict = $strict || ! $schema->allowsAdditional();

            $given = [];
            foreach (Compiler::compile($schema)['inputs'] as $name => [$segments, $node]) {
                if (array_key_exists($name, $input)) {
                    $given[] = [$segments, $node, $input[$name]];
                    $consumed[$name] = true;
                }
            }
            // The defaults are written into the columns the input fills, and those of a new model
            if (count($given) === 0 && $model->exists) {
                continue;
            }

            $current = JsonDataCast::storedWithPending($model, $attribute);
            $value = $current ?? [];
            foreach ($given as [$segments, $node, $item]) {
                $path = $attribute.'.'.implode('.', $segments);
                $value = self::put($value, $segments, $this->value($node, self::get($value, $segments), $item, $path));
            }
            $value = $this->withDefaults($schema, $value, $attribute);
            if (count($given) > 0 || $value !== ($current ?? [])) {
                $values[$attribute] = $value;
            }
        }

        $unknown = [];
        foreach (array_keys($input) as $key) {
            if (! isset($consumed[$key]) && ! $model->isFillable((string) $key)) {
                $unknown[] = (string) $key;
            }
        }
        if ($strict && count($unknown) > 0) {
            throw InvalidJsonData::unknownKeys($model::class, $unknown);
        }

        foreach ($values as $attribute => $value) {
            $model->setAttribute($attribute, $value);
        }
    }

    /**
     * The value to store at a node's path, for the value given and the one stored there.
     * `null` clears the path. An object replaces the properties it gives and keeps the
     * others, a translatable node the locales it gives; any other value replaces the
     * stored one.
     */
    private function value(Type $node, mixed $current, mixed $given, string $path): mixed
    {
        if (is_null($given)) {
            return null;
        }

        if ($node instanceof ScalarType) {
            return $node->isTranslatable()
                ? $this->translated($node, $current, $given, $path)
                : $node->store($given);
        }

        if (! is_array($given)) {
            // Not the shape of the node: Json::rules() refuses it
            return $given;
        }

        if ($node instanceof ObjectType) {
            $value = is_array($current) ? $current : [];
            foreach ($given as $key => $item) {
                $key = (string) $key;
                $property = $node->getProperty($key);
                if (! is_null($property)) {
                    $value[$key] = $this->value($property, $value[$key] ?? null, $item, $path.'.'.$key);
                } elseif ($node->allowsAdditional()) {
                    $value[$key] = $item;
                } else {
                    throw InvalidJsonData::unknownKey($path.'.'.$key);
                }
            }

            return $value;
        }

        if ($node instanceof ListType || $node instanceof MapType) {
            $value = [];
            foreach ($given as $key => $item) {
                $value[$key] = $this->value($node->getOf(), null, $item, $path.'.'.$key);
            }

            return $node instanceof ListType ? array_values($value) : $value;
        }

        return $given;
    }

    /**
     * A translatable value: a string goes into the default locale, an object keyed by
     * locale replaces the locales it gives.
     *
     * @return array<array-key, mixed>
     */
    private function translated(ScalarType $node, mixed $current, mixed $given, string $path): array
    {
        $value = is_array($current) ? $current : [];
        if (! is_array($given)) {
            $value[$this->locales()->defaultLocale()] = $node->store($given);

            return $value;
        }

        $locales = $this->locales()->locales();
        foreach ($given as $locale => $item) {
            $locale = (string) $locale;
            if (! in_array($locale, $locales, true)) {
                throw InvalidJsonData::unknownLocale($path, $locale);
            }
            $value[$locale] = is_null($item) ? null : $node->store($item);
        }

        return $value;
    }

    /**
     * Writes the defaults of the paths that the value doesn't have, in its objects, and in
     * the objects of its lists and maps.
     */
    private function withDefaults(Type $node, mixed $value, string $path): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($node instanceof ObjectType) {
            foreach ($node->getProperties() as $name => $property) {
                if (array_key_exists($name, $value)) {
                    $value[$name] = $this->withDefaults($property, $value[$name], $path.'.'.$name);
                } elseif ($property->hasDefault()) {
                    $value[$name] = $this->withDefaults(
                        $property,
                        $this->value($property, null, $property->getDefault(), $path.'.'.$name),
                        $path.'.'.$name,
                    );
                }
            }
        } elseif ($node instanceof ListType || $node instanceof MapType) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->withDefaults($node->getOf(), $item, $path.'.'.$key);
            }
        }

        return $value;
    }

    private function locales(): LocaleResolver
    {
        return $this->locales ??= Container::getInstance()->make(LocaleResolver::class);
    }

    /**
     * @param  list<string>  $segments
     */
    private static function get(mixed $value, array $segments): mixed
    {
        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Sets the value at a path, making the objects on the way.
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $segments
     * @return array<array-key, mixed>
     */
    private static function put(array $data, array $segments, mixed $value): array
    {
        $segment = array_shift($segments);
        if (is_null($segment)) {
            return $data;
        }
        if (count($segments) === 0) {
            $data[$segment] = $value;

            return $data;
        }
        $child = $data[$segment] ?? [];
        $data[$segment] = self::put(is_array($child) ? $child : [], $segments, $value);

        return $data;
    }
}
