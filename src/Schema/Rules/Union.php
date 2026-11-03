<?php

namespace Folklore\JsonData\Schema\Rules;

use Closure;
use Folklore\JsonData\Schema\RuleBuilder;
use Folklore\JsonData\Schema\UnionType;
use Illuminate\Container\Container;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Validator;

/**
 * The validation rule of a union (`Json::rules()`): its discriminator is one of the union's
 * values, and the object is checked with the rules of that variant, whose errors are added
 * at their own keys (`blocks.0.body`). Laravel's rules can't depend on a sibling value at
 * every depth, so the variant's rules are checked here, against the whole input.
 */
class Union implements ValidationRule, ValidatorAwareRule
{
    protected ?Validator $validator = null;

    /**
     * @param  string  $attribute  The JSON attribute of the union.
     * @param  list<string>  $segments  The union's path in the attribute (`blocks.*`).
     * @param  bool  $repeated  Whether the union is under a list or a map, whose items are given in full.
     */
    public function __construct(
        protected UnionType $node,
        protected Model $owner,
        protected string $attribute,
        protected array $segments,
        protected bool $repeated,
    ) {}

    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            // The node's `array` rule refuses it
            return;
        }

        $discriminator = $this->node->getDiscriminator();
        $variant = $this->node->getVariant($value[$discriminator] ?? null);
        if (is_null($variant)) {
            $fail("The :attribute.{$discriminator} must be one of: ".implode(', ', array_keys($this->node->getVariants())).'.');

            return;
        }

        $rules = RuleBuilder::forVariant($this->owner, $this->attribute, $this->node, $variant, $attribute, $this->segments, $this->repeated);
        $validator = Container::getInstance()->make(Factory::class)->make(
            $this->validator?->getData() ?? [],
            $rules,
            $this->validator->customMessages ?? [],
            $this->validator->customAttributes ?? [],
        );
        if (! $validator->fails()) {
            return;
        }

        foreach ($validator->errors()->messages() as $key => $messages) {
            foreach ($messages as $message) {
                if ($key === $attribute || is_null($this->validator)) {
                    $fail($message);
                } else {
                    $this->validator->errors()->add($key, $message);
                }
            }
        }
    }
}
