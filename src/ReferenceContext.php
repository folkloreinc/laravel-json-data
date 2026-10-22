<?php

namespace Folklore\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * What a reference handler is told about a reference: who holds it, where, and under
 * which key.
 */
final readonly class ReferenceContext
{
    /**
     * @param  class-string<Model>  $ownerClass  The class of the models that hold the references.
     * @param  Model|null  $owner  The model that holds the reference. Null when references of
     *                             several models are resolved together.
     * @param  string  $attribute  The JSON attribute (`data`, `images`).
     * @param  string|null  $path  The declared path (`gallery.*`). Null in a batch of several paths.
     * @param  string|null  $relation  The relation the path is declared with, if any.
     */
    public function __construct(
        public ReferenceOperation $operation,
        public string $key,
        public JsonReference $target,
        public string $ownerClass,
        public ?Model $owner,
        public string $attribute,
        public ?string $path = null,
        public ?string $relation = null,
    ) {}

    public function isRead(): bool
    {
        return $this->operation === ReferenceOperation::Read;
    }

    public function isWrite(): bool
    {
        return $this->operation === ReferenceOperation::Write;
    }
}
