<?php

namespace Folklore\Eloquent\Exceptions;

use Folklore\Eloquent\ReferenceContext;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A reference handler refused, on write, that the owner refers to a model.
 */
class ReferenceNotAllowed extends RuntimeException
{
    public static function for(Model $resolved, ReferenceContext $context): self
    {
        $model = $resolved::class;
        $key = $resolved->getKey();
        $identifier = is_scalar($key) ? (string) $key : '?';
        $path = $context->path ?? '?';

        return new self("A [{$context->ownerClass}] may not refer to [{$model}] {$identifier} at [{$context->attribute}.{$path}].");
    }
}
