<?php

namespace Folklore\JsonData\Tests\Feature\Fixtures;

/**
 * A block that counts how often it is turned into an array, and whose array holds a
 * reference that isn't in any stored JSON (`cover`).
 */
class CountedBlock extends Block
{
    public static int $arrays = 0;

    protected $appends = ['cover'];

    public function getCoverAttribute(): string
    {
        return 'medias://1';
    }

    public function toArray()
    {
        self::$arrays++;

        return parent::toArray();
    }
}
