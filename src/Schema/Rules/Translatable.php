<?php

namespace Folklore\JsonData\Schema\Rules;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Validation\Validator;

/**
 * The validation rule of a translatable node (`Json::rules()`): an object keyed by locale
 * has only the locales, whose values the `.*` rules check; any other value goes into the
 * default locale, and is checked with the node's rules.
 */
class Translatable implements ValidationRule, ValidatorAwareRule
{
    protected ?Validator $validator = null;

    /**
     * @param  list<string>  $locales
     * @param  list<mixed>  $rules  The rules of the node's value in one locale.
     */
    public function __construct(
        protected array $locales,
        protected array $rules,
    ) {}

    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_array($value)) {
            $unknown = array_diff(array_map(fn (int|string $locale) => (string) $locale, array_keys($value)), $this->locales);
            if (count($unknown) > 0) {
                $fail('The :attribute has locales that are not one of the locales: '.implode(', ', $unknown).'.');
            }

            return;
        }

        $name = $this->validator?->getDisplayableAttribute($attribute) ?? str_replace('_', ' ', $attribute);
        $validator = Container::getInstance()->make(Factory::class)->make(
            ['value' => $value],
            ['value' => $this->rules],
            [],
            ['value' => $name],
        );
        foreach ($validator->errors()->all() as $message) {
            $fail($message);
        }
    }
}
