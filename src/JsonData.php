<?php

namespace Folklore\JsonData;

use Closure;
use Folklore\JsonData\Exceptions\InvalidJsonData;
use Folklore\JsonData\Exceptions\InvalidJsonReference;
use Folklore\JsonData\Schema\Compiler;
use Folklore\JsonData\Schema\Filler;
use Folklore\JsonData\Schema\ObjectType;
use Folklore\JsonData\Schema\Schemas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * The global registry of reference keys in JSON data, like Eloquent's morph map
 * (docs/json-data.md, docs/decisions/0005). It is set once, in a service provider:
 *
 *     JsonData::map([
 *         'media' => JsonReference::to(Media::class)->by('uid')->using(MediaReferences::class),
 *         'prize' => [Prize::class, 'handle'],
 *         'blocks' => Block::class,
 *     ]);
 *
 * Without `enforceMap()`, a key that isn't mapped falls back to the relation of the same
 * name on the model that holds it, as in 1.x.
 */
final class JsonData
{
    /**
     * A key: a letter, then letters, digits, `_` or `-`. Mapped keys are lowercase by
     * convention; unmapped 1.x keys are relation names, which may be in camel case.
     */
    public const string KEY_PATTERN = '[A-Za-z][A-Za-z0-9_-]*';

    /**
     * @var array<string, JsonReference>
     */
    private static array $map = [];

    private static bool $enforced = false;

    /**
     * @var list<Closure(Model, string, mixed, array<array-key, mixed>): (array{references?: iterable<mixed>, columns?: array<string, string>}|null)>
     */
    private static array $declarers = [];

    /**
     * @var (Closure(object, JsonReference|null): mixed)|null
     */
    private static ?Closure $unwrapper = null;

    /**
     * @var list<Closure(Model, string, Relation<Model, Model, mixed>, list<mixed>): bool>
     */
    private static array $syncers = [];

    /**
     * Maps keys to their targets. Can be called more than once: a later call adds keys.
     * Mapping a key again to the same target does nothing; to another target, it throws.
     *
     * @param  array<string, mixed>  $map  A model class, a `[model, column]` pair or a JsonReference, by key.
     */
    public static function map(array $map): void
    {
        $targets = [];
        foreach ($map as $key => $definition) {
            if (preg_match('/^'.self::KEY_PATTERN.'$/', $key) !== 1) {
                throw InvalidJsonReference::invalidKey($key);
            }
            $target = JsonReference::from($key, $definition);
            $existing = self::$map[$key] ?? $targets[$key] ?? null;
            if ($existing !== null && ! $existing->equals($target)) {
                throw InvalidJsonReference::conflictingKey($key, $existing->describe(), $target->describe());
            }
            $targets[$key] = $target;
        }

        self::$map = array_merge(self::$map, $targets);
    }

    /**
     * Maps the given keys, and rejects every key that isn't mapped, on read and on write.
     *
     * @param  array<string, mixed>  $map
     */
    public static function enforceMap(array $map = []): void
    {
        self::map($map);
        self::$enforced = true;
    }

    public static function enforcesMap(): bool
    {
        return self::$enforced;
    }

    /**
     * @return array<string, JsonReference>
     */
    public static function keys(): array
    {
        return self::$map;
    }

    public static function target(string $key): ?JsonReference
    {
        return self::$map[$key] ?? null;
    }

    /**
     * The keys whose target is this model or one it extends, in the order they were mapped.
     *
     * @param  Model|class-string<Model>  $model
     * @return list<string>
     */
    public static function keysFor(Model|string $model): array
    {
        return array_keys(array_filter(
            self::$map,
            fn (JsonReference $target): bool => $target->accepts($model),
        ));
    }

    /**
     * The key written for a model by default: the first one mapped to it.
     *
     * @param  Model|class-string<Model>  $model
     */
    public static function keyFor(Model|string $model): ?string
    {
        return self::keysFor($model)[0] ?? null;
    }

    /**
     * Parses a stored reference. Null when the value is not `{key}://{identifier}`, or,
     * under `enforceMap()`, when its key isn't mapped. Without it, a reference with an
     * unmapped key is returned without a target.
     */
    public static function parse(string $value): ?JsonReferenceEntry
    {
        if (preg_match('#^('.self::KEY_PATTERN.')://(\S+)$#', $value, $matches) !== 1) {
            return null;
        }
        $target = self::$map[$matches[1]] ?? null;
        if ($target === null && self::$enforced) {
            return null;
        }

        return new JsonReferenceEntry($matches[1], $matches[2], $target);
    }

    /**
     * Builds the reference of a model, with the given key or its first mapped key.
     */
    public static function reference(Model $model, ?string $key = null): string
    {
        $key ??= self::keyFor($model) ?? throw InvalidJsonReference::unmappedModel($model::class);
        $target = self::$map[$key] ?? throw InvalidJsonReference::unmappedKey($key);
        if (! $target->accepts($model)) {
            throw InvalidJsonReference::keyForAnotherModel($key, $model::class);
        }
        $identifier = $target->identifierOf($model)
            ?? throw InvalidJsonReference::missingIdentifier($key, $model::class);

        return $key.'://'.$identifier;
    }

    /**
     * Resolves the references of a collection of models together: one query per key for
     * the whole collection, and the relations of unmapped keys eager-loaded.
     *
     * @param  iterable<mixed>  $models
     * @param  list<string>|null  $attributes  The JSON attributes, or every one.
     */
    public static function load(iterable $models, ?array $attributes = null): void
    {
        JsonDataCast::load($models, $attributes);
    }

    /**
     * The references stored in a model's JSON attributes, whether they resolve or not.
     *
     * @param  list<string>|null  $attributes  The JSON attributes, or every one.
     * @return Collection<int, JsonReferenceEntry>
     */
    public static function references(Model $model, ?array $attributes = null): Collection
    {
        return JsonDataCast::references($model, $attributes);
    }

    /**
     * The decoded value of a JSON attribute as it is stored, with its references as
     * `{key}://{identifier}` strings instead of models. A value assigned to a reference
     * path and not saved yet reads as the reference stored before at its path, or null,
     * until the model is saved (docs/json-data.md, "Reading").
     *
     * @return array<array-key, mixed>|null
     */
    public static function stored(Model $model, string $attribute): ?array
    {
        return JsonDataCast::stored($model, $attribute);
    }

    /**
     * The schema of a model's JSON attribute (`HasJsonDataSchema`), or null when it has
     * none. Memoized per instance and attribute.
     */
    public static function schema(Model $model, string $attribute): ?ObjectType
    {
        return Schemas::for($model, $attribute);
    }

    /**
     * Puts a flat input into the JSON attributes of a model that have a schema
     * (docs/json-data.md, "Filling"). Each key that a schema declares (a property of its
     * root, or a node's `input()` name) is put at its path, and the other paths are kept;
     * `null` clears a path. The model's own attributes are left to `fill()`.
     *
     * A key that is neither fillable nor in a schema is refused when the root of one of
     * the model's schemas doesn't allow additional keys, which is the default under
     * `enforceMap()`; otherwise it is ignored.
     *
     * @param  array<array-key, mixed>  $input
     *
     * @throws InvalidJsonData
     */
    public static function fill(Model $model, array $input): void
    {
        (new Filler)->fill($model, $input);
    }

    /**
     * Adds a callback that declares the JSON attributes of models without a schema
     * (docs/json-data.md, "Hooks"), such as laravel-folklore's adapter of its 1.x
     * declarations. It is given the model, the attribute, its decoded value and the
     * model's raw attributes, and returns null when it doesn't declare the attribute, or:
     *
     * - `references`: the reference paths, as `path => relation`, or `path => options`
     *   (`relation`, `key`, `using`, `lazy`, `sync`, `delete`, `skip`, `get`, `set`,
     *   `before_set`, `callbacks`);
     * - `columns`: the paths copied into columns, as `path => column`.
     *
     * The callbacks are asked in the order they were added; the first that declares the
     * attribute wins.
     *
     * @param  Closure(Model, string, mixed, array<array-key, mixed>): (array{references?: iterable<mixed>, columns?: array<string, string>}|null)  $declarer
     */
    public static function declareUsing(Closure $declarer): void
    {
        self::$declarers[] = $declarer;
    }

    /**
     * What is declared for a JSON attribute of a model: its reference paths and the
     * columns it is copied into, from its schema, or else from the declarations hook.
     * Empty when nothing declares it.
     *
     * @param  array<array-key, mixed>  $attributes
     * @return array{references: iterable<mixed>, columns: array<string, string>}
     *
     * @internal
     */
    public static function declarations(Model $model, string $attribute, mixed $value, array $attributes): array
    {
        $schema = Schemas::for($model, $attribute);
        if (! is_null($schema)) {
            $compiled = Compiler::compile($schema);

            return ['references' => $compiled['references'], 'columns' => $compiled['columns']];
        }

        foreach (self::$declarers as $declarer) {
            $declared = $declarer($model, $attribute, $value, $attributes);
            if (is_array($declared)) {
                return [
                    'references' => $declared['references'] ?? [],
                    'columns' => $declared['columns'] ?? [],
                ];
            }
        }

        return ['references' => [], 'columns' => []];
    }

    /**
     * Turns a value that isn't a model, such as an application's entity, into the model or
     * the identifier it stands for, before a handler prepares it (docs/json-data.md,
     * "Hooks"). The callback is given the value and the target of the key being prepared,
     * if any, and returns the value unchanged when it doesn't know it. A later call
     * replaces it.
     *
     * @param  (Closure(object, JsonReference|null): mixed)|null  $unwrapper
     */
    public static function unwrapUsing(?Closure $unwrapper): void
    {
        self::$unwrapper = $unwrapper;
    }

    /**
     * The model or identifier a value stands for, through the callback of `unwrapUsing()`.
     * Models, scalars and arrays are returned as they are.
     */
    public static function unwrap(mixed $value, ?JsonReference $target = null): mixed
    {
        if (! is_object($value) || $value instanceof Model || is_null(self::$unwrapper)) {
            return $value;
        }

        return (self::$unwrapper)($value, $target);
    }

    /**
     * Adds a callback that syncs the references of a relation itself, instead of the sync
     * by kind of relation (docs/json-data.md, "Hooks"). It is given the model, the name
     * of the relation, the relation and the keys of the referenced models, and returns
     * true when it synced them. The callbacks are tried in the order they were added.
     *
     * @param  Closure(Model, string, Relation<Model, Model, mixed>, list<mixed>): bool  $syncer
     */
    public static function syncUsing(Closure $syncer): void
    {
        self::$syncers[] = $syncer;
    }

    /**
     * @return list<Closure(Model, string, Relation<Model, Model, mixed>, list<mixed>): bool>
     *
     * @internal
     */
    public static function syncers(): array
    {
        return self::$syncers;
    }

    /**
     * Forgets every key and hook, and turns `enforceMap()` off. For tests.
     *
     * @internal
     */
    public static function flush(): void
    {
        self::$map = [];
        self::$enforced = false;
        self::$declarers = [];
        self::$unwrapper = null;
        self::$syncers = [];
    }
}
