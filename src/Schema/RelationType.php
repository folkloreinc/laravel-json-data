<?php

namespace Folklore\JsonData\Schema;

use Folklore\JsonData\Exceptions\InvalidJsonData;

/**
 * A reference named by its relation, without a key (`Json::relation('authors')`), as a
 * declaration of laravel-folklore 1.x: it writes the first key mapped to the relation's
 * related model, else the relation's name (`authors://12`), and a key that isn't mapped
 * resolves through the owner's relation, so only the rows attached to the owner
 * (docs/decisions/0016). Its references are synced to the relation.
 */
class RelationType extends ReferenceType
{
    public function __construct(string $relation)
    {
        parent::__construct(null);
        $this->relation = $relation;
    }

    /**
     * Refused: the handler is the written key's. Map the key and declare
     * `Json::reference($key)->relation(...)->using(...)` instead.
     */
    public function using(string $handler): static
    {
        throw InvalidJsonData::invalidSchema("A reference node without a key takes its key's handler: map a key for [{$this->relation}] and use Json::reference(\$key)->using().");
    }
}
