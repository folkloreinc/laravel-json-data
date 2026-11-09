<?php

namespace Folklore\JsonData\Schema;

use Folklore\JsonData\Contracts\LocaleResolver;
use Illuminate\Container\Container;

/**
 * Compiles a schema node into a JSON Schema (draft 2020-12) of its **stored** value, as
 * `contests.data` or a seed file holds it: references as `{key}://{identifier}` strings,
 * translatable values as objects keyed by locale (docs/json-data.md, "JSON Schema"). What JSON
 * Schema can't say is in `x-` keywords: `x-reference`, `x-translatable`, `x-discriminator`,
 * `x-paths`, `x-document`.
 *
 * @internal
 */
final class JsonSchemaBuilder
{
    public const DIALECT = 'https://json-schema.org/draft/2020-12/schema';

    /**
     * @param  list<string>  $locales
     */
    private function __construct(
        private array $locales,
    ) {}

    /**
     * @param  list<string>|null  $locales  The locales of translatable values, else the
     *                                      `LocaleResolver`'s.
     * @return array<string, mixed>
     */
    public static function for(Type $node, ?array $locales = null): array
    {
        $locales ??= Container::getInstance()->make(LocaleResolver::class)->locales();

        return (new self($locales))->node($node);
    }

    /**
     * @return array<string, mixed>
     */
    private function node(Type $node): array
    {
        $schema = match (true) {
            $node instanceof ScalarType => $this->scalar($node),
            $node instanceof ObjectType => $this->object($node),
            $node instanceof ListType => $this->bounded(
                ['type' => 'array', 'items' => $this->node($node->getOf())],
                $node->getMin(),
                $node->getMax(),
                'minItems',
                'maxItems',
            ),
            $node instanceof MapType => $this->bounded(
                ['type' => 'object', 'additionalProperties' => $this->node($node->getOf())],
                $node->getMin(),
                $node->getMax(),
                'minProperties',
                'maxProperties',
            ),
            $node instanceof UnionType => $this->union($node),
            $node instanceof DocumentType => $this->document($node),
            default => [],
        };

        if ($node instanceof ObjectType || $node instanceof ListType || $node instanceof MapType || $node instanceof AnyType) {
            $paths = $node->getPaths();
            if (count($paths) > 0) {
                $schema['x-paths'] = array_map(fn (ReferenceType $reference) => $this->reference($reference), $paths);
            }
        }
        if ($node->hasDefault()) {
            $default = $node->getDefault();
            $schema['default'] = $node instanceof ScalarType && ! is_null($default) ? $node->store($default) : $default;
        }

        return $node->isNullable() ? $this->nullable($schema) : $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function scalar(ScalarType $node): array
    {
        $schema = match (true) {
            $node instanceof StringType => $this->string($node),
            $node instanceof IntegerType => $this->bounded(['type' => 'integer'], $node->getMin(), $node->getMax(), 'minimum', 'maximum'),
            $node instanceof NumberType => $this->bounded(['type' => 'number'], $node->getMin(), $node->getMax(), 'minimum', 'maximum'),
            $node instanceof BooleanType => ['type' => 'boolean'],
            $node instanceof DateType => ['type' => 'string', 'format' => $node->hasTime() ? 'date-time' : 'date'],
            $node instanceof EnumType => ['enum' => $node->getValues()],
            $node instanceof ReferenceType => $this->referenceValue($node),
            default => [],
        };

        if (! $node->isTranslatable()) {
            return $schema;
        }

        return [
            'type' => 'object',
            'properties' => array_fill_keys($this->locales, $schema),
            'additionalProperties' => false,
            'x-translatable' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function string(StringType $node): array
    {
        $schema = $this->bounded(['type' => 'string'], $node->getMin(), $node->getMax(), 'minLength', 'maxLength');
        $pattern = $node->getPattern();

        return is_null($pattern) ? $schema : array_merge($schema, $this->pattern($pattern));
    }

    /**
     * A PHP regular expression (`/^[a-z]+$/i`) as a JSON Schema pattern, without its
     * delimiters. Its modifiers, which JSON Schema has no keyword for, are in
     * `x-pattern-modifiers`.
     *
     * @return array<string, string>
     */
    private function pattern(string $pattern): array
    {
        $closing = ['(' => ')', '{' => '}', '[' => ']', '<' => '>'];
        $delimiter = $pattern[0] ?? '';
        $end = strrpos($pattern, $closing[$delimiter] ?? $delimiter);
        if ($delimiter === '' || $end === false || $end === 0 || ctype_alnum($delimiter) || $delimiter === '\\') {
            return ['pattern' => $pattern];
        }

        $modifiers = substr($pattern, $end + 1);

        return array_filter([
            'pattern' => substr($pattern, 1, $end - 1),
            'x-pattern-modifiers' => $modifiers,
        ], fn (string $value) => $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function referenceValue(ReferenceType $node): array
    {
        $key = $node->getKey();
        $string = [
            'type' => 'string',
            'pattern' => is_null($key) ? '^[^:/]+://.+$' : '^'.preg_quote($key, '/').'://.+$',
        ];
        $properties = $node->getProperties();
        if (count($properties) === 0) {
            return [...$string, 'x-reference' => $this->reference($node)];
        }

        // With properties, the reference string, or an object of the reference and some of them
        return [
            'oneOf' => [
                $string,
                [
                    'type' => 'object',
                    'properties' => ['id' => $string, ...array_map(fn (Type $property) => $this->node($property), $properties)],
                    'required' => ['id'],
                    'additionalProperties' => false,
                    'minProperties' => 2,
                ],
            ],
            'x-reference' => $this->reference($node),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function reference(ReferenceType $node): array
    {
        return array_filter([
            'key' => $node->getKey(),
            'relation' => $node->getRelation(),
        ], fn (?string $value) => ! is_null($value));
    }

    /**
     * @return array<string, mixed>
     */
    private function object(ObjectType $node): array
    {
        $properties = array_map(fn (Type $property) => $this->node($property), $node->getProperties());
        $required = array_keys(array_filter($node->getProperties(), fn (Type $property) => $property->isRequired()));

        return array_filter([
            'type' => 'object',
            'properties' => count($properties) > 0 ? $properties : null,
            'required' => count($required) > 0 ? $required : null,
            'additionalProperties' => $node->allowsAdditional(),
        ], fn (mixed $value) => ! is_null($value));
    }

    /**
     * One object per variant, with the discriminator's value as a `const`, first.
     *
     * @return array<string, mixed>
     */
    private function union(UnionType $node): array
    {
        $discriminator = $node->getDiscriminator();
        $variants = [];
        foreach ($node->getVariants() as $value => $variant) {
            $properties = [$discriminator => ['const' => $value]];
            $required = [$discriminator];
            foreach ($variant->getProperties() as $name => $property) {
                $properties[$name] = $this->node($property);
                if ($property->isRequired()) {
                    $required[] = $name;
                }
            }
            $schema = [
                'type' => 'object',
                'properties' => $properties,
                'required' => $required,
                'additionalProperties' => $variant->allowsAdditional(),
            ];
            if (count($variant->getPaths()) > 0) {
                $schema['x-paths'] = array_map(fn (ReferenceType $reference) => $this->reference($reference), $variant->getPaths());
            }
            $variants[] = $schema;
        }

        return ['oneOf' => $variants, 'x-discriminator' => $discriminator];
    }

    /**
     * @return array<string, mixed>
     */
    private function document(DocumentType $node): array
    {
        $references = array_map(fn (ReferenceType $reference) => $this->reference($reference), $node->getReferences());

        return array_filter([
            'x-document' => true,
            'x-references' => count($references) > 0 ? $references : null,
        ], fn (mixed $value) => ! is_null($value));
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function bounded(array $schema, int|float|null $min, int|float|null $max, string $minKeyword, string $maxKeyword): array
    {
        return array_filter(
            array_merge($schema, [$minKeyword => $min, $maxKeyword => $max]),
            fn (mixed $value) => ! is_null($value),
        );
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function nullable(array $schema): array
    {
        if (isset($schema['type']) && is_string($schema['type'])) {
            return array_merge($schema, ['type' => [$schema['type'], 'null']]);
        }
        if (isset($schema['enum']) && is_array($schema['enum'])) {
            return array_merge($schema, ['enum' => array_values(array_unique(array_merge($schema['enum'], [null]), SORT_REGULAR))]);
        }
        if ($schema === [] || $schema === ['x-document' => true]) {
            return $schema;
        }

        return ['anyOf' => [$schema, ['type' => 'null']]];
    }
}
