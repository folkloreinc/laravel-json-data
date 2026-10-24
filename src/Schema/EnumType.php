<?php

namespace Folklore\JsonData\Schema;

use BackedEnum;
use UnitEnum;

/**
 * One of a list of values, or of the cases of a backed enum, stored as its value. A case
 * of the enum given to it is stored as its value.
 */
class EnumType extends ScalarType
{
    /**
     * @param  class-string<BackedEnum>|null  $enum
     * @param  list<int|string>  $values
     */
    public function __construct(
        protected ?string $enum,
        protected array $values,
    ) {}

    /**
     * @return class-string<BackedEnum>|null
     */
    public function getEnum(): ?string
    {
        return $this->enum;
    }

    /**
     * @return list<int|string>
     */
    public function getValues(): array
    {
        return $this->values;
    }

    public function store(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            default => $value,
        };
    }
}
