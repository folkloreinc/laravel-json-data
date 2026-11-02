# Roadmap of 1.0

1.0 is built on the `v1.x` branch in slices named by theme. Pre-releases (`v1.0.0-alpha.N`, then beta and RC) are tagged when a part is ready, and each item says the release that shipped it; what each release contains is in the [CHANGELOG](../CHANGELOG.md). The pilots pull the slices: Niche, through `laravel-folklore` 2, makes the JSON columns the source of truth of its structures, and the contests platform is the widest user of `JsonDataCast`. 1.0.0 ships before `laravel-folklore` 2.0.0, which requires it.

Index: [#1](https://github.com/folkloreinc/laravel-json-data/issues/1). Each item becomes a sub-issue of it when its work starts.

## Rules

- **The schema is the package's only format** ([laravel-folklore's decision 0020](https://github.com/folkloreinc/laravel-folklore/blob/v2.x/docs/decisions/0020-json-schema.md)). The 1.x declarations stay in `laravel-folklore`, through the declarations hook.
- **Nothing of the other Folklore packages:** what the package doesn't know comes through hooks.
- Until the release candidate, an alpha can break the previous one: the CHANGELOG lists the breaking changes.
- Releases are tagged from a green CI on `v1.x`.

## The extraction (1.0.0-alpha.1)

The behaviour of `laravel-folklore` 2.0.0-alpha.3, in its own package ([laravel-folklore's decision 0021](https://github.com/folkloreinc/laravel-folklore/blob/v2.x/docs/decisions/0021-json-data-package.md), #2).

- [x] The JSON data code of `laravel-folklore`, with its history, under `Folklore\JsonData\`
- [x] Hooks for what the package doesn't know: declarations of models without a schema, values unwrapped into models, relations synced by the application, models of the rewrite command
- [x] The cast's tests on fixtures that declare their columns through the hook, on SQLite, MySQL and PostgreSQL (`jsonb`)
- [x] Tooling, CI, release workflow, docs; on Packagist
- [x] `laravel-folklore` requires it, with the aliases under its 1.x names (its 2.0.0-alpha.4)

## The schema, first slice (1.0.0-alpha.2)

- [x] `jsonDataSchema()` (`HasJsonDataSchema`) and the fluent nodes, compiled into the cast's declarations: references, `extract()`, `JsonData::fill()` from a flat input, unknown keys refused under `enforceMap()`, locales of translatable nodes (#7)
- [x] Reference paths with a key and no Eloquent relation (#4)
- [x] Commands named under `json-data:` ([decisions/0022](decisions/0022-command-names.md), #9)

## Syncing stored rows (1.0.0-alpha.3)

- [x] `json-data:sync-references`: sync the relations of stored references, as a save would, for rows written without model events (imports, `saveQuietly()`, SQL, immutable rows, upgraded sites), with `--model`, `--chunk` and `--dry-run` ([#5](https://github.com/folkloreinc/laravel-json-data/issues/5)). Needed before Niche's first import, since the tables are indexes of the JSON

## Validation rules

- [x] `Json::rules($model, $attribute)`: the schema compiled into Laravel validation rules on the flat input keys (`required()`, `nullable()`, `min()`, `max()`, `pattern()`, types, enums, references, nested lists and objects), for form requests. Laravel rules only, with no dependency (decided on 2026-10-23) ([#15](https://github.com/folkloreinc/laravel-json-data/issues/15), from [#6](https://github.com/folkloreinc/laravel-json-data/issues/6)) (1.0.0-alpha.3)
- [ ] Validating on save, opt-in (`validatesOnSave()`) ([#16](https://github.com/folkloreinc/laravel-json-data/issues/16))

## JSON Schema export

- [ ] `toJsonSchema()`: a standard JSON Schema (draft 2020-12) of a column, with `x-reference` and `x-translatable` extensions, for API specs and front-end types ([#17](https://github.com/folkloreinc/laravel-json-data/issues/17))

## Migrating 1.x sites (1.0.0-alpha.5)

What sites on `laravel-folklore` 1.1 need to move their JSON data and declarations to 2.0, found while migrating urbania.media and two other 1.1 sites with `folklore:upgrade --schemas` ([laravel-folklore#151](https://github.com/folkloreinc/laravel-folklore/pull/151)).

- [x] The sync after a `saved` listener that saves the model again, as eloquent-sluggable's ([#22](https://github.com/folkloreinc/laravel-json-data/issues/22))
- [x] `json-data:rewrite-references --from-column=`: references whose identifiers are of another column than their key's, such as `media://12` by id where `media` is mapped by uid ([#23](https://github.com/folkloreinc/laravel-json-data/issues/23))
- [x] `JsonData::load()` with a depth: the references of the models that references resolve to, such as nested blocks ([#24](https://github.com/folkloreinc/laravel-json-data/issues/24))
- [x] `Json::relation()`: a reference node named by its relation, without a key, as a 1.x declaration ([#25](https://github.com/folkloreinc/laravel-json-data/issues/25))
- [x] `deletesUnreferenced()`: the `delete` option of 1.x on a reference node ([#26](https://github.com/folkloreinc/laravel-json-data/issues/26))
- [x] `paths()`: reference nodes at paths under a node, without describing its structure ([#27](https://github.com/folkloreinc/laravel-json-data/issues/27))

## Beta and release candidate

- [ ] Public API frozen: the schema's nodes, the hooks, the commands; `laravel-folklore` 2 and the pilot on the beta
- [ ] Docs complete; the spec's examples run as tests
- [ ] RC, then 1.0.0, before `laravel-folklore` 2.0.0

## After 1.0.0

- Rows computed from JSON entries, for list items that repeat the same related model with different attributes (one person with two roles), if a project needs them
- 2.0, later: remove what 1.x keeps for laravel-folklore's adapter (the 1.x options of a declaration, `JsonDataCast::MEDIAS_HANDLE`)

## Open questions

- Whether Panneau 5's `laravel-folklore` plugin builds fields from the schema (after `laravel-folklore` 2.0).
