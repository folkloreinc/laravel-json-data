<?php

namespace Folklore\JsonData\Schema;

use Folklore\JsonData\Schema\Concerns\HasPaths;

/**
 * Any value, not looked into: for data that the application doesn't describe yet, or whose
 * references are declared with `paths()` without describing the rest.
 */
class AnyType extends Type
{
    use HasPaths;
}
