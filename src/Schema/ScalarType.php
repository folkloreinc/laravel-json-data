<?php

namespace Folklore\JsonData\Schema;

/**
 * A node stored as a single JSON value: a string, a number, a boolean, a date, an enum's
 * value or a reference. It can be translatable, and copied into a column.
 */
abstract class ScalarType extends Type
{
    protected bool $translatable = false;

    protected ?string $extract = null;

    /**
     * Stores the value as an object keyed by locale (`{"fr": "…", "en": "…"}`). The
     * locales come from the package's config, or from the `LocaleResolver` bound in the
     * container.
     */
    public function translatable(bool $translatable = true): static
    {
        $this->translatable = $translatable;

        return $this;
    }

    /**
     * Copies the value into a column of the model when it is written, to sort or search on
     * it. A translatable value is copied into one column per locale: the name has a
     * `{locale}` placeholder (`title_{locale}`). Not under a list or a map.
     */
    public function extract(string $column): static
    {
        $this->extract = $column;

        return $this;
    }

    public function isTranslatable(): bool
    {
        return $this->translatable;
    }

    public function getExtract(): ?string
    {
        return $this->extract;
    }

    /**
     * The value to store for a value given to this node, before it is translated:
     * an enum becomes its value, a date its ISO 8601 string. Other values are kept.
     *
     * @internal
     */
    public function store(mixed $value): mixed
    {
        return $value;
    }
}
