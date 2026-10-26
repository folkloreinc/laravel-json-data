<?php

namespace Folklore\JsonData\Schema;

use Folklore\JsonData\Contracts\LocaleResolver;
use Folklore\JsonData\JsonDataCast;
use Folklore\JsonData\Schema\Rules\Reference;
use Folklore\JsonData\Schema\Rules\Translatable;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Compiles the schemas of a model's JSON attributes into Laravel validation rules on the
 * keys of the flat input that `JsonData::fill()` reads (`Json::rules()`, docs/json-data.md,
 * "Validation rules").
 *
 * @internal
 */
final class RuleBuilder
{
    /**
     * @var array<string, list<mixed>>
     */
    private array $rules = [];

    private ?LocaleResolver $locales = null;

    private function __construct(
        private Model $model,
        private string $attribute,
    ) {}

    /**
     * The rules of a JSON attribute, or of every attribute that has a schema.
     *
     * @return array<string, list<mixed>>
     */
    public static function for(Model $model, ?string $attribute = null): array
    {
        $rules = [];
        $attributes = is_null($attribute) ? JsonDataCast::castAttributesOf($model) : [$attribute];
        foreach ($attributes as $name) {
            $schema = Schemas::for($model, $name);
            if (is_null($schema)) {
                continue;
            }
            // Compiling checks the schema: inputs and columns under lists, conflicts
            Compiler::compile($schema);
            $builder = new self($model, $name);
            foreach ($schema->getProperties() as $property => $node) {
                $input = $node->getInput();
                $builder->walk($node, $input ?? $property, [$property], parent: null, repeated: false, alias: false);
            }
            foreach ($builder->rules as $key => $keyRules) {
                $rules[$key] = array_merge($rules[$key] ?? [], $keyRules);
            }
        }

        return $rules;
    }

    /**
     * Adds the rules of a node at an input key, then those of its children.
     *
     * @param  list<string>  $segments  The node's path in the attribute, with `*` for the items of lists and maps.
     * @param  string|null  $parent  The input key of the object the node is a property of, if any.
     * @param  bool  $alias  Whether the key is a nested node's `input()` name, beside its path, or under one.
     * @param  string|null  $other  The other key of a nested node with an `input()` name: its path for the name, or the reverse.
     */
    private function walk(Type $node, string $key, array $segments, ?string $parent, bool $repeated, bool $alias, ?string $other = null): void
    {
        $this->add($key, [...$this->presence($node, $parent, $repeated, $alias, $other), ...$this->type($node, $key, $segments)]);

        if ($node instanceof ObjectType) {
            foreach ($node->getProperties() as $name => $property) {
                $childKey = $key.'.'.$name;
                $input = $property->getInput();
                $hasAlias = ! $repeated && ! $alias && ! is_null($input);
                $this->walk($property, $childKey, [...$segments, $name], $key, $repeated, $alias, $hasAlias ? $input : null);
                if ($hasAlias) {
                    $this->walk($property, $input, [...$segments, $name], null, false, true, $childKey);
                }
            }
        } elseif ($node instanceof ListType || $node instanceof MapType) {
            $this->walk($node->getOf(), $key.'.*', [...$segments, '*'], null, true, $alias);
        }
    }

    /**
     * Whether the key must be given. A stored model keeps the paths that the input doesn't
     * give, and `fill()` writes defaults, so `required()` only refuses an empty value there;
     * a list or a map replaces the stored one, so its items are checked in full. A nested
     * node with an `input()` name is required at one of its two keys.
     *
     * @return list<mixed>
     */
    private function presence(Type $node, ?string $parent, bool $repeated, bool $alias, ?string $other): array
    {
        $rules = [];
        if ($node->isRequired()) {
            $rules = match (true) {
                $repeated && ! is_null($parent) => $node->hasDefault() ? ['sometimes', 'required'] : ['required_with:'.$parent],
                $repeated => ['required'],
                $this->model->exists || $node->hasDefault() => ['sometimes', 'required'],
                $alias && ! is_null($other) => ['required_without:'.$other],
                ! is_null($other) => [],
                ! is_null($parent) => ['required_with:'.$parent],
                default => ['required'],
            };
        }
        if ($node->isNullable()) {
            $rules[] = 'nullable';
        }

        return $rules;
    }

    /**
     * The rules of a node's value, and the rules of the locales of a translatable one.
     *
     * @param  list<string>  $segments
     * @return list<mixed>
     */
    private function type(Type $node, string $key, array $segments): array
    {
        if ($node instanceof ScalarType) {
            $rules = $this->scalar($node, $segments);
            if (! $node->isTranslatable()) {
                return $rules;
            }
            $this->add($key.'.*', ['nullable', ...$rules]);

            return [new Translatable($this->locales()->locales(), $rules)];
        }

        return match (true) {
            $node instanceof ObjectType => [$node->allowsAdditional() ? 'array' : 'array:'.implode(',', array_keys($node->getProperties()))],
            $node instanceof ListType => ['array', 'list', ...$this->bounds($node->getMin(), $node->getMax())],
            $node instanceof MapType => ['array', ...$this->bounds($node->getMin(), $node->getMax())],
            $node instanceof DocumentType => ['array'],
            default => [],
        };
    }

    /**
     * @param  list<string>  $segments
     * @return list<mixed>
     */
    private function scalar(ScalarType $node, array $segments): array
    {
        return match (true) {
            $node instanceof StringType => [
                'string',
                ...$this->bounds($node->getMin(), $node->getMax()),
                ...(is_null($node->getPattern()) ? [] : ['regex:'.$node->getPattern()]),
            ],
            $node instanceof IntegerType => ['integer', ...$this->bounds($node->getMin(), $node->getMax())],
            $node instanceof NumberType => ['numeric', ...$this->bounds($node->getMin(), $node->getMax())],
            $node instanceof BooleanType => ['boolean'],
            $node instanceof DateType => [$node->hasTime() ? 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:sp,Y-m-d\TH:i:s.vP,Y-m-d\TH:i:s.vp' : 'date_format:Y-m-d'],
            $node instanceof EnumType => [is_null($node->getEnum()) ? Rule::in($node->getValues()) : Rule::enum($node->getEnum())],
            $node instanceof ReferenceType => [new Reference(
                $node,
                $this->model,
                $this->attribute,
                implode('.', $node->isTranslatable() ? [...$segments, '*'] : $segments),
            )],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function bounds(int|float|null $min, int|float|null $max): array
    {
        return [
            ...(is_null($min) ? [] : ['min:'.$min]),
            ...(is_null($max) ? [] : ['max:'.$max]),
        ];
    }

    /**
     * @param  list<mixed>  $rules
     */
    private function add(string $key, array $rules): void
    {
        $this->rules[$key] = array_merge($this->rules[$key] ?? [], $rules);
    }

    private function locales(): LocaleResolver
    {
        return $this->locales ??= Container::getInstance()->make(LocaleResolver::class);
    }
}
