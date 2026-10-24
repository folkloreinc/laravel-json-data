<?php

namespace Folklore\JsonData\Schema;

use Folklore\JsonData\Contracts\HasJsonDataSchema;
use Illuminate\Database\Eloquent\Model;
use WeakMap;

/**
 * The schemas of the models' JSON attributes, memoized per instance and attribute.
 *
 * @internal
 */
final class Schemas
{
    /**
     * @var WeakMap<Model, array<string, ObjectType|false|null>>|null
     */
    private static ?WeakMap $schemas = null;

    /**
     * The schema of a model's JSON attribute, or null when it has none. A schema that reads
     * its own attribute while it is built reads it without a schema.
     */
    public static function for(Model $model, string $attribute): ?ObjectType
    {
        if (! $model instanceof HasJsonDataSchema) {
            return null;
        }
        self::$schemas ??= new WeakMap;
        $memo = self::$schemas[$model] ?? [];
        if (array_key_exists($attribute, $memo)) {
            return $memo[$attribute] === false ? null : $memo[$attribute];
        }

        // false while it is built
        $memo[$attribute] = false;
        self::$schemas[$model] = $memo;
        $schema = $model->jsonDataSchema($attribute);
        // Read again: building it may have memoized the schemas of other attributes
        self::$schemas[$model] = array_merge(self::$schemas[$model], [$attribute => $schema]);

        return $schema;
    }
}
