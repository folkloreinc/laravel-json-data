<?php

namespace Folklore\JsonData\Schema\Rules;

use Closure;
use Folklore\JsonData\Exceptions\InvalidJsonReference;
use Folklore\JsonData\JsonData;
use Folklore\JsonData\JsonDataCast;
use Folklore\JsonData\JsonReference;
use Folklore\JsonData\ReferenceContext;
use Folklore\JsonData\ReferenceOperation;
use Folklore\JsonData\Schema\ReferenceType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * The validation rule of a reference node (`Json::rules()`): a model, a reference or an
 * identifier given to it must be a model of the key's model that its handler finds and
 * allows the owner to refer to. Other values, such as files, are left to the handler,
 * which prepares them when the model is saved. A node named by its relation
 * (`Json::relation()`) checks the key it writes for the related model: for an unmapped
 * one, a model of the related class or one of its ids, and its own references.
 */
class Reference implements ValidationRule
{
    /**
     * @param  string  $attribute  The JSON attribute of the node.
     * @param  string  $path  The node's path in the attribute (`gallery.*`).
     */
    public function __construct(
        protected ReferenceType $node,
        protected Model $owner,
        protected string $attribute,
        protected string $path,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $key = $this->node->getKey();
        $relation = $this->node->getRelation();
        if (is_null($key) && ! is_null($relation)) {
            try {
                [$key, $target] = JsonDataCast::writeTargetFor($this->owner, $relation);
            } catch (InvalidJsonReference $exception) {
                $fail("The :attribute can't be checked: {$exception->getMessage()}");

                return;
            }
        } else {
            $key = (string) $key;
            $target = JsonData::target($key);
        }
        if (is_null($target)) {
            $fail("The :attribute can't be checked: [{$key}] is not a mapped key.");

            return;
        }

        $value = JsonData::unwrap($value, $target);
        if ($value instanceof Model) {
            if (! $target->accepts($value) || ! $this->allows($value, $key, $target)) {
                $fail('The :attribute is not something it can refer to.');
            }

            return;
        }

        if (is_string($value) && ! is_null($entry = JsonData::parse($value))) {
            // A reference of another key of the same model is kept, as the cast keeps it,
            // and so is one of the unmapped key that a node named by its relation writes
            $entryTarget = $entry->target ?? ($entry->key === $key ? $target : null);
            if (is_null($entryTarget) || ! $entryTarget->accepts($target->model)) {
                $fail("The :attribute is not a reference of [{$key}].");

                return;
            }
            if (! $this->resolves($entry->identifier, $entry->key, $entryTarget)) {
                $fail('The :attribute refers to something that doesn\'t exist.');
            }

            return;
        }

        if (is_int($value) || (is_string($value) && $value !== '')) {
            if (! $this->resolves((string) $value, $key, $target)) {
                $fail('The :attribute refers to something that doesn\'t exist.');
            }
        }
    }

    /**
     * Whether an identifier of a key resolves through its handler to a model that the
     * owner may refer to.
     */
    protected function resolves(string $identifier, string $key, JsonReference $target): bool
    {
        $found = JsonDataCast::handlerFor($target, $this->node->getUsing())
            ->resolveMany([$identifier], $this->context($key, $target))[$identifier] ?? null;

        return ! is_null($found) && $this->allows($found, $key, $target);
    }

    protected function allows(Model $model, string $key, JsonReference $target): bool
    {
        return JsonDataCast::handlerFor($target, $this->node->getUsing())->allows($model, $this->context($key, $target));
    }

    protected function context(string $key, JsonReference $target): ReferenceContext
    {
        return new ReferenceContext(
            operation: ReferenceOperation::Write,
            key: $key,
            target: $target,
            ownerClass: $this->owner::class,
            owner: $this->owner,
            attribute: $this->attribute,
            path: $this->path,
            relation: $this->node->getRelation(),
        );
    }
}
