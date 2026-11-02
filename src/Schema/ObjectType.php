<?php

namespace Folklore\JsonData\Schema;

use Folklore\JsonData\Exceptions\InvalidJsonData;
use Folklore\JsonData\JsonData;
use Folklore\JsonData\Schema\Concerns\HasPaths;

/**
 * An object with the given properties, stored as a JSON object. The root of a column's
 * schema is one (`HasJsonDataSchema`).
 */
class ObjectType extends Type
{
    use HasPaths;

    protected ?bool $additional = null;

    /**
     * @param  array<string, Type>  $properties
     */
    public function __construct(
        protected array $properties,
    ) {
        foreach (array_keys($properties) as $name) {
            if ($name === '' || str_contains($name, '.') || str_contains($name, '*')) {
                throw InvalidJsonData::invalidSchema("[{$name}] is not a property name: it is not empty, and has no `.` or `*`.");
            }
        }
    }

    /**
     * Whether keys outside the schema are kept. When they aren't, `JsonData::fill()`
     * refuses them with `InvalidJsonData`. Kept by default, and refused by default under
     * `JsonData::enforceMap()`.
     */
    public function additional(bool $additional = true): static
    {
        $this->additional = $additional;

        return $this;
    }

    public function allowsAdditional(): bool
    {
        return $this->additional ?? ! JsonData::enforcesMap();
    }

    /**
     * @return array<string, Type>
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    public function getProperty(string $name): ?Type
    {
        return $this->properties[$name] ?? null;
    }

    /**
     * A path under one of the object's properties is declared on that property.
     *
     * @param  list<string>  $segments
     */
    protected function checkPath(array $segments, string $path): void
    {
        if (array_key_exists($segments[0], $this->properties)) {
            throw InvalidJsonData::invalidSchema("[{$path}] starts with the property [{$segments[0]}]: declare it on that property.");
        }
    }
}
