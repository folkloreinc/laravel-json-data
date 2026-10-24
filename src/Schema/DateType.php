<?php

namespace Folklore\JsonData\Schema;

use DateTimeInterface;

/**
 * A date (`2026-10-23`) or a date and time (`2026-10-23T14:30:00+00:00`), stored as an
 * ISO 8601 string. A `DateTimeInterface` given to it is formatted; a string is kept.
 */
class DateType extends ScalarType
{
    public function __construct(
        protected bool $withTime = false,
    ) {}

    public function hasTime(): bool
    {
        return $this->withTime;
    }

    public function store(mixed $value): mixed
    {
        if (! $value instanceof DateTimeInterface) {
            return $value;
        }

        return $value->format($this->withTime ? DateTimeInterface::ATOM : 'Y-m-d');
    }
}
