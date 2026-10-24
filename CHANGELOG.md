# Changelog

All notable changes to `folklore/laravel-json-data` are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

The package is released in pre-releases, tagged `v1.0.0-alpha.N`, then `beta.N` and `rc.N`. Until the release candidate, an alpha can break the previous one: each alpha lists its breaking changes since the previous one under **Breaking changes**.

## [Unreleased]

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
