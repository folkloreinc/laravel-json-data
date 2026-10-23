# JSON data and references (`JsonDataCast`)

Specification of `JsonDataCast` in 2.0. It ships in alpha 1 ([roadmap.md](roadmap.md)). It was designed in [#56](https://github.com/folkloreinc/laravel-folklore/issues/56#issuecomment-6344885526) and is recorded in [decisions 0005 to 0008](decisions/). The 1.x behaviour it starts from is described in [current-state.md](current-state.md#jsondatacast).

Class and method names are settled in [decisions/0014](decisions/0014-names.md).

## Goals

- Data stored by 1.x keeps working: `relation://id` references, `getJsonDataRelations()`, the column extraction, and entities that read resolved models in `data` (`to_entity(data_get($this->data, 'image.fr'))`).
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
use Folklore\Eloquent\JsonData;
use Folklore\Eloquent\JsonReference;

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

## Declaring paths

Models still declare their paths in `getJsonDataRelations($key, $value, $attributes)`, from `HasJsonDataRelations`, for each cast column. A path maps to a relation name, or to an array of options:

```php
// A brand, with an `images` column cast with JsonDataCast
public function getJsonDataRelations($key, $value, $attributes = []): array
{
    return match ($key) {
        'images' => [
            'logo' => 'medias',
            'favicon' => 'medias',
            'gallery.*' => 'medias',
        ],
        default => [],
    };
}

// A game of an upgraded site: only what changes from 1.x
public function getJsonDataRelations($key, $value, $attributes = [])
{
    return [
        'image.*' => 'medias',
        'prizes.*.prize' => ['relation' => 'prizes', 'key' => 'prize'],
    ];
}
```

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
| `key` | **New** | The key to write. Must be mapped, and its model must be the relation's related model |
| `using` | **New** | A handler class for this path only, instead of the key's handler |
| `sync` | Kept | `false` to not sync this path, or a callable `fn (Relation $relation, array $ids, array $options)` |
| `delete` | Kept | Delete the related rows that are no longer referenced, before syncing |
| `skip` | Kept | A callable that skips a value, on read and on write |
| `lazy` | Deprecated | 1.x: resolve only if the relation is loaded. Batch resolution makes it unneeded ([below](#batch-resolution)); kept with its 1.x meaning until 3.0 |
| `get`, `set`, `before_set` | Deprecated | Per-path callables. Kept, run before the handler, until 3.0 |

The string-keyed static macros of 1.x (`JsonDataCast::macro('medias:get', …)`, `'App\Models\Game:medias:before_set'`) keep working through the registry, deprecated: a macro for a relation applies to the paths declared with that relation, before its handler.

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

- Repositories run `saveData()` in a database transaction (in 1.x, only the `Pages` and `Blocks` repositories did). When it rolls back, the media row created by `Mediatheque::ingest()` rolls back with it, and the mediatheque doesn't run its pipeline, which it dispatches only after the commit. The stored file is left without a media; `mediatheque:prune` deletes orphan files ([laravel-mediatheque 2's roadmap](https://github.com/folkloreinc/laravel-mediatheque/blob/v2.x/docs/roadmap.md), alpha 2).
- A model saved outside a transaction (`$model->save()` in application code) can leave a media without a usage, which nothing prunes ([decisions/0019](decisions/0019-media-of-failed-saves.md)). Save it in a transaction to avoid it:

  ```php
  DB::transaction(fn () => $model->save());
  ```

### Partial updates

A repository merges an update into the current value of the column. References that don't resolve (a lazy relation that isn't loaded, a related row that is gone, a reference the owner may no longer read) are put back before the merge, so that writing the value back doesn't replace them with `null`. This is the 1.1.8 behaviour ([#63](https://github.com/folkloreinc/laravel-folklore/pull/63)), kept.

## Reading

`get()` returns the decoded value with each reference replaced by its model, or by `null` when it doesn't resolve, as in 1.x. Entities keep reading resolved models from it.

- **On read, any mapped key whose model is the relation's related model is accepted**: in a relation to media, `medias://12` and `media://med_…` both resolve.
- A mapped key that points to another model is an error: `InvalidJsonReference`.
- A string at a declared path whose key is neither mapped nor, without a map, the name of the relation, is not a reference: it is returned as is. In 1.x, a URL or a reference of another relation stored at a reference path was taken for an id.
- The resolved model is checked with the handler's `allows()`. A refused model reads as `null`, and its reference is kept on the next write.

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

The #56 design passes references to `resolveMany()`. This spec passes identifiers with the context, so that a handler doesn't parse what the cast has already parsed, and so that `MediaReferences` can serve two keys with different columns. To settle in review.

### Default handler

`Folklore\Eloquent\ModelReferences`.

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

After the model is saved, the references at each relation's paths are synced to that relation, as in 1.x, by the kind of relation:

| Relation | Sync |
|---|---|
| `BelongsToMany`, `MorphToMany`, `MorphedByMany` | `sync()` with the referenced identifiers |
| `BelongsTo` | `associate()` the first one, or `dissociate()`; the foreign key is saved (1.1.8, [#61](https://github.com/folkloreinc/laravel-folklore/pull/61)) |
| `HasMany`, `HasOne`, `MorphMany`, `MorphOne` | Rows without a parent are attached; a row of another parent stays there (1.1.8, [#64](https://github.com/folkloreinc/laravel-folklore/pull/64)) |

- Only references whose key resolves to the relation's related model count. References of several keys to the same model (`medias://` and `media://`) are synced together, by the related model's key.
- `sync => false` and a `sync` callable behave as in 1.x.
- Syncing doesn't call `refresh()` on the model anymore: it unsets the synced relations and the memoized values.

Media references sync into `laravel-mediatheque` 2's `medias_pivot`, which is also its usage graph, with its `HasMedias::syncMedias()`, under the handle `json` (`JsonDataCast::MEDIAS_HANDLE`): the sync only replaces its own rows, so a media attached otherwise (`attachMedia($media, 'cover')`) stays ([laravel-mediatheque's decision 0020](https://github.com/folkloreinc/laravel-mediatheque/blob/v2.x/docs/decisions/0020-attachments-by-handle-and-usages.md)). One handle serves every JSON column of a model, since the cast gathers the references of a relation across its columns. A reference whose identifier is not a media key (a URL, a reference of another relation) is not synced; 1.x wrote it to the pivot. Rows that 1.x wrote for JSON references have no handle: an upgraded site removes them before the first sync ([upgrade.md](upgrade.md)).

## Reference listing

An API lists the references of a model, without resolving them:

```php
JsonData::references($brand);              // every cast column
JsonData::references($brand, ['images']);

// Collection<int, JsonReferenceEntry>: key, identifier, target, attribute, path, relation
```

It reads the stored value, not the resolved one, so it lists references that don't resolve too. `laravel-mediatheque` 2 uses it to keep its usage graph in sync, and `folklore:upgrade --check` to report keys that aren't mapped.

`JsonData::parse('media://med_01J…')` returns a `JsonReferenceEntry` (key, identifier, and the key's target, or none for an unmapped key without `enforceMap()`) or `null`, and `JsonData::reference($model)` builds the reference of a model with its first key. `JsonData::keysFor($model)` and `keyFor($model)` list the keys mapped to a model or one it extends.

## Rewriting references

```bash
php artisan folklore:json-references:rewrite --from=medias --to=media --dry-run
```

- Rewrites every reference with the key `--from` to the key `--to`, at the declared paths of every cast column of the models given by `--model` (repeatable), or of the models of `app/Models` and, with the pages module, of its `Page` and `Block`.
- Both keys must be mapped to the same model. Identifiers are converted in batches through the model (`medias://12` → `media://med_…` reads the `uid` of media 12).
- References that don't resolve are left as they are and reported.
- `--dry-run` reports what would change. The command works in chunks (`--chunk`, 500 rows), without model events or global scopes, and can be run again.

`laravel-mediatheque` 2 planned a helper to rewrite JSON columns; this command is that helper, for `laravel-folklore` columns.

## Media references

The medias module registers two keys, when `laravel-mediatheque` 2 is installed:

| Key | Column | Form | Use |
|---|---|---|---|
| `media` | `uid` | `media://med_01J…` | 2.0 |
| `medias` | `id` | `medias://12` | 1.x, kept for upgraded sites |

Both use `Folklore\Eloquent\MediaReferences`, so either one resolves in a relation to media, attached or not ([decisions/0016](decisions/0016-scope-of-unmapped-keys.md)). The module maps first, and so writes by default, the key of `site.medias.reference_key`: `media` for new projects (the default), `medias` for upgraded sites (`folklore:upgrade` will set it), so that front ends that read `medias://` keep working until they move. A relation can still choose with `key`, and a reference of the other key is kept as it is until the rewrite command converts it.

`MediaReferences`:

- `prepare()` accepts a media model, a media entity, a reference of either key, a uid, a 1.x id, a Panneau 5 media value (`['id' => 'med_…']`), an uploaded file, a file or a URL. Files are ingested with `Mediatheque::ingest()`, which replaces the `SyncsJsonDataRelations` traits several sites copied. A URL is downloaded with the medias repository's limits (`site.medias.download`), then ingested, until the mediatheque ingests URLs behind its SSRF guard. Files and URLs are ingested when the owner is saved ([decisions/0013](decisions/0013-defer-prepare-until-saving.md)).
- `resolveMany()` goes through `Mediatheque::resolveMany()`, one query per form.
- Applications plug their tenancy into it:

```php
MediaReferences::ingestUsing(fn (Model $owner) => ['workspace_id' => $owner->workspace_id]);
MediaReferences::allowUsing(fn (Media $media, ?Model $owner) => $media->workspace_id === $owner?->workspace_id);
```

`ingestUsing()` gives the columns of the media ingested for an owner; `allowUsing()` is checked on write (a refusal throws `ReferenceNotAllowed`) and on read (a refused media reads as `null`).

## Repositories

- A repository lists its JSON columns (`$jsonAttributes = ['data', 'images']`). The first one takes the data that isn't fillable, as `$jsonAttributeName` (`data`) does in 1.x, which keeps working, deprecated. Each of the others is given as a whole (`['images' => ['logo' => $media]]`) and merged into its stored value: the keys given replace theirs, the others stay, and the references that don't resolve are put back first. `null` clears it.
- `withJsonReferences()` returns a copy of the repository that loads the references of each result with `JsonData::load()`, so `lazy` is no longer needed. The repository it is called on doesn't change.

## From 1.x

| 1.x | 2.0 | Until 3.0 |
|---|---|---|
| Key = relation name | Key = registry entry; unmapped keys fall back to the relation of the same name | Fallback kept; off with `enforceMap()` |
| `relation://id` resolved with `$model->{relation}->find($id)` | Mapped keys resolved in batches by their handler; unmapped keys through the relation, as in 1.x | — |
| A string at a reference path that isn't a reference of the relation (a URL, `blocks://1` under a `medias` path) taken for an id: written `medias://https://…`, synced to the relation named in it | Not a reference: read as is; written `null` when the handler can't prepare it; not synced | — |
| Values turned into references in `set()` | References kept in `set()`; other values prepared on `saving` | The deprecated callables still run in `set()` |
| `JsonDataCast::macro('{relation}:get')`, `':set'`, `':before_set'`, per model or global | Handlers per key, `using` per path | Macros run, deprecated |
| `get`, `set`, `before_set` options | Handlers | Run, deprecated |
| `lazy` | `JsonData::load()`, `withJsonReferences()` | Kept, deprecated |
| `**` undocumented | `**` specified | — |
| One JSON column per repository (`$jsonAttributeName`) | `$jsonAttributes` | Kept, deprecated |
| `syncRelations()` then `refresh()` | Relations and memoized values unset | — |

Every 1.1 test of the cast passes on 2.0 without changes, except those that assert a deprecated behaviour's notice. The characterization tests added in 1.1.8 ([#60](https://github.com/folkloreinc/laravel-folklore/pull/60)) are the reference.
