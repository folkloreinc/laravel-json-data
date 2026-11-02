<?php

namespace Folklore\JsonData\Tests\Feature\Fixtures;

use Folklore\JsonData\Schema\ObjectType;
use Folklore\JsonData\Tests\DeclaresJsonData;

/**
 * An offer without a schema, whose `data` is declared through the hook, as a model of
 * laravel-folklore 1.x: the twin of an Offer with a schema, to compare the two.
 */
class LegacyOffer extends Offer implements DeclaresJsonData
{
    /**
     * The declarations of `data` in a test.
     *
     * @var array<string, mixed>
     */
    public static array $references = [];

    public function jsonDataSchema(string $attribute): ?ObjectType
    {
        return null;
    }

    public function jsonDataReferences(string $attribute, mixed $value, array $attributes): array
    {
        return $attribute === 'data' ? static::$references : [];
    }

    public function jsonDataColumns(string $attribute, mixed $value, array $attributes): array
    {
        return [];
    }

    public function getMorphClass()
    {
        return Offer::class;
    }
}
