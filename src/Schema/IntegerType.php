<?php

namespace Folklore\JsonData\Schema;

use Folklore\JsonData\Schema\Concerns\HasBounds;

/**
 * An integer. `min()` and `max()` bound its value.
 */
class IntegerType extends ScalarType
{
    use HasBounds;
}
