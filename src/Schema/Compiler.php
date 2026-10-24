<?php

namespace Folklore\JsonData\Schema;

use Folklore\JsonData\Contracts\LocaleResolver;
use Folklore\JsonData\Exceptions\InvalidJsonData;
use Illuminate\Container\Container;
use WeakMap;

/**
 * Compiles a schema into what the cast and `JsonData::fill()` read: the declarations of
 * its reference paths and extracted columns, in the shape of the declarations hook, and
 * the keys of the flat input it reads. Memoized per schema.
 *
 * @internal
 */
final class Compiler
{
    /**
     * @var WeakMap<ObjectType, array{references: list<array<string, mixed>>, columns: array<string, string>, inputs: array<string, array{list<string>, Type}>}>|null
     */
    private static ?WeakMap $compiled = null;

    /**
     * @var list<array<string, mixed>>
     */
    private array $references = [];

    /**
     * @var array<string, string>
     */
    private array $columns = [];

    /**
     * @var array<string, array{list<string>, Type}>
     */
    private array $inputs = [];

    /**
     * @return array{references: list<array<string, mixed>>, columns: array<string, string>, inputs: array<string, array{list<string>, Type}>}
     */
    public static function compile(ObjectType $schema): array
    {
        self::$compiled ??= new WeakMap;
        if (isset(self::$compiled[$schema])) {
            return self::$compiled[$schema];
        }

        $compiler = new self;
        $compiler->walk($schema, [], false);

        return self::$compiled[$schema] = [
            'references' => $compiler->references,
            'columns' => $compiler->columns,
            'inputs' => $compiler->inputs,
        ];
    }

    /**
     * @param  list<string>  $segments  The path of the node, with `*` for the items of lists and maps.
     */
    private function walk(Type $node, array $segments, bool $repeated): void
    {
        if ($repeated && ! is_null($node->getInput())) {
            throw InvalidJsonData::invalidSchema('A node under a list or a map can\'t be read from an input key: ['.implode('.', $segments).'].');
        }

        match (true) {
            $node instanceof ObjectType => $this->walkObject($node, $segments, $repeated),
            $node instanceof ListType, $node instanceof MapType => $this->walk($node->getOf(), [...$segments, '*'], true),
            $node instanceof ScalarType => $this->walkScalar($node, $segments, $repeated),
            $node instanceof DocumentType => $this->walkDocument($node, $segments),
            default => null,
        };
    }

    /**
     * @param  list<string>  $segments
     */
    private function walkObject(ObjectType $node, array $segments, bool $repeated): void
    {
        foreach ($node->getProperties() as $name => $property) {
            $path = [...$segments, $name];
            // The root's properties are read by their name, others only by their input key
            $input = $property->getInput() ?? (count($segments) === 0 ? $name : null);
            if (! $repeated && ! is_null($input)) {
                if (isset($this->inputs[$input])) {
                    throw InvalidJsonData::invalidSchema("Two nodes are read from the input key [{$input}].");
                }
                $this->inputs[$input] = [$path, $property];
            }
            $this->walk($property, $path, $repeated);
        }
    }

    /**
     * @param  list<string>  $segments
     */
    private function walkScalar(ScalarType $node, array $segments, bool $repeated): void
    {
        $path = implode('.', $segments);

        if ($node instanceof ReferenceType) {
            $this->references[] = $this->reference($node, $node->isTranslatable() ? $path.'.*' : $path);
        }

        $column = $node->getExtract();
        if (is_null($column)) {
            return;
        }
        if ($repeated) {
            throw InvalidJsonData::invalidSchema("A node under a list or a map can't be extracted into a column: [{$path}].");
        }
        if (! $node->isTranslatable()) {
            $this->column($path, $column);

            return;
        }
        if (! str_contains($column, '{locale}')) {
            throw InvalidJsonData::invalidSchema("A translatable node is extracted into a column per locale, named with a {locale} placeholder: [{$path}].");
        }
        $locales = Container::getInstance()->make(LocaleResolver::class);
        foreach ($locales->locales() as $locale) {
            $this->column($path.'.'.$locale, str_replace('{locale}', $locale, $column));
        }
    }

    /**
     * @param  list<string>  $segments
     */
    private function walkDocument(DocumentType $node, array $segments): void
    {
        foreach ($node->getReferences() as $reference) {
            $this->references[] = $this->reference($reference, implode('.', [...$segments, '**']));
        }
    }

    /**
     * The declaration of a reference path, as the declarations hook gives it.
     *
     * @return array<string, mixed>
     */
    private function reference(ReferenceType $node, string $path): array
    {
        return array_filter([
            'path' => $path,
            'key' => $node->getKey(),
            'relation' => $node->getRelation(),
            'using' => $node->getUsing(),
        ], fn (?string $option) => ! is_null($option));
    }

    private function column(string $path, string $column): void
    {
        if (in_array($column, $this->columns, true)) {
            throw InvalidJsonData::invalidSchema("Two nodes are extracted into the column [{$column}].");
        }
        $this->columns[$path] = $column;
    }
}
