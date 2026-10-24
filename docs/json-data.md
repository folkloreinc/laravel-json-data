# JSON data and references (`JsonDataCast`)

Specification of `JsonDataCast`. It was built in `folklore/laravel-folklore` 2, designed in [folkloreinc/laravel-folklore#56](https://github.com/folkloreinc/laravel-folklore/issues/56#issuecomment-6344885526) and recorded in [decisions](decisions/) 0005 to 0007, 0013 and 0016, then moved to this package ([laravel-folklore's decision 0021](https://github.com/folkloreinc/laravel-folklore/pull/107)). The 1.x behaviour it starts from is described in laravel-folklore's [current-state.md](https://github.com/folkloreinc/laravel-folklore/blob/v2.x/docs/current-state.md#jsondatacast).

A model declares its JSON columns with `jsonDataSchema()` ([Schema](#schema), [laravel-folklore's decision 0020](https://github.com/folkloreinc/laravel-folklore/pull/106)). For the models without a schema, the cast takes its declarations from a hook ([Hooks](#hooks)): laravel-folklore feeds its 1.x declarations through it. Numbers like #59 below are issues and pull requests of `folkloreinc/laravel-folklore`.

## Goals

- Data stored by laravel-folklore 1.x keeps working: `relation://id` references, and, through laravel-folklore's adapter, `getJsonDataRelations()`, the column extraction, and entities that read resolved models in `data` (`to_entity(data_get($this->data, 'image.fr'))`).
- A reference can be read without the model that holds it: by a recursive path, by the reference listing, by a JavaScript reader. Renaming a relation no longer breaks stored data.
- The references of a whole collection of models resolve in one query per key, and a decoded value is not decoded and resolved again on every access.
- What a reference points to decides how a value is prepared and resolved. The kind of Eloquent relation decides how it is synced.
- Upgraded sites declare nothing new. New projects can be strict.

## Vocabulary

| Term | Meaning |
|---|---|
| Column | A model attribute cast with `JsonDataCast` (`data`, `images`, `body`) |
| Path | Where references are in the column's value: `logo`, `image.*`, `prizes.*.prize`, `body.**` |
| Relation | The Eloquent relation a path is declared with (`medias`, `parent`), used to sync |
| Reference | The stored string: `{key}://{identifier}` (`media://med_01J…`, `medias://12`, `prize://grand-prize`) |
| Key | The part before `://`. In 1.x, the name of the relation method; in 2.0, an entry of the registry |
| Handler | The class that prepares, resolves and checks the values of one key (a `ReferenceHandler`) |

## Stored format

A reference is a string `{key}://{identifier}`:

- `key` matches `[A-Za-z][A-Za-z0-9_-]*` (`JsonData::KEY_PATTERN`). Mapped keys are lowercase by convention; unmapped 1.x keys are relation names, which may be in camel case;
- `identifier` is the value of the key's identifier column, as a string. It is never empty and has no whitespace.

The format is unchanged from 1.x, so JavaScript code that reads `relation://id` today keeps working. Since a key now always means the same model, a reader outside PHP can tell what a reference points to from its key alone.

## The registry of keys

A **global registry** maps each key to a model and its identifier column, like Eloquent's morph map. It is set once, in a service provider:

```php
use Folklore\JsonData\JsonData;
use Folklore\JsonData\JsonReference;

JsonData::map([
    'media' => JsonReference::to(Media::class)->by('uid')->using(MediaReferences::class),
    'medias' => JsonReference::to(Media::class)->by('id')->using(MediaReferences::class),
    'prize' => JsonReference::to(Prize::class)->by('handle'),
    'blocks' => Block::class, // by its key name, with the default handler
]);
```

- A key maps to a class name (by the model's key name, default handler), to `[Model::class, 'column']` (default handler), or to a `JsonReference` (column and handler).
- `map()` can be called more than once; a later call adds keys. Mapping a key twice to different targets throws.
- Modules register their own keys: the medias module registers `media` and `medias` ([below](#media-references)).
- `JsonData::enforceMap([...])` maps the given keys and rejects every key that isn't mapped, on read and on write. New projects use it.
- Without `enforceMap()`, a key that isn't mapped falls back to **the relation of the same name on the model that holds it**, as in 1.x: `blocks://3` in a model with a `blocks()` relation is a block, by the related model's key. That is how upgraded sites keep working without declaring anything.

The registry is read by the cast, the reference listing and the rewrite command. Nothing else keeps a list of keys.

## Schema

A model declares its JSON columns with a schema per attribute (`HasJsonDataSchema`), built with the fluent factory `Json` ([laravel-folklore's decision 0020](https://github.com/folkloreinc/laravel-folklore/pull/106), [#6](https://github.com/folkloreinc/laravel-json-data/issues/6)). This is the package's only way to declare a column; the declarations hook is there for laravel-folklore's 1.x declarations.

```php
use Folklore\JsonData\Contracts\HasJsonDataSchema;
use Folklore\JsonData\JsonDataCast;
use Folklore\JsonData\Schema\Json;
use Folklore\JsonData\Schema\ObjectType;

class Offer extends Model implements HasJsonDataSchema
{
    protected $casts = ['data' => JsonDataCast::class];

    public function jsonDataSchema(string $attribute): ?ObjectType
    {
        return match ($attribute) {
            'data' => Json::object([
                'name' => Json::string()->translatable()->required()->max(120)->extract('name_{locale}'),
                'benefits' => Json::list(Json::string()->max(200))->max(10),
                'seo' => Json::object([
                    'title' => Json::string()->input('seo_title'),
                ]),
                'prize' => Json::reference('prize')->relation('prizes'),
                'source' => Json::reference('revision'),
                'body' => Json::document()->references('media'),
                'status' => Json::enum(OfferStatus::class)->default(OfferStatus::Draft),
            ])->additional(false),
            default => null,
        };
    }
}
```

- The method runs on the instance, so a schema can depend on the model's attributes: the fields of its type, for example. It is memoized per instance and attribute, so a schema that depends on an attribute is built from its value when the schema is first needed. A schema that reads its own JSON attribute while it is built reads it without a schema.
- For an attribute whose schema is `null`, or a model without `HasJsonDataSchema`, the cast asks the declarations hook ([Hooks](#hooks)).
- `JsonData::schema($model, 'data')` returns the schema of an attribute.

### Nodes

| Node | Stored as | Options |
|---|---|---|
| `Json::string()` | A string | `min()`, `max()` (length), `pattern()` |
| `Json::integer()`, `Json::number()` | A number | `min()`, `max()` |
| `Json::boolean()` | A boolean | |
| `Json::date()`, `Json::datetime()` | An ISO 8601 string: `2026-10-23`, `2026-10-23T14:30:00+00:00` | A `DateTimeInterface` given is formatted |
| `Json::enum(BackedEnum::class)`, `Json::enum([...])` | The enum's value | A case given is stored as its value |
| `Json::object([...])` | An object | `additional(bool)`: whether keys outside the schema are kept |
| `Json::list(node)` | An array | `min()`, `max()` (length) |
| `Json::map(node)` | An object with any keys | `min()`, `max()` (number of keys) |
| `Json::any()` | Any value, not looked into | For data the application doesn't describe yet |
| `Json::reference(key)` | `{key}://{identifier}` | `relation()`, `using()` |
| `Json::document()` | A document (ProseMirror JSON…), as given | `references(...)` |

Every node has `required()`, `nullable()`, `default()` and `input()`. A scalar node (a string, a number, a boolean, a date, an enum or a reference) also has `translatable()` and `extract()`.

- `required()`, `nullable()`, `min()`, `max()` and `pattern()` are kept on the nodes for the validation rules, the next slice of the schema. This slice doesn't check them.
- `translatable()` stores the value as an object keyed by locale (`{"fr": "…", "en": "…"}`), from the [locales](#locales).
- `input('seo_title')` reads the node from that key of the flat input ([Filling](#filling)), instead of its name. Not under a list or a map.
- `extract('name_sort')` copies the value into a column of the model when it is written, to sort or search on it, as the `columns` of a declaration. A translatable node is copied into one column per locale, named with a `{locale}` placeholder (`name_{locale}`). Not under a list or a map.
- `default()` is written by `JsonData::fill()` when neither the input nor the stored value has the path ([Filling](#filling)).
- `Json` is macroable: laravel-folklore's medias module adds `Json::media()`, a `Json::reference()` with the key it writes, synced to `medias`. This package doesn't know media.
- Property names are not empty and have no `.` or `*`.

### References

A schema compiles into the declarations of the [hook](#declaring-paths), so a column declared either way behaves the same:

| Node | Declaration |
|---|---|
| `'prize' => Json::reference('prize')->relation('prizes')` | `['path' => 'prize', 'key' => 'prize', 'relation' => 'prizes']` |
| `'image' => Json::media()->translatable()` | `['path' => 'image.*', 'key' => 'media', 'relation' => 'medias']` |
| `'prizes' => Json::list(Json::object(['prize' => Json::reference('prize')]))` | `['path' => 'prizes.*.prize', 'key' => 'prize']` |
| `'body' => Json::document()->references('media', Json::reference('prize')->relation('prizes'))` | `['path' => 'body.**', 'key' => 'media']` and `['path' => 'body.**', 'key' => 'prize', 'relation' => 'prizes']` |

- The key must be mapped ([The registry of keys](#the-registry-of-keys)): it is the key written, and its handler applies, or the node's `using()` handler.
- With `relation()`, the references are synced to that relation of the model ([Syncing](#syncing)), whose related model must be the key's model.
- **Without a relation, a reference is resolved, checked with `allows()`, loaded in batches and listed (with a `null` relation), but not synced** ([#4](https://github.com/folkloreinc/laravel-json-data/issues/4)). Every mapped key of the key's model is accepted at its path, and the rewrite command rewrites it. A record of where a row came from, or of the record a snapshot was taken from, needs no relation.
- `document()->references()` takes keys, or reference nodes to give them a relation. Its references are found at any depth, as `**` ([Paths](#paths)).

### Filling

`JsonData::fill(Model $model, array $input): void` puts a flat input into the JSON attributes of a model that have a schema. Clients send `name`, never `data.name`:

```php
$offer = new Offer;
$offer->fill($input);            // the model's fillable attributes
JsonData::fill($offer, $input);  // its JSON attributes
$offer->save();
```

- **The input keys** are the root properties of each schema, by their name, and the nodes with an `input()` name, at any depth outside lists and maps. A root property with an `input()` name is read from that name only. A nested object is given as an object under its root property (`'seo' => ['title' => '…']`), or by the `input()` names of its nodes.
- **A partial update** replaces the paths it gives and keeps the others. An object given for an object node replaces the properties it gives and keeps the others; a list, a map, a document or `any` replaces the stored value.
- **`null` clears a path**: it is stored as `null`.
- **The input is merged into the stored value**, with its references as `{key}://{identifier}` strings, so the references that don't resolve are kept as they are, as the repositories do since 1.1.8. The values assigned since the model was saved and not prepared yet are kept too.
- **A translatable node**: a string goes into the default locale, and an object keyed by locale replaces the locales it gives and keeps the others. A locale that isn't one of the locales is refused with `InvalidJsonData`.
- **Defaults** are written for the paths that neither the input nor the stored value has: in each object of the value, including those of its lists and maps. They are written into the attributes that the input fills, and into every attribute of a model that doesn't exist yet. A path stored as `null` keeps its `null`.
- **Values** are stored as their nodes store them: an enum case as its value, a date as its ISO 8601 string. Anything else is put as it is given: references are prepared by their handler when the model is saved ([Writing](#writing)), and the other values are checked by the validation rules of the next slice.
- **The model's own attributes stay Eloquent's**: `fill()` doesn't set them.

**Unknown keys.** A key of the input that is neither fillable on the model (`isFillable()`) nor an input key of one of its schemas is refused with `InvalidJsonData`, which lists them, when the root of one of the model's schemas is `additional(false)`. Otherwise it is ignored, as `fill()` ignores an attribute that isn't fillable. A key of an object given for an object node that the node doesn't have is refused when that object is `additional(false)`, and kept otherwise. Nothing is filled when a key is refused.

An object allows additional keys by default, and refuses them by default under `JsonData::enforceMap()`. Sites that keep the 1.x behaviour declare `additional(true)`, or don't declare a schema.

`JsonData::fill()` does nothing for a model without a schema.

### Locales

The locales of translatable nodes, and the default one, come from the `LocaleResolver` bound in the container. The package binds `ConfigLocaleResolver`, which reads its config:

| Config | Default | |
|---|---|---|
| `json-data.locales` | `null`: `[app.locale]` | The locales |
| `json-data.default_locale` | `null`: `app.locale` when it is one of the locales, else the first one | The locale a string goes into |

Publish the config with `php artisan vendor:publish --tag=json-data-config`. An application, or a package, binds its own resolver instead, as laravel-folklore does to read `site.locales`:

```php
$this->app->bind(LocaleResolver::class, SiteLocaleResolver::class);
```

The locales are read when a translatable value is filled, and when a schema with a translatable `extract()` is compiled, once per schema.

### Not in this slice

The validation rules (`Json::rules()`, `validatesOnSave()`) and the JSON Schema export (`toJsonSchema()`) are the next slices ([#6](https://github.com/folkloreinc/laravel-json-data/issues/6)).

## Declaring paths

For a model without a schema, the declarations hook gives, for each cast column of a model, its reference paths and the paths copied into columns. A path maps to a relation name, or to an array of options:

```php
JsonData::declareUsing(fn (Model $model, string $attribute, mixed $value, array $attributes) => match (true) {
    $model instanceof Brand && $attribute === 'images' => [
        'references' => [
            'logo' => 'medias',
            'favicon' => 'medias',
            'gallery.*' => 'medias',
        ],
    ],
    $model instanceof Game => [
        'references' => [
            'image.*' => 'medias',
            'prizes.*.prize' => ['relation' => 'prizes', 'key' => 'prize'],
        ],
        'columns' => ['title.fr' => 'title_fr'],
    ],
    default => null,
});
```

This is the shape of laravel-folklore's 1.x `getJsonDataRelations()` and `getJsonDataColumnExtract()`, which its adapter passes as they are.

### Paths

| Pattern | Matches |
|---|---|
| `logo`, `share.image` | That exact path |
| `*` | One segment: `gallery.*` matches `gallery.0`, `image.*` matches `image.fr` |
| `**` | Any number of segments, at any depth: `body.**` matches every value under `body` |

`**` exists in the 1.x path matcher, but is neither tested nor documented. 2.0 defines it for documents where references can be at any depth, such as ProseMirror JSON. Under `**`:

- on read, only strings that parse as a reference **with a mapped key** whose model is the relation's related model are resolved. Any other string, a URL in particular, is left alone;
- on write, a model or an entity is serialized; a reference is kept; every other value (arrays, scalars, other strings) is left as is, and arrays are walked into;
- the default handler's `prepare()` is not called on identifiers, since a bare string or number deep in a document is not a reference.

### Options

| Option | Status | Meaning |
|---|---|---|
| `relation` | Kept | The relation to sync, and the fallback key. A callable is still accepted, deprecated |
| `key` | **New** | The key to write. Must be mapped, and its model must be the relation's related model. A path with a `key` and no `relation` is resolved and listed but not synced, as `Json::reference()` without a relation ([References](#references)) |
| `using` | **New** | A handler class for this path only, instead of the key's handler |
| `sync` | Kept | `false` to not sync this path, or a callable `fn (Relation $relation, array $ids, array $options)` |
| `delete` | Kept | Delete the related rows that are no longer referenced, before syncing |
| `skip` | Kept | A callable that skips a value, on read and on write |
| `lazy` | Deprecated | 1.x: resolve only if the relation is loaded. Batch resolution makes it unneeded ([below](#batch-resolution)); kept with its 1.x meaning until 3.0 |
| `get`, `set`, `before_set` | Deprecated | Per-path callables. Kept, run before the handler, until 2.0 |
| `callbacks` | **New** | A resolver of `get`, `set` and `before_set` for a path that doesn't give them: `fn (string $name, string $relationName, Model $model): ?callable` |

The string-keyed static macros of 1.x (`JsonDataCast::macro('medias:get', …)`, `'App\Models\Game:medias:before_set'`) are laravel-folklore's: its `Folklore\Eloquent\JsonDataCast` keeps them, and its adapter passes them through `callbacks`, so a macro for a relation applies to the paths declared with that relation, before its handler. This package's cast has no macros.

## Writing

Writing happens in two steps: `set()` sorts the values when they are assigned, and the values that need preparing are turned into references when the model is saved ([decisions/0013](decisions/0013-defer-prepare-until-saving.md)).

### When a value is assigned

`set()` looks at each value at a declared path:

1. `skip` and the deprecated callables (`before_set`, `set`, macros) run first, in `set()`, as in 1.x.
2. **A reference is kept as is** when its key is mapped to the relation's related model, or, without a map, when its key is the relation's name. It is never prefixed twice (fixed in 1.1.8, [#62](https://github.com/folkloreinc/laravel-folklore/pull/62)). A reference written by another key of the same model (`medias://12` where the relation now writes `media://`) is kept too: the rewrite command converts it ([below](#rewriting-references)).
3. `null` is written as `null`. A list at a path that isn't a wildcard is left as is, as in 1.x.
4. **Any other value is pending**: a model, an entity, an identifier, a file, a URL. The cast keeps it on the model, by column and path, and doesn't call the handler yet. In the raw attribute, the path keeps the reference stored before, or `null`, so the raw attribute always holds valid stored data.

Until the model is saved, reading the column returns pending values as they were assigned.

### When the model is saved

On the model's `saving` event, for each pending value:

1. The handler's `prepare()` turns it into a model: a model, an entity, a reference, an identifier, or, for media, a file or a URL, which it ingests.
2. The handler's `allows()` checks that the owner may refer to it. A refusal throws `ReferenceNotAllowed`.
3. The reference is built with the **write key**: the relation's `key` option, else the first key mapped to the related model (as `getMorphClass()` picks the first morph map entry), else the relation's name. The identifier comes from the key's column. It is written into the raw attribute, before the query runs.

A value that can't be prepared throws `InvalidJsonReference` under `enforceMap()`. Without it, the value is written as `null`, as in 1.x, and a deprecation is logged. An exception thrown while preparing stops the save: nothing is written.

- The cast registers its `saving` listener for a model class the first time a model of that class has a pending value, once per event dispatcher. Models need no trait, so upgraded sites declare nothing.
- Listeners and observers that the model registers when it boots run first, so the attributes they set (a tenant, an owner) are known to `prepare()`. Attributes set in `creating` are not: it fires after `saving`.
- A model saved without events (`saveQuietly()`, `withoutEvents()`) doesn't prepare its pending values: their paths keep their stored references, and the values stay pending until the next save with events.

### When the save fails afterwards

`prepare()` can ingest a file, and the save can still fail after `saving`: the query, the sync of relations, or a later listener. The media would then exist without a usage.

- laravel-folklore's repositories run `saveData()` in a database transaction (in 1.x, only the `Pages` and `Blocks` repositories did). When it rolls back, the media row created by `Mediatheque::ingest()` rolls back with it, and the mediatheque doesn't run its pipeline, which it dispatches only after the commit. The stored file is left without a media; `mediatheque:prune` deletes orphan files ([laravel-mediatheque 2's roadmap](https://github.com/folkloreinc/laravel-mediatheque/blob/v2.x/docs/roadmap.md), alpha 2).
- A model saved outside a transaction (`$model->save()` in application code) can leave a media without a usage, which nothing prunes (laravel-folklore's [decision 0019](https://github.com/folkloreinc/laravel-folklore/blob/v2.x/docs/decisions/0019-media-of-failed-saves.md)). Save it in a transaction to avoid it:

  ```php
  DB::transaction(fn () => $model->save());
  ```

### Partial updates

`JsonData::fill()` merges its input into the stored value ([Filling](#filling)). laravel-folklore's repositories merge an update into the current value of the column, from `JsonDataCast::getValueWithUnresolvedReferences($model, $attribute)`. References that don't resolve (a lazy relation that isn't loaded, a related row that is gone, a reference the owner may no longer read) are put back before the merge, so that writing the value back doesn't replace them with `null`. This is the 1.1.8 behaviour ([#63](https://github.com/folkloreinc/laravel-folklore/pull/63)), kept.

## Reading

`get()` returns the decoded value with each reference replaced by its model, or by `null` when it doesn't resolve, as in 1.x. Entities keep reading resolved models from it.

- **On read, any mapped key whose model is the relation's related model is accepted**: in a relation to media, `medias://12` and `media://med_…` both resolve.
- A mapped key that points to another model is an error: `InvalidJsonReference`.
- A string at a declared path whose key is neither mapped nor, without a map, the name of the relation, is not a reference: it is returned as is. In 1.x, a URL or a reference of another relation stored at a reference path was taken for an id.
- The resolved model is checked with the handler's `allows()`. A refused model reads as `null`, and its reference is kept on the next write.

### Stored references

`JsonData::stored($model, 'body')` returns the decoded value of a JSON attribute as it is stored, with its references as `{key}://{identifier}` strings instead of models ([#88](https://github.com/folkloreinc/laravel-folklore/issues/88)). APIs and front ends that read references get them without serializing whole models, and an entity can expose both forms:

```php
public function body(): ?array
{
    return JsonData::stored($this->getModel(), 'body'); // for the resource: `media://med_…`
}

public function bodyMedia(): array
{
    return $this->getModel()->body['media'] ?? [];    // for the code: the resolved models
}
```

- A value assigned to a reference path and not saved yet reads as the reference stored before at its path, or `null`, until the model is saved: it is only prepared when the model is saved ([decisions/0013](decisions/0013-defer-prepare-until-saving.md)).
- Every reference reads as stored, whether it resolves or not, and under recursive paths (`**`).
- An attribute that isn't cast with `JsonDataCast` throws an `InvalidArgumentException`. An empty one reads as `null`.

`getAttributes()` gives the raw JSON string. Serializing the resolved value sends whole models instead of references.

### Memoization

The decoded and resolved value is memoized per model and column, keyed by the raw attribute. It is decoded and resolved again only when the raw attribute changes (`set()`, `fill()`, `refresh()`).

### Batch resolution

Resolving the references of one model loads them with **one query per key**, not one per reference.

The references of a whole collection resolve together, with one query per key for the whole collection:

```php
JsonData::load($games);                          // every cast column
JsonData::load($games, ['data']);                // some columns

$repository->withJsonReferences()->get($query);  // in a repository
```

`JsonData::load()` reads the references of every model, groups them by key, calls each handler's `resolveMany()` once, checks `allows()` for each owner, and seeds each model's memoized value. The relations of unmapped keys are eager-loaded on the collection (`loadMissing()`), `lazy` ones included. A model of the collection read afterwards doesn't query again.

A key that isn't mapped resolves as in 1.x, through the owner's relation of the same name, so only the rows attached to the owner resolve ([decisions/0016](decisions/0016-scope-of-unmapped-keys.md)). A mapped key resolves by its column through its handler, and `allows()` scopes it.

## Handlers

A handler is attached to a **key**, not to a relation name: preparing and resolving a value depend on what it points to, while syncing depends on the kind of relation, which the cast handles.

```php
namespace Folklore\Contracts\Eloquent;

interface ReferenceHandler
{
    /**
     * Turn what forms and seeders give into a model: a model, an entity, a reference,
     * an identifier, a file… Null when the value is not something this handler takes.
     */
    public function prepare(mixed $value, ReferenceContext $context): ?Model;

    /**
     * @param  list<string>  $identifiers  Values of the key's identifier column
     * @return array<string, Model>  Keyed by identifier; unresolved ones are left out
     */
    public function resolveMany(array $identifiers, ReferenceContext $context): array;

    /**
     * Whether the owner may refer to this model. Checked on write (an error) and on read
     * (the reference reads as null).
     */
    public function allows(Model $resolved, ReferenceContext $context): bool;
}
```

`ReferenceContext` is a readonly object with the operation (`ReferenceOperation::Read` or `Write`), the key and its target (`JsonReference`: model, column, handler), the owners' class, the owner model (null in a batch of several owners), the JSON attribute, the declared path (null in a batch of several paths) and the relation name.

The #56 design passed references to `resolveMany()`. It takes identifiers with the context, so that a handler doesn't parse what the cast has already parsed, and so that `MediaReferences` can serve two keys with different columns. Settled in alpha 1.

### Default handler

`Folklore\JsonData\ModelReferences`.

Used by every key mapped without a handler, and by unmapped keys when a value is written.

- `prepare()` takes a model of the key's model, an entity (`ToEntity`, `Entity`), a reference of an accepted key, or an identifier (a string or an integer). Anything else returns `null`.
- `resolveMany()` is one `whereIn()` on the key's column, through the related model's query, so its global scopes (soft deletes, a tenant scope) apply.
- `allows()` returns `true`.

Applications extend it rather than implement the contract from scratch.

### Overriding

- A key's handler is set in the registry (`->using(Handler::class)`).
- A path can use another handler (`'using' => Handler::class`).
- Handlers are resolved from the container, so their dependencies are injected.

## Syncing

After the model is saved, the references at each relation's paths are synced to that relation, as in 1.x, by the kind of relation. The cast does it in a `saved` listener, registered with its `saving` one, so a model is synced however it is saved: `$model->save()`, `create()`, a factory, a seeder, an application repository, or laravel-folklore's base repository ([#97](https://github.com/folkloreinc/laravel-folklore/issues/97)). 1.x only synced in the base repository.

- Only the relations declared in the JSON attributes that changed are synced. A relation gathers its references from every JSON attribute of the model.
- A model saved without events (`saveQuietly()`, `withoutEvents()`) isn't synced, nor are rows written another way (raw SQL, imports). `JsonDataCast::syncRelations($model)` syncs them, and [#86](https://github.com/folkloreinc/laravel-folklore/issues/86) plans a command for stored rows.
- laravel-folklore's base repository doesn't sync a second time.


| Relation | Sync |
|---|---|
| `BelongsToMany`, `MorphToMany`, `MorphedByMany` | `sync()` with the referenced identifiers |
| `BelongsTo` | `associate()` the first one, or `dissociate()`; the foreign key is saved (1.1.8, [#61](https://github.com/folkloreinc/laravel-folklore/pull/61)) |
| `HasMany`, `HasOne`, `MorphMany`, `MorphOne` | Rows without a parent are attached; a row of another parent stays there (1.1.8, [#64](https://github.com/folkloreinc/laravel-folklore/pull/64)) |

- Only references whose key resolves to the relation's related model count. References of several keys to the same model (`medias://` and `media://`) are synced together, by the related model's key.
- `sync => false` and a `sync` callable behave as in 1.x.
- Syncing doesn't call `refresh()` on the model anymore, nor does the base repository after it: it unsets the synced relations and the memoized values.

An application can sync a relation itself, instead of the sync by kind of relation, with `JsonData::syncUsing()` ([Hooks](#hooks)). laravel-folklore's medias module syncs the media into `laravel-mediatheque` 2's `medias_pivot`, which is also its usage graph, under the handle `json`, so that a media attached otherwise stays.

## Reference listing

An API lists the references of a model, without resolving them:

```php
JsonData::references($brand);              // every cast column
JsonData::references($brand, ['images']);

// Collection<int, JsonReferenceEntry>: key, identifier, target, attribute, path, relation
```

It reads the stored value, not the resolved one, so it lists references that don't resolve too. `laravel-mediatheque` 2 uses it to keep its usage graph in sync, and laravel-folklore's `folklore:upgrade --check` to report keys that aren't mapped.

`JsonData::parse('media://med_01J…')` returns a `JsonReferenceEntry` (key, identifier, and the key's target, or none for an unmapped key without `enforceMap()`) or `null`, and `JsonData::reference($model)` builds the reference of a model with its first key. `JsonData::keysFor($model)` and `keyFor($model)` list the keys mapped to a model or one it extends.

## Rewriting references

```bash
php artisan json-data:rewrite-references --from=medias --to=media --dry-run
```

- Rewrites every reference with the key `--from` to the key `--to`, at the declared paths of every cast column of the models given by `--model` (repeatable), or of the models of `app/Models` and those added with `JsonReferencesRewriteCommand::discoverUsing()` (laravel-folklore adds its pages and blocks).
- Both keys must be mapped to the same model. Identifiers are converted in batches through the model (`medias://12` → `media://med_…` reads the `uid` of media 12).
- References that don't resolve are left as they are and reported.
- `--dry-run` reports what would change. The command works in chunks (`--chunk`, 500 rows), without model events or global scopes, and can be run again.
- It was `folklore:json-references:rewrite` in laravel-folklore and in this package's alpha 1 ([decisions/0022](decisions/0022-command-names.md)).

`laravel-mediatheque` 2 planned a helper to rewrite JSON columns; this command is that helper.

## Media references and repositories

Both are laravel-folklore's, on top of this package:

- its medias module maps the `media` (`media://med_…`, by uid) and `medias` (`medias://12`, by id) keys to the mediatheque's media, with its `MediaReferences` handler, which ingests files and URLs and checks the forms it accepts ([laravel-folklore's json-data.md](https://github.com/folkloreinc/laravel-folklore/blob/v2.x/docs/json-data.md#media-references));
- its repositories list their JSON columns (`$jsonAttributes`), merge updates into them, and load the references of their results with `JsonData::load()` (`withJsonReferences()`).

## Hooks

What the package doesn't know, an application or a package sets once, in a service provider:

| Hook | Given | laravel-folklore uses it for |
|---|---|---|
| `JsonData::declareUsing(fn (Model $model, string $attribute, mixed $value, array $attributes): ?array)` | The declarations of a column without a schema (an attribute whose `jsonDataSchema()` returns `null`, or a model without one): `references` and `columns`, as [above](#declaring-paths). The first callback that returns an array wins | Its 1.x declarations and macros |
| `JsonData::unwrapUsing(fn (object $value, ?JsonReference $target): mixed)` | A value that isn't a model, to turn into the model or the identifier it stands for. Returns the value unchanged when it doesn't know it | Its entities (`HasModel`, `ToEntity`, `Entity`) |
| `JsonData::syncUsing(fn (Model $model, string $relationName, Relation $relation, array $ids): bool)` | The relation of a model and the keys of the referenced models. Returns true when it synced them, instead of the cast | The mediatheque's usage graph, under the `json` handle |
| `JsonReferencesRewriteCommand::discoverUsing(fn (): iterable)` | Model classes that the rewrite command looks at by default | Its pages and blocks |
| A `LocaleResolver` bound in the container | The locales of translatable nodes ([Locales](#locales)) | `site.locales` |

`JsonData::flush()` forgets the registry and the hooks, for tests.

## From 1.x

| laravel-folklore 1.x | This package (laravel-folklore 2) | Until this package's 2.0 |
|---|---|---|
| Key = relation name | Key = registry entry; unmapped keys fall back to the relation of the same name | Fallback kept; off with `enforceMap()` |
| `relation://id` resolved with `$model->{relation}->find($id)` | Mapped keys resolved in batches by their handler; unmapped keys through the relation, as in 1.x | — |
| A string at a reference path that isn't a reference of the relation (a URL, `blocks://1` under a `medias` path) taken for an id: written `medias://https://…`, synced to the relation named in it | Not a reference: read as is; written `null` when the handler can't prepare it; not synced | — |
| Values turned into references in `set()` | References kept in `set()`; other values prepared on `saving` | The deprecated callables still run in `set()` |
| `JsonDataCast::macro('{relation}:get')`, `':set'`, `':before_set'`, per model or global | Handlers per key, `using` per path | Macros run in laravel-folklore, deprecated until its 3.0 |
| `get`, `set`, `before_set` options | Handlers | Run, deprecated |
| `lazy` | `JsonData::load()`, `withJsonReferences()` | Kept, deprecated |
| `**` undocumented | `**` specified | — |
| `getJsonDataRelations()`, `getJsonDataColumnExtract()` | `jsonDataSchema()` | Kept in laravel-folklore, through the declarations hook, until its 3.0 |
| One JSON column per repository (`$jsonAttributeName`) | laravel-folklore's `$jsonAttributes` | Kept, deprecated |
| `$jsonAttributeFillable`, `$jsonAttributeExclude`: input keys into the column, in the base repository only; `'*'` stores every key that isn't fillable | `JsonData::fill()`, from the schema, for any code that saves the model; unknown keys refused under `additional(false)` | Kept in laravel-folklore for the models without a schema |
| A fake relation with `sync => false` for a reference that isn't a relation | `Json::reference()` without a relation | — |
| Synced by the base repository, after `saveData()` | Synced by the cast after any save with events | — |
| `syncRelations()` then `refresh()` | Relations and memoized values unset | — |

The characterization tests added in laravel-folklore 1.1.8 ([#60](https://github.com/folkloreinc/laravel-folklore/pull/60)) are the reference, and stay in laravel-folklore as the compatibility suite of its adapter. This package runs their cases on its own fixtures, through the declarations hook. Three of them change on purpose, as the breaking changes of laravel-folklore 2.0.0-alpha.1 say: a string at a reference path that isn't a reference of its relation is neither written nor synced as an id, a reference is synced only to the relation of its path, and a media reference resolves by its key before it is attached.
