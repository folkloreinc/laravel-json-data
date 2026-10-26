# Changelog

All notable changes to `folklore/laravel-json-data` are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

The package is released in pre-releases, tagged `v1.0.0-alpha.N`, then `beta.N` and `rc.N`. Until the release candidate, an alpha can break the previous one: each alpha lists its breaking changes since the previous one under **Breaking changes**.

## [Unreleased]

## [1.0.0-alpha.3] - 2026-10-26

Syncing stored rows ([#5](https://github.com/folkloreinc/laravel-json-data/issues/5)), and the schema of JSON columns, second slice: validation rules ([#15](https://github.com/folkloreinc/laravel-json-data/issues/15), from [#6](https://github.com/folkloreinc/laravel-json-data/issues/6)).

### Breaking changes

- The sync of a relation leaves out the references whose model isn't found through the related model's query, global scopes included, whatever the column of their key. A reference by the related model's key (`medias://999`) was synced without being looked up, which wrote a pivot row to a row that doesn't exist, or failed on a foreign key. A soft-deleted row, or one that a tenant scope hides, is detached on the next sync. The reference stays in the JSON, and `json-data:sync-references` reports it ([#5](https://github.com/folkloreinc/laravel-json-data/issues/5)).

### Added

- `php artisan json-data:sync-references [--model=…] [--chunk=500] [--dry-run]` syncs the relations of the references of stored rows, as a save would, without saving the models and without model events, for rows written by imports, `saveQuietly()` or SQL ([#5](https://github.com/folkloreinc/laravel-json-data/issues/5)). It syncs a row only when its plan changes something, so a second run changes nothing, and reports the rows changed, the keys attached and detached, and the references that don't resolve (listed with `-v`). It finds its models as the rewrite command does, `JsonReferencesRewriteCommand::discoverUsing()` included.
- `JsonDataCast::syncRelations($model, $only, pretend: true)` plans the sync without writing: by relation, the keys it holds (`current`), those to `attach`, `detach` and `delete`, whether it would change (`changed`, `null` when it can't tell) and the references that don't resolve (`unresolved`).
- `JsonData::syncUsing()` takes an optional second callback, which returns the keys the first one has synced for a relation, or `null` when it doesn't sync it. Without it, a plan can't compare the relations such a callback may sync, and the command syncs them on every run.
- `Json::rules(Model $model, ?string $attribute = null)`: the schema of a JSON attribute, or of every attribute that has one, compiled into Laravel validation rules on the keys of the flat input that `JsonData::fill()` reads, for form requests. Types, `min()`, `max()`, `pattern()`, enums, `required()` (which only refuses an empty value on a stored model, and for a node with a default), `nullable()`, nested objects (`array:` under `additional(false)`), lists, maps and the `input()` names.
- The rule objects `Schema\Rules\Reference`, which checks that a reference, a model or an identifier given to a reference node resolves through its handler and is allowed, and `Schema\Rules\Translatable`, which takes a string for the default locale or an object keyed by the locales.
- `illuminate/validation` is required, for `Rule::enum()` and the rule objects.

### Fixed

- A relation declared at several paths takes the options of all its declarations, the first one winning, instead of those of its last declaration only: a `sync` callable or `delete` given at one path was ignored when another path of the relation came after it ([#18](https://github.com/folkloreinc/laravel-json-data/issues/18)).

## [1.0.0-alpha.2] - 2026-10-23

The schema of JSON columns, first slice ([laravel-folklore's decision 0020](https://github.com/folkloreinc/laravel-folklore/pull/106), [#7](https://github.com/folkloreinc/laravel-json-data/issues/7)), and the package's commands under `json-data:`.

### Breaking changes

- The rewrite command is `json-data:rewrite-references`, under the package's name, instead of `folklore:json-references:rewrite` ([#9](https://github.com/folkloreinc/laravel-json-data/issues/9), [decisions/0022](https://github.com/folkloreinc/laravel-json-data/blob/v1.x/docs/decisions/0022-command-names.md)). The old name isn't kept as an alias: scripts, deploy steps and tests that call it move to the new one.

### Added

- `HasJsonDataSchema`: a model declares the schema of a JSON attribute with `jsonDataSchema(string $attribute): ?ObjectType`, on the instance, memoized per instance and attribute. `JsonData::schema()` returns it.
- The fluent factory `Folklore\JsonData\Schema\Json`, macroable, and its nodes: `string()`, `integer()`, `number()`, `boolean()`, `date()`, `datetime()`, `enum()`, `object()` with `additional()`, `list()`, `map()`, `any()`, `reference()` with `relation()` and `using()`, and `document()` with `references()`. Every node has `required()`, `nullable()`, `default()` and `input()`; scalar nodes have `translatable()` and `extract()`.
- A schema compiles into the cast's declarations, its reference paths and extracted columns, before the declarations hook is asked.
- `JsonData::fill(Model $model, array $input)`: a flat input into the JSON attributes that have a schema. Partial updates keep the paths they don't give, `null` clears a path, unresolved references and pending values are kept, `input()` names are read, and a string given to a translatable node goes into the default locale. Defaults are written for the missing paths.
- `InvalidJsonData`: an input key that is neither fillable nor in a schema is refused when its object is `additional(false)`, which is the default under `JsonData::enforceMap()`. So is a locale that isn't configured.
- A reference path with a key and no relation (`Json::reference()` without `relation()`, or `'key'` without `'relation'` in a declaration) is resolved, checked, loaded in batches, listed with a `null` relation and rewritten, but not synced ([#4](https://github.com/folkloreinc/laravel-json-data/issues/4)).
- The locales of translatable nodes come from `LocaleResolver`, bound in the container to `ConfigLocaleResolver`, which reads the new `json-data.locales` and `json-data.default_locale` config, falling back to `app.locale`. The config is published with the `json-data-config` tag.

## [1.0.0-alpha.1] - 2026-10-23

The JSON data of `folklore/laravel-folklore` 2.0.0-alpha.3, in its own package ([laravel-folklore's decision 0021](https://github.com/folkloreinc/laravel-folklore/pull/107), [#1](https://github.com/folkloreinc/laravel-json-data/issues/1)). The changes below are those from laravel-folklore 2.0.0-alpha.3, whose 1.x names stay there.

### Breaking changes

- The namespace is `Folklore\JsonData\`: `JsonDataCast`, `JsonData`, `JsonReference`, `JsonReferenceEntry`, `ReferenceContext`, `ReferenceOperation`, `ModelReferences`, `Exceptions\InvalidJsonReference`, `Exceptions\ReferenceNotAllowed`, `Contracts\ReferenceHandler` and `Console\JsonReferencesRewriteCommand`.
- The cast no longer reads `HasJsonDataRelations` and `HasJsonDataColumnExtract`, which stay in laravel-folklore: a column is declared through `JsonData::declareUsing()` until the schema.
- The cast has no macros (`JsonDataCast::macro()`), which stay in laravel-folklore's cast. A declaration passes callbacks with its `get`, `set` and `before_set` options, or resolves them with its `callbacks` option.
- Entities, the mediatheque's usage graph and the pages models are no longer known: `JsonData::unwrapUsing()`, `JsonData::syncUsing()` and `JsonReferencesRewriteCommand::discoverUsing()` let laravel-folklore plug them in.

### Added

- `JsonData::declareUsing()`: the reference paths and extracted columns of the JSON columns of models without a schema.
- `JsonData::unwrapUsing()`: values that stand for a model, such as entities, turned into the model or its identifier before a handler prepares them.
- `JsonData::syncUsing()`: callbacks that sync a relation instead of the cast.
- `JsonReferencesRewriteCommand::discoverUsing()`: models that the rewrite command looks at by default, besides `app/Models`.
- The `callbacks` option of a declaration, a resolver of its `get`, `set` and `before_set` callbacks by relation.
- `JsonDataServiceProvider`, discovered by Laravel, registers `folklore:json-references:rewrite`.

### Fixed

- The paths of the declarations kept the integer keys of an object at the top level of a column: `{"12": {…}}` matched `*` as `0`, since 1.x merged the paths with `array_merge()`.

### Deprecated

- `JsonDataCast::MEDIAS_HANDLE`, kept for the code that read it in laravel-folklore. Removed in 2.0.

[Unreleased]: https://github.com/folkloreinc/laravel-json-data/compare/v1.0.0-alpha.3...v1.x
[1.0.0-alpha.3]: https://github.com/folkloreinc/laravel-json-data/compare/v1.0.0-alpha.2...v1.0.0-alpha.3
[1.0.0-alpha.2]: https://github.com/folkloreinc/laravel-json-data/compare/v1.0.0-alpha.1...v1.0.0-alpha.2
[1.0.0-alpha.1]: https://github.com/folkloreinc/laravel-json-data/releases/tag/v1.0.0-alpha.1
