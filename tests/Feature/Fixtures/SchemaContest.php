<?php

namespace Folklore\JsonData\Tests\Feature\Fixtures;

use Closure;
use Folklore\JsonData\Contracts\HasJsonDataSchema;
use Folklore\JsonData\Schema\ObjectType;

/**
 * A contest that declares its `data` with a schema: the twin of Contest, declared through
 * the hook, to compare the two.
 */
class SchemaContest extends Contest implements HasJsonDataSchema
{
    /**
     * @var (Closure(): ObjectType)|null
     */
    public static ?Closure $schema = null;

    public function jsonDataSchema(string $attribute): ?ObjectType
    {
        return $attribute === 'data' && ! is_null(static::$schema) ? (static::$schema)() : null;
    }

    public function getMorphClass()
    {
        return Contest::class;
    }

    public function getForeignKey()
    {
        return 'contest_id';
    }
}
