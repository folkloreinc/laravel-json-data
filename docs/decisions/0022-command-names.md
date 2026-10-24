# 0022: Console commands are named under `json-data:`

- Status: accepted
- Date: 2026-10-23
- Issue: [#9](https://github.com/folkloreinc/laravel-json-data/issues/9)

## Context

The rewrite command came from `folklore/laravel-folklore` 2 with its name, `folklore:json-references:rewrite`. laravel-folklore's [decision 0021](https://github.com/folkloreinc/laravel-folklore/pull/107) kept that name when JSON data moved to this package, and its [0014](https://github.com/folkloreinc/laravel-folklore/blob/v2.x/docs/decisions/0014-names.md) lists it among its names.

This package knows nothing of the other Folklore packages, and an application can use it without laravel-folklore. A `folklore:` command there reads as if it came from laravel-folklore, and would mix with laravel-folklore's own commands (`folklore:upgrade`) in `php artisan list`. The sync command to come ([#5](https://github.com/folkloreinc/laravel-json-data/issues/5)) would get the same kind of name.

## Decision

**The package's commands are named under `json-data:`, its name, as `namespace:verb-noun`**, like Laravel's own (`queue:prune-batches`, `schedule:clear-cache`):

| Command | Was |
|---|---|
| `json-data:rewrite-references` | `folklore:json-references:rewrite` |
| `json-data:sync-references` ([#5](https://github.com/folkloreinc/laravel-json-data/issues/5)) | `folklore:json-references:sync`, planned |

The class keeps its name, `JsonReferencesRewriteCommand`, with `discoverUsing()`.

**`folklore:json-references:rewrite` stays as an alias that warns, until before 1.0.0.** laravel-folklore 2's tests, upgrade guide and docs call it. It moves to the new name in its next alpha, and the alias is then removed in an alpha of this package, listed under its breaking changes.

## Consequences

- laravel-folklore 2 keeps working on `v1.0.0-alpha.2` without a change, with a deprecation warning when its sites call the old name. Its docs, tests and decision 0014 move to the new name in a pull request of their own.
- The commands of this package are told apart from laravel-folklore's in `php artisan list`.
