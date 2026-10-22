# 0006: Reference handlers per key

- Status: accepted
- Date: 2026-10-22

## Context

1.x customizes a relation in JSON data with string-keyed static macros (`JsonDataCast::macro('App\Models\Game:medias:before_set', …)`) or callables in the path options (`get`, `set`, `before_set`). Several sites copied the same trait (`SyncsJsonDataRelations`) to accept an uploaded file or a URL in a media field and create the media before writing the reference.

The plan in [#56](https://github.com/folkloreinc/laravel-folklore/issues/56#issuecomment-6271513875) replaced the macros with relation handlers, registered once for a relation name across all models. With reference keys ([0005](0005-json-reference-keys.md)), what a value points to is known from its key, and the relation name is only used to sync.

## Decision

- Handlers attach to a **key** (a reference type), not to a relation name: preparing and resolving a value depend on what it points to.
- Syncing depends on the kind of Eloquent relation, which the cast already handles (`BelongsToMany` and its morph forms, `HasMany` and its morph forms, `BelongsTo`). The `sync` option stays for special cases.
- A handler implements `ReferenceHandler`: `prepare()` turns what forms and seeders give into a model, `resolveMany()` resolves identifiers in one query, `allows()` checks that the owner may refer to a model, on write (an error) and on read (the reference reads as `null`).
- Keys mapped without a handler get a **default handler**: `prepare()` takes a model, an entity, an identifier or a reference; `resolveMany()` is a `whereIn()` on the identifier column.
- A path can use another handler (`'using' => Handler::class`).
- The 1.x macros and callables keep working, deprecated, run before the handler, until 3.0.

## Consequences

- The medias module ships one handler for media ([0008](0008-media-references.md)); the sites' copies of `SyncsJsonDataRelations` go away.
- Tenancy checks live in `allows()`, in one place per key, instead of in each model.
- Open, see [roadmap.md](../roadmap.md#open-questions): whether `prepare()` is deferred until the model's `saving` event, and whether the fallback of unmapped keys resolves through the owner's relation, as 1.x did.
