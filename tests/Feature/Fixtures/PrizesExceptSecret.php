<?php

namespace Folklore\Tests\Feature\Eloquent\Fixtures;

use Folklore\Eloquent\ModelReferences;
use Folklore\Eloquent\ReferenceContext;
use Illuminate\Database\Eloquent\Model;

/**
 * A handler that refuses the prize whose handle is `secret`, as an application refuses
 * a model of another tenant.
 */
class PrizesExceptSecret extends ModelReferences
{
    public function allows(Model $resolved, ReferenceContext $context): bool
    {
        return $resolved->getAttribute('handle') !== 'secret';
    }
}
