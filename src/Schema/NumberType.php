<?php

namespace Folklore\JsonData\Schema;

use Folklore\JsonData\Schema\Concerns\HasBounds;

/**
 * A number, integer or not. `min()` and `max()` bound its value.
 */
class NumberType extends ScalarType
{
    use HasBounds;
}
