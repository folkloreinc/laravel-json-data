<?php

namespace Folklore\Tests\Unit\Eloquent\Fixtures;

use Folklore\Contracts\Eloquent\ReferenceHandler;
use Folklore\Eloquent\ReferenceContext;
use Illuminate\Database\Eloquent\Model;

class MediaHandler implements ReferenceHandler
{
    public function prepare(mixed $value, ReferenceContext $context): ?Model
    {
        return $value instanceof ReferencedMedia ? $value : null;
    }

    public function resolveMany(array $identifiers, ReferenceContext $context): array
    {
        return [];
    }

    public function allows(Model $resolved, ReferenceContext $context): bool
    {
        return true;
    }
}
