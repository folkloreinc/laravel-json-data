<?php

namespace Folklore\JsonData\Contracts;

use Folklore\JsonData\Schema\ObjectType;

/**
 * A model that declares the schema of its JSON columns (docs/json-data.md, "Schema").
 */
interface HasJsonDataSchema
{
    /**
     * The schema of a JSON attribute cast with `JsonDataCast`, or null when the model
     * doesn't declare one: the declarations hook is then asked. It runs on the instance,
     * so it can depend on the model's attributes, and it is memoized per instance and
     * attribute.
     */
    public function jsonDataSchema(string $attribute): ?ObjectType;
}
