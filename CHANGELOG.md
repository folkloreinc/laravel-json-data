# Changelog

All notable changes to `folklore/laravel-json-data` are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

The package is released in pre-releases, tagged `v1.0.0-alpha.N`, then `beta.N` and `rc.N`. Until the release candidate, an alpha can break the previous one: each alpha lists its breaking changes since the previous one under **Breaking changes**.

## [Unreleased]

The schema of JSON columns, first slice ([laravel-folklore's decision 0020](https://github.com/folkloreinc/laravel-folklore/pull/106), [#7](https://github.com/folkloreinc/laravel-json-data/issues/7)).

### Added

- `HasJsonDataSchema`: a model declares the schema of a JSON attribute with `jsonDataSchema(string $attribute): ?ObjectType`, on the instance, memoized per instance and attribute. `JsonData::schema()` returns it.
- The fluent factory `Folklore\JsonData\Schema\Json`, macroable, and its nodes: `string()`, `integer()`, `number()`, `boolean()`, `date()`, `datetime()`, `enum()`, `object()` with `additional()`, `list()`, `map()`, `any()`, `reference()` with `relation()` and `using()`, and `document()` with `references()`. Every node has `required()`, `nullable()`, `default()` and `input()`; scalar nodes have `translatable()` and `extract()`.
- A schema compiles into the cast's declarations, its reference paths and extracted columns, before the declarations hook is asked.
- `JsonData::fill(Model $model, array $input)`: a flat input into the JSON attributes that have a schema. Partial updates keep the paths they don't give, `null` clears a path, unresolved references and pending values are kept, `input()` names are read, and a string given to a translatable node goes into the default locale. Defaults are written for the missing paths.
- `InvalidJsonData`: an input key that is neither fillable nor in a schema is refused when its object is `additional(false)`, which is the default under `JsonData::enforceMap()`. So is a locale that isn't configured.
- A reference path with a key and no relation (`Json::reference()` without `relation()`, or `'key'` without `'relation'` in a declaration) is resolved, checked, loaded in batches, listed with a `null` relation and rewritten, but not synced ([#4](https://github.com/folkloreinc/laravel-json-data/issues/4)).
- The locales of translatable nodes come from `LocaleResolver`, bound in the container to `ConfigLocaleResolver`, which reads the new `json-data.locales` and `json-data.default_locale` config, falling back to `app.locale`. The config is published with the `json-data-config` tag.

### Changed

- The rewrite command is `json-data:rewrite-references`, under the package's name instead of `folklore:` ([#9](https://github.com/folkloreinc/laravel-json-data/issues/9), [decisions/0022](docs/decisions/0022-command-names.md)).

### Deprecated

- `folklore:json-references:rewrite`, kept as an alias of `json-data:rewrite-references` that warns, for laravel-folklore 2 until it moves to the new name. Removed before 1.0.0.

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

[Unreleased]: https://github.com/folkloreinc/laravel-json-data/compare/v1.0.0-alpha.1...v1.x
[1.0.0-alpha.1]: https://github.com/folkloreinc/laravel-json-data/releases/tag/v1.0.0-alpha.1
