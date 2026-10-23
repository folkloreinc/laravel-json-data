<?php

namespace Folklore\Tests\Feature\Eloquent\Fixtures;

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

    public function getJsonDataRelations($key, $value, $attributes = [])
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
