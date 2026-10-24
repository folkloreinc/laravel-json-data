<?php

namespace Folklore\JsonData\Schema;

use Folklore\JsonData\Schema\Concerns\HasBounds;

/**
 * An object with any keys, whose values are of one node. `min()` and `max()` bound its
 * number of keys. A map given to `JsonData::fill()` replaces the stored one.
 */
class MapType extends Type
{
    use HasBounds;

    public function __construct(
        protected Type $of,
    ) {}

    public function getOf(): Type
    {
        return $this->of;
    }
}
