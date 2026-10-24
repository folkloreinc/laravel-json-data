<?php

namespace Folklore\JsonData\Schema;

/**
 * A node of the schema of a JSON column (docs/json-data.md, "Schema"). Every node can be
 * required, nullable, have a default, and be read from an input key with another name.
 */
abstract class Type
{
    protected bool $required = false;

    protected bool $nullable = false;

    protected bool $hasDefault = false;

    protected mixed $default = null;

    protected ?string $input = null;

    /**
     * Whether the value must be given. Checked by the validation rules of a later slice.
     */
    public function required(bool $required = true): static
    {
        $this->required = $required;

        return $this;
    }

    /**
     * Whether the value may be null. Checked by the validation rules of a later slice.
     */
    public function nullable(bool $nullable = true): static
    {
        $this->nullable = $nullable;

        return $this;
    }

    /**
     * The value written by `JsonData::fill()` when neither the input nor the stored value
     * has the path. It is written as if it were given.
     */
    public function default(mixed $default): static
    {
        $this->hasDefault = true;
        $this->default = $default;

        return $this;
    }

    /**
     * The key of the flat input that `JsonData::fill()` reads this node from, instead of
     * its name (`seo.title` from `seo_title`). Not under a list or a map.
     */
    public function input(string $name): static
    {
        $this->input = $name;

        return $this;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }

    public function getDefault(): mixed
    {
        return $this->default;
    }

    public function getInput(): ?string
    {
        return $this->input;
    }
}
