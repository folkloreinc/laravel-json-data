<?php

namespace Folklore\JsonData\Schema;

use Folklore\JsonData\Schema\Concerns\HasBounds;

/**
 * A list of values of one node, stored as a JSON array. `min()` and `max()` bound its
 * length. A list given to `JsonData::fill()` replaces the stored one.
 */
class ListType extends Type
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
