<?php

namespace Folklore\JsonData\Tests\Feature\Fixtures;

/**
 * A game that refers to its prizes by handle (`prize://grand-prize`), through the
 * registry of keys, and to prizes anywhere in a document (`body.**`).
 */
class RegistryGame extends Game
{
    /**
     * @var array<string, mixed>
     */
    public static array $prizeOptions = [];

    public function jsonDataReferences(string $attribute, mixed $value, array $attributes): array
    {
        return [
            'image.*' => 'medias',
            'prizes.*.prize' => array_merge(['relation' => 'prizes'], self::$prizeOptions),
            'body.**' => 'prizes',
        ];
    }

    public function getMorphClass()
    {
        return Game::class;
    }
}
