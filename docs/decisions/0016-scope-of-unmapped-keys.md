# 0016: Unmapped keys keep the scope of 1.x; mapped keys resolve by their column

- Status: accepted
- Date: 2026-10-23

## Context

In 1.x, `JsonDataCast` resolves `relation://id` through the owner's loaded relation (`$model->medias->find($id)`): only the rows attached to the owner resolve. Sites rely on it without saying so: since 1.1.8, a menu of the contests platform that points to a page of another contest no longer resolves, because syncing no longer moves that page ([#64](https://github.com/folkloreinc/laravel-folklore/pull/64)).

The default handler of 2.0 resolves a mapped key by its column, with one `whereIn()` ([0006](0006-reference-handlers.md)). Applied to every key, a row that isn't attached would resolve again, unless the handler's `allows()` refuses it.

## Decision

- A key that **isn't mapped** (upgraded sites, which declare nothing) resolves as in 1.x: through the owner's relation of the same name, so only the rows attached to the owner resolve; a `BelongsTo` gives its related model; `lazy` keeps its meaning. `JsonData::load()` will batch it by eager-loading the relation on a whole collection.
- A **mapped** key resolves by its column through its handler (`ModelReferences` by default: one `whereIn()` per key), and the handler's `allows()` does the scoping.

## Consequences

- Upgraded sites read the same models as in 1.x.
- A site that maps a key to a model changes how it is scoped: the rows it should not read are refused by an `allows()` (a tenant check), not by an attachment.
- The default handler is `Folklore\Eloquent\ModelReferences`, which applications extend.
