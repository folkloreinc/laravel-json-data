<?php

namespace Folklore\Eloquent;

use Folklore\Eloquent\Exceptions\InvalidJsonReference;
use Illuminate\Database\Eloquent\Model;
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
     * Forgets every key and turns `enforceMap()` off. For tests.
     *
     * @internal
     */
    public static function flush(): void
    {
        self::$map = [];
        self::$enforced = false;
    }
}
