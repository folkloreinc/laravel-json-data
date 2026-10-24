<?php

namespace Folklore\JsonData\Exceptions;

use InvalidArgumentException;

/**
 * Data that the schema of a JSON column refuses, or a schema that can't be compiled.
 */
class InvalidJsonData extends InvalidArgumentException
{
    /**
     * @param  list<string>  $keys
     */
    public static function unknownKeys(string $model, array $keys): self
    {
        $list = implode(', ', $keys);

        return new self("The keys [{$list}] are neither fillable on [{$model}] nor in the schema of its JSON attributes.");
    }

    public static function unknownKey(string $path): self
    {
        return new self("The key [{$path}] is not in the schema, whose object doesn't allow additional keys.");
    }

    public static function unknownLocale(string $path, string $locale): self
    {
        return new self("[{$locale}] at [{$path}] is not one of the locales of translatable values.");
    }

    public static function invalidSchema(string $message): self
    {
        return new self("Invalid JSON data schema: {$message}");
    }
}
