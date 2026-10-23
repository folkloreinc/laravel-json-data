<?php

namespace Folklore\Eloquent;

use Folklore\Contracts\Eloquent\ReferenceHandler;
use Folklore\Contracts\Entities\Entity;
use Folklore\Contracts\Entities\HasModel;
use Folklore\Contracts\Entities\ToEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The default reference handler (docs/json-data.md, "Default handler"): it takes models,
 * entities, references and identifiers, and resolves identifiers with one `whereIn()` on
 * the key's column. Applications extend it for their own keys.
 */
class ModelReferences implements ReferenceHandler
{
    public function prepare(mixed $value, ReferenceContext $context): ?Model
    {
        $target = $context->target;

        if ($value instanceof Model) {
            return $target->accepts($value) ? $value : null;
        }

        if ($value instanceof HasModel) {
            $model = $value->getModel();
            if ($target->accepts($model)) {
                return $model;
            }
        }

        if ($value instanceof ToEntity) {
            $value = $value->toEntity();
        }

        if ($value instanceof Entity) {
            return $this->find($value->id(), $context);
        }

        if (is_string($value) && ! is_null($entry = JsonData::parse($value))) {
            return $this->fromReference($entry, $context);
        }

        if (is_array($value) && isset($value['id']) && (is_int($value['id']) || is_string($value['id']))) {
            return $this->find((string) $value['id'], $context);
        }

        if (is_int($value) || (is_string($value) && $value !== '')) {
            return $this->find((string) $value, $context);
        }

        return null;
    }

    public function resolveMany(array $identifiers, ReferenceContext $context): array
    {
        if (count($identifiers) === 0) {
            return [];
        }

        $target = $context->target;
        $column = $target->column();
        $identifiers = array_values(array_filter(
            array_unique($identifiers),
            fn (string $identifier) => $this->isIdentifierOf($target, $identifier),
        ));
        if (count($identifiers) === 0) {
            return [];
        }
        $models = [];
        foreach ($this->query($target)->whereIn($column, $identifiers)->get() as $model) {
            $identifier = $target->identifierOf($model);
            if (! is_null($identifier)) {
                $models[$identifier] = $model;
            }
        }

        return $models;
    }

    public function allows(Model $resolved, ReferenceContext $context): bool
    {
        return true;
    }

    /**
     * A reference of another accepted key, such as `medias://12` where the key writes
     * `media://`: found by that key's column.
     */
    protected function fromReference(JsonReferenceEntry $entry, ReferenceContext $context): ?Model
    {
        $target = $entry->target ?? ($entry->key === $context->key ? $context->target : null);
        if (is_null($target) || ! $context->target->accepts($target->model) || ! $this->isIdentifierOf($target, $entry->identifier)) {
            return null;
        }

        return $this->query($target)->where($target->column(), $entry->identifier)->first();
    }

    protected function find(string $identifier, ReferenceContext $context): ?Model
    {
        $target = $context->target;
        if (! $this->isIdentifierOf($target, $identifier)) {
            return null;
        }

        return $this->query($target)->where($target->column(), $identifier)->first();
    }

    /**
     * Whether a value can be an identifier of the key's column: an integer key only has
     * digits. Other strings (a URL) are not looked up, which databases with types refuse.
     */
    protected function isIdentifierOf(JsonReference $target, string $identifier): bool
    {
        $model = new $target->model;

        return $target->column() !== $model->getKeyName()
            || ! in_array($model->getKeyType(), ['int', 'integer'], true)
            || ctype_digit($identifier);
    }

    /**
     * The query of the key's model, with its global scopes (soft deletes, a tenant scope).
     *
     * @return Builder<Model>
     */
    protected function query(JsonReference $target): Builder
    {
        return (new $target->model)->newQuery();
    }
}
