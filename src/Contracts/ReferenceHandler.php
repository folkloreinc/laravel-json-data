<?php

namespace Folklore\Contracts\Eloquent;

use Folklore\Eloquent\ReferenceContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Prepares, resolves and checks the values of one reference key in JSON data
 * (docs/json-data.md, "Handlers"). Registered for a key with `JsonReference::using()`,
 * or for a path with the `using` option, and resolved from the container.
 */
interface ReferenceHandler
{
    /**
     * Turns what forms and seeders give into a model: a model, an entity, a reference,
     * an identifier, a file… Null when the value is not something this handler takes.
     * Called when the owner is saved, so it may create the model (ingest a file).
     */
    public function prepare(mixed $value, ReferenceContext $context): ?Model;

    /**
     * Resolves identifiers of the key's column in one query.
     *
     * @param  list<string>  $identifiers
     * @return array<string, Model> Keyed by identifier; those that don't resolve are left out.
     */
    public function resolveMany(array $identifiers, ReferenceContext $context): array;

    /**
     * Whether the owner may refer to this model. Checked on write, where a refusal throws
     * `ReferenceNotAllowed`, and on read, where the reference reads as null.
     */
    public function allows(Model $resolved, ReferenceContext $context): bool;
}
