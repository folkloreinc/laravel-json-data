<?php

namespace Folklore\JsonData\Schema;

use Folklore\JsonData\Exceptions\InvalidJsonData;

/**
 * A reference to a model, stored as `{key}://{identifier}` (docs/json-data.md, "Stored
 * format"). The key must be mapped. With `relation()`, the references are synced to that
 * relation of the model; without one, they are resolved and checked, but not synced. A
 * reference named by its relation, without a key, is a `RelationType`.
 *
 * What is given to it (a model, an identifier, a file…) is prepared by the key's handler
 * when the model is saved, as for any reference path.
 *
 * With `properties()`, a value may carry values of its own beside its reference, such as the
 * edits of an image for this use: it is stored as `{ "id": "{key}://{identifier}", …properties }`
 * when it has one of them, and as the reference string otherwise.
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
     * @var array<string, Type>
     */
    protected array $properties = [];

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
     * Values that a reference keeps beside it, such as the edits of an image for this use
     * (`['transform' => Json::object()->additional()]`). A value given as an object with an
     * `id`, the reference, and some of these properties is stored as `{ "id": "{key}://{identifier}",
     * …properties }`, with its other keys left out; one without any of them, or a value of another
     * form (a model, an identifier…), is stored as the reference string, as without properties.
     * On read, the object's `id` is resolved. The properties are optional plain values: no
     * reference, column or input key of their own.
     *
     * @param  array<array-key, mixed>  $properties  Nodes by name.
     */
    public function properties(array $properties): static
    {
        $nodes = [];
        foreach ($properties as $name => $property) {
            if (! is_string($name) || $name === '' || $name === 'id' || str_contains($name, '.') || str_contains($name, '*')) {
                throw InvalidJsonData::invalidSchema("The properties of a reference are named, other than [id], with no `.` or `*`: [{$name}].");
            }
            if (! $property instanceof Type) {
                throw InvalidJsonData::invalidSchema("The property [{$name}] of a reference is not a schema node.");
            }
            if ($property->isRequired() || ! is_null($property->getInput()) || self::declaresReferences($property)) {
                throw InvalidJsonData::invalidSchema("The property [{$name}] of a reference is an optional plain value: not required, with no input key and no reference.");
            }
            if ($property instanceof ScalarType && ! is_null($property->getExtract())) {
                throw InvalidJsonData::invalidSchema("The property [{$name}] of a reference can't be extracted into a column.");
            }
            $nodes[$name] = $property;
        }
        $this->properties = $nodes;

        return $this;
    }

    /**
     * @return array<string, Type>
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    /**
     * Whether a node is, or has under it, a reference.
     */
    private static function declaresReferences(Type $node): bool
    {
        return match (true) {
            $node instanceof ReferenceType, $node instanceof DocumentType, $node instanceof UnionType => true,
            $node instanceof ObjectType => count($node->getPaths()) > 0 || count(array_filter($node->getProperties(), self::declaresReferences(...))) > 0,
            $node instanceof ListType, $node instanceof MapType => count($node->getPaths()) > 0 || self::declaresReferences($node->getOf()),
            $node instanceof AnyType => count($node->getPaths()) > 0,
            default => false,
        };
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
