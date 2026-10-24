<?php

namespace Folklore\JsonData\Tests;

/**
 * How the fixtures declare their JSON attributes until the schema exists: TestCase reads
 * it through JsonData::declareUsing(), as laravel-folklore's adapter reads its 1.x
 * declarations.
 */
interface DeclaresJsonData
{
    /**
     * The reference paths of a JSON attribute: `path => relation`, or `path => options`.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function jsonDataReferences(string $attribute, mixed $value, array $attributes): array;

    /**
     * The paths of a JSON attribute copied into columns: `path => column`.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    public function jsonDataColumns(string $attribute, mixed $value, array $attributes): array;
}
