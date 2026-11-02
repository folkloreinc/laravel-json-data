<?php

namespace Folklore\JsonData\Exceptions;

use InvalidArgumentException;

/**
 * A reference, or a reference key, that the registry doesn't accept.
 */
class InvalidJsonReference extends InvalidArgumentException
{
    public static function unmappedKey(string $key): self
    {
        return new self("The JSON reference key [{$key}] is not mapped, and JsonData::enforceMap() is on.");
    }

    public static function invalidKey(string $key): self
    {
        return new self("[{$key}] is not a valid JSON reference key: use letters, digits, `_` and `-`, starting with a letter.");
    }

    public static function conflictingKey(string $key, string $mapped, string $given): self
    {
        return new self("The JSON reference key [{$key}] is already mapped to [{$mapped}], not to [{$given}].");
    }

    public static function invalidTarget(string $key): self
    {
        return new self("The JSON reference key [{$key}] must map to a model class, a [model, column] pair or a JsonReference.");
    }

    public static function unmappedModel(string $model): self
    {
        return new self("No JSON reference key is mapped to [{$model}].");
    }

    public static function keyForAnotherModel(string $key, string $model): self
    {
        return new self("The JSON reference key [{$key}] is not mapped to [{$model}].");
    }

    public static function cantDelete(string $relation, string $model): self
    {
        return new self("The relation [{$relation}] of [{$model}] doesn't own its rows: only a HasOne, HasMany, MorphOne or MorphMany deletes the rows that the JSON no longer references (the `delete` option, deletesUnreferenced()).");
    }

    public static function missingIdentifier(string $key, string $model): self
    {
        return new self("The [{$model}] has no identifier for the JSON reference key [{$key}].");
    }
}
