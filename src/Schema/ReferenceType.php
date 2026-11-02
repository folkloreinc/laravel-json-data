<?php

namespace Folklore\JsonData\Schema;

/**
 * A reference to a model, stored as `{key}://{identifier}` (docs/json-data.md, "Stored
 * format"). The key must be mapped. With `relation()`, the references are synced to that
 * relation of the model; without one, they are resolved and checked, but not synced. A
 * reference named by its relation, without a key, is a `RelationType`.
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
        protected ?string $key,
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

    protected bool $deletesUnreferenced = false;

    /**
     * Deletes, when the model's references are synced, the rows of the relation that the
     * JSON no longer references, as the `delete` option of a 1.x declaration. Only for a
     * relation that owns its rows: a HasOne, HasMany, MorphOne or MorphMany. The rows are
     * deleted with a query, without model events.
     */
    public function deletesUnreferenced(bool $deletes = true): static
    {
        $this->deletesUnreferenced = $deletes;

        return $this;
    }

    public function isDeletingUnreferenced(): bool
    {
        return $this->deletesUnreferenced;
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

    /**
     * The key written, or null for a node named by its relation (`Json::relation()`).
     */
    public function getKey(): ?string
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
