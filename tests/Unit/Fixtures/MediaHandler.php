<?php

namespace Folklore\JsonData\Tests\Unit\Fixtures;

use Folklore\JsonData\Contracts\ReferenceHandler;
use Folklore\JsonData\ReferenceContext;
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
