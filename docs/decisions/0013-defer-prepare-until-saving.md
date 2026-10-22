# 0013: Prepare JSON references when the model is saved

- Status: accepted
- Date: 2026-10-22

## Context

A reference handler's `prepare()` turns what a form or a seeder gives into a model ([0006](0006-reference-handlers.md)). For media, it can ingest an uploaded file or a URL with `Mediatheque::ingest()` ([0008](0008-media-references.md)).

If `prepare()` runs in the cast's `set()`, when the value is assigned:

- a file is ingested before the save is known to succeed, for example before validation in a model listener or a database constraint fails;
- the owner's attributes may not be set yet. Applications that scope media by tenant (`MediaReferences::ingestUsing()`) need them when the media is created.

Decision 0006 left this open.

## Decision

- `set()` keeps references, `null` and lists as in 1.x, and keeps every other value **pending** on the model, without calling the handler. The raw attribute keeps the reference stored before at that path.
- On the model's **`saving` event**, the cast prepares the pending values, checks them with `allows()`, and writes their references into the raw attribute before the query runs.
- The cast registers its `saving` listener itself, once per model class, so models need no trait.
- The 1.x callables and macros (`before_set`, `set`) still run in `set()`, so that sites that rely on them see no change.
- Repositories run `saveData()` in a database transaction, so that a save that fails after `saving` rolls back the media it ingested.

The full rules are in [json-data.md](../json-data.md#writing).

## Consequences

- Assigning a file to a JSON column ingests nothing until the model is saved; a save that throws before `saving` ingests nothing.
- Reading the column before the save returns pending values as assigned, not resolved models.
- A model saved without events (`saveQuietly()`) keeps its pending values for the next save.
- Inside a repository's transaction, a failed save rolls back the media row, and the mediatheque doesn't start its pipeline, which it dispatches after the commit. The stored file is an orphan that `mediatheque:prune` deletes.
- Outside a transaction, a failed save can leave a media without a usage. Whether the mediatheque prunes media that were never used is settled with it before alpha 1 ([roadmap.md](../roadmap.md#open-questions)).
