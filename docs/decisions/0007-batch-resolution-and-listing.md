# 0007: Batch resolution, memoization and reference listing

- Status: accepted
- Date: 2026-10-19, updated 2026-10-22

## Context

In 1.x, `JsonDataCast::get()` decodes the column and resolves every reference on each access, through the owner's relation (`$model->medias->find($id)`), so a list of 50 models with media in their data lazy-loads the relation 50 times. Sites set `lazy` on relations to avoid it, and then had to load them by hand, or got `null`. Nothing lists the references a model holds, so `laravel-mediatheque` 2 can't keep its usage graph in sync from JSON data.

## Decision

- The references of a model resolve with one query per key, not per reference.
- The references of a **collection** resolve together, with one query per key for the whole collection: `JsonData::load($models)`, and `withJsonReferences()` on repositories.
- The decoded and resolved value is **memoized** per model and column, until the raw attribute changes.
- Repositories merge the JSON columns they list, not only `data`.
- `JsonData::references($model)` **lists the references** of a model (column, path, key, identifier, relation) without resolving them.
- A **recursive wildcard** (`body.**`) matches references at any depth, for documents such as ProseMirror. Only mapped keys are recognized under it.

## Consequences

- `lazy` is no longer needed; it is deprecated.
- Syncing no longer calls `refresh()` on the model.
- `laravel-mediatheque` 2 builds its usage graph from the listing.
