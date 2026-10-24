<?php

namespace Folklore\JsonData\Schema;

/**
 * A reference to a model, stored as `{key}://{identifier}` (docs/json-data.md, "Stored
 * format"). The key must be mapped. With `relation()`, the references are synced to that
 * relation of the model; without one, they are resolved and checked, but not synced.
 *
 * What is given to it (a model, an identifier, a file…) is prepared by the key's handler
 * when the model is saved, as for any reference path.
 */
class ReferenceType extends ScalarType
{
    protected ?string $relation = null;

    /**
     * @var class-string|null
     */
    protected ?string $using = null;

    public function __construct(
        protected string $key,
    ) {}

    /**
     * The Eloquent relation that the references are synced to. Its related model must be
     * the model the key is mapped to.
     */
    public function relation(string $relation): static
    {
        $this->relation = $relation;

        return $this;
    }

    /**
     * A handler for this node only, instead of the key's handler.
     *
     * @param  class-string  $handler
     */
    public function using(string $handler): static
    {
        $this->using = $handler;

        return $this;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getRelation(): ?string
    {
        return $this->relation;
    }

    /**
     * @return class-string|null
     */
    public function getUsing(): ?string
    {
        return $this->using;
    }
}
