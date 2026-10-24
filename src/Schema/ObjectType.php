<?php

namespace Folklore\JsonData\Schema;

use Folklore\JsonData\Exceptions\InvalidJsonData;
use Folklore\JsonData\JsonData;

/**
 * An object with the given properties, stored as a JSON object. The root of a column's
 * schema is one (`HasJsonDataSchema`).
 */
class ObjectType extends Type
{
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
}
