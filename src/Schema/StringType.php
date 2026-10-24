<?php

namespace Folklore\JsonData\Schema;

use Folklore\JsonData\Schema\Concerns\HasBounds;

/**
 * A string. `min()` and `max()` bound its length.
 */
class StringType extends ScalarType
{
    use HasBounds;

    protected ?string $pattern = null;

    /**
     * A regular expression the string matches, with its delimiters (`/^[a-z-]+$/`).
     */
    public function pattern(string $pattern): static
    {
        $this->pattern = $pattern;

        return $this;
    }

    public function getPattern(): ?string
    {
        return $this->pattern;
    }
}
