# 0005: JSON reference keys in a global registry

- Status: accepted
- Date: 2026-10-22

## Context

In 1.x, the part before `://` in a JSON reference is the name of the relation method: `medias://12` means "call `$model->medias()` and find 12". So:

- renaming a relation breaks stored data;
- a reference can only be read through a model that declares its path, so a recursive path, a listing of references or a JavaScript reader can't tell what it points to;
- a string at a reference path that isn't a reference of that relation (a URL, a reference of another relation) is taken for an id;
- `laravel-mediatheque` 2 adds a second form for media, `media://med_…`, by uid, next to `medias://{id}`.

The plan in [#56](https://github.com/folkloreinc/laravel-folklore/issues/56#issuecomment-6271513875) kept `relation://id` as is. On 2026-10-22 both pilots needed the cast before alpha 2: the contests platform rewrites its JSON data once in its next phase, so the format should be settled before; Niche stores several named images per brand in a JSON column. `JsonDataCast` moved to alpha 1, with reference keys ([#56](https://github.com/folkloreinc/laravel-folklore/issues/56#issuecomment-6344885526)).

## Decision

- A **global registry** maps each key to a model and its identifier column, like Eloquent's morph map: `JsonData::map(['media' => JsonReference::to(Media::class)->by('uid'), 'blocks' => Block::class])`.
- `JsonData::enforceMap()` rejects keys that aren't mapped, for new projects. Without it, an unmapped key falls back to the relation of the same name, as in 1.x, so upgraded sites declare nothing.
- Models still declare paths and relations in `getJsonDataRelations()`. A relation may choose the key it writes (`'key' => 'prize'`).
- **On write**, the key is the relation's `key`, else the first key mapped to the related model, else the relation's name. An existing reference is kept as is, never prefixed twice.
- **On read**, any mapped key whose model is the relation's related model is accepted. A key that points to another model is an error.
- A command rewrites the references of one key to another, with `--dry-run`.

The full rules are in [json-data.md](../json-data.md).

## Consequences

- The stored format doesn't change: `{key}://{identifier}`. Existing data stays valid.
- Renaming a relation no longer breaks data once its key is mapped.
- A reference can be read anywhere from its key alone, which [0006](0006-reference-handlers.md), [0007](0007-batch-resolution-and-listing.md) and the recursive wildcard rely on.
- A relation can store something other than an id (a prize by handle), which 1.x couldn't.
