<?php

namespace Folklore\JsonData\Schema;

use Folklore\JsonData\Exceptions\InvalidJsonData;

/**
 * An object whose schema is chosen by the value of one of its properties, the
 * discriminator (`type`): each value has its own object, a variant. It is stored as the
 * variant's object, with the discriminator first. A variant allows additional keys or not,
 * as its object does.
 */
class UnionType extends Type
{
    /**
     * @var array<string, ObjectType>
     */
    protected array $variants = [];

    /**
     * @param  array<array-key, mixed>  $variants  The object of each value of the discriminator.
     */
    public function __construct(
        protected string $discriminator,
        array $variants,
    ) {
        if ($discriminator === '' || str_contains($discriminator, '.') || str_contains($discriminator, '*')) {
            throw InvalidJsonData::invalidSchema("[{$discriminator}] is not a property name: it is not empty, and has no `.` or `*`.");
        }
        if (count($variants) === 0) {
            throw InvalidJsonData::invalidSchema("A union on [{$discriminator}] has no variant.");
        }
        foreach ($variants as $value => $variant) {
            if (! $variant instanceof ObjectType) {
                throw InvalidJsonData::invalidSchema("The variant [{$value}] of a union is not an object.");
            }
            if (! is_null($variant->getProperty($discriminator))) {
                throw InvalidJsonData::invalidSchema("The variant [{$value}] declares the discriminator [{$discriminator}]: the union writes it.");
            }
            $this->variants[(string) $value] = $variant;
        }
    }

    public function getDiscriminator(): string
    {
        return $this->discriminator;
    }

    /**
     * @return array<string, ObjectType>
     */
    public function getVariants(): array
    {
        return $this->variants;
    }

    /**
     * The variant of a value of the discriminator, or null when the union has none for it.
     */
    public function getVariant(mixed $value): ?ObjectType
    {
        return is_string($value) || is_int($value) ? ($this->variants[(string) $value] ?? null) : null;
    }
}
