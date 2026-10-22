<?php

namespace Folklore\Eloquent;

use Stringable;

/**
 * A reference as stored, `{key}://{identifier}`, with where it was found when it comes
 * from a listing.
 */
final readonly class JsonReferenceEntry implements Stringable
{
    /**
     * @param  JsonReference|null  $target  The key's target. Null for a key that isn't mapped,
     *                                      which falls back to the relation of the same name.
     * @param  string|null  $attribute  The JSON attribute that holds it, in a listing.
     * @param  string|null  $path  Its path in the attribute (`gallery.2`), in a listing.
     * @param  string|null  $relation  The relation its path is declared with, in a listing.
     */
    public function __construct(
        public string $key,
        public string $identifier,
        public ?JsonReference $target = null,
        public ?string $attribute = null,
        public ?string $path = null,
        public ?string $relation = null,
    ) {}

    public function isMapped(): bool
    {
        return $this->target !== null;
    }

    public function __toString(): string
    {
        return $this->key.'://'.$this->identifier;
    }
}
