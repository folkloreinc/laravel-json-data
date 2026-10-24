<?php

namespace Folklore\JsonData\Schema\Concerns;

/**
 * The bounds of a node: of a number's value, or of the length of a string or a list.
 * Checked by the validation rules of a later slice.
 */
trait HasBounds
{
    protected int|float|null $min = null;

    protected int|float|null $max = null;

    public function min(int|float $min): static
    {
        $this->min = $min;

        return $this;
    }

    public function max(int|float $max): static
    {
        $this->max = $max;

        return $this;
    }

    public function getMin(): int|float|null
    {
        return $this->min;
    }

    public function getMax(): int|float|null
    {
        return $this->max;
    }
}
