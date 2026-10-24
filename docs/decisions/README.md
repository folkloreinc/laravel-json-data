# Decisions

One file per decision. A decision is not edited once accepted: a later one replaces it and links to it.

Decisions 0005 to 0007, 0013 and 0016 were taken in `folklore/laravel-folklore` 2, before JSON data moved to this package. They are copied as they were, with their numbers. Their links to other decisions and to the roadmap point to [laravel-folklore's docs](https://github.com/folkloreinc/laravel-folklore/tree/v2.x/docs), and the class names they use are laravel-folklore's 1.x names (`Folklore\Eloquent\…`), now under `Folklore\JsonData\`. The decisions about the move and the schema are laravel-folklore's [0021](https://github.com/folkloreinc/laravel-folklore/pull/107) and [0020](https://github.com/folkloreinc/laravel-folklore/pull/106); the next decisions of this package start at 0022.

| # | Decision | Status |
|---|---|---|
| [0005](0005-json-reference-keys.md) | JSON reference keys in a global registry | Accepted |
| [0006](0006-reference-handlers.md) | Reference handlers per key | Accepted |
| [0007](0007-batch-resolution-and-listing.md) | Batch resolution, memoization and reference listing | Accepted |
| [0013](0013-defer-prepare-until-saving.md) | Prepare JSON references when the model is saved | Accepted |
| [0016](0016-scope-of-unmapped-keys.md) | Unmapped keys keep the scope of 1.x; mapped keys resolve by their column | Accepted |
| [0022](0022-command-names.md) | Console commands are named under `json-data:` | Accepted |
