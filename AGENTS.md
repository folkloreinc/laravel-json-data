# AGENTS.md

Guidance for AI coding agents and human contributors working on this repository.

## Language: English only

`folklore/laravel-json-data` is a public package. Everything in this repository must be written in English, even when the request or the discussion happens in another language (French, for instance): code, comments, exception and console messages, commit messages, pull requests, issues and documentation.

## What this package is

References to models in the JSON columns of Eloquent models (`JsonDataCast`, the `JsonData` registry of keys, reference handlers), and the schema of JSON columns. It requires only `illuminate/*` and knows nothing of the other Folklore packages.

It was extracted from `folklore/laravel-folklore` 2 ([decision 0021](https://github.com/folkloreinc/laravel-folklore/pull/107) of that package), which requires it and adds, on top of it, its 1.x declarations (`getJsonDataRelations()`, `getJsonDataColumnExtract()`, macros), its entities, its repositories and its media references. What the package doesn't know comes through hooks (`docs/json-data.md`, "Hooks"): don't add a dependency on another Folklore package here.

Read [`docs/json-data.md`](docs/json-data.md) and [`docs/decisions/`](docs/decisions/) before changing the cast. The roadmap is tracked in [#1](https://github.com/folkloreinc/laravel-json-data/issues/1).

## Working rules

- `laravel-folklore` 2 and its sites depend on this package. Prefer additive, opt-in changes whose defaults keep the current behaviour, and deprecate before removing.
- Every change comes with tests (a regression test for each bug fix) and must pass CI before it lands.
- A change that `laravel-folklore` needs to follow gets a linked pull request there.

## Releases

Released from `v1.x` on Packagist, in pre-releases until 1.0.0:

- **Versions:** `v1.0.0-alpha.N`, then `v1.0.0-beta.N`, `v1.0.0-rc.N` and `v1.0.0`. Until the release candidate, an alpha can break the previous one. Composer aliases `v1.x` as `1.x-dev`.
- **Changelog:** every change that users will notice goes in the `[Unreleased]` section of `CHANGELOG.md`, in the same pull request, with breaking changes under **Breaking changes**.
- **Only from a green commit of `v1.x`:** `.github/workflows/release.yml` runs on `v1.*` tags. It refuses a tag that isn't on `v1.x`, or whose commit doesn't have the `Composer, Pint and Larastan` job and every `PHP …` job of the tests green (`.github/scripts/check-green.sh`). It publishes the GitHub release with the changelog section (`.github/scripts/release-notes.sh`), marked as a pre-release for alpha, beta and RC versions.

Steps:

1. Open a release pull request against `v1.x` that moves the `[Unreleased]` changes to a `## [1.0.0-alpha.1] - YYYY-MM-DD` section, with the breaking changes since the previous alpha first, and adds its link at the bottom.
2. Merge it, and wait for CI to pass on the merge commit of `v1.x`.
3. Tag that commit and push the tag:

   ```bash
   git switch v1.x && git pull
   git tag -a v1.0.0-alpha.1 -m "v1.0.0-alpha.1"
   git push origin v1.0.0-alpha.1
   ```

4. The `Release` workflow checks the tag and CI, and publishes the GitHub release. Packagist picks up the tag.
5. Check that [Packagist](https://packagist.org/packages/folklore/laravel-json-data) lists the version, then update `laravel-folklore`.

A tag that fails the checks publishes nothing: delete it (`git push origin :refs/tags/v1.0.0-alpha.1`), fix the cause, and tag again. Never move a tag that Packagist already has.

## Development

- Requirements: PHP `^8.3`, Laravel 12 and 13.
- Install dependencies: `composer install`
- Run tests: `composer test` (PHPUnit with Orchestra Testbench; suites in `tests/Unit` and `tests/Feature`). They use SQLite in memory by default. To run them against MySQL or PostgreSQL, set `DB_DRIVER=mysql` or `DB_DRIVER=pgsql` with `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD`; the database is wiped before each test.
- Most fixtures declare their JSON columns through `JsonData::declareUsing()` (`tests/DeclaresJsonData.php`, registered in `tests/TestCase.php`), as laravel-folklore's adapter does. `tests/Feature/Fixtures/Offer.php` declares its column with a schema (`JsonDataSchemaTest`).
- Format code: `composer format` (Laravel Pint, `laravel` preset); check it with `composer lint`.
- Static analysis: `composer analyse` (Larastan at the maximum level). The errors of the code that came from laravel-folklore 1.x are listed in `phpstan-baseline.neon`: never add new errors to it, fix them instead. New code passes the maximum level without a baseline entry.
- CI (GitHub Actions): `.github/workflows/tests.yml` runs the tests on PHP 8.3 to 8.5 × Laravel 12 and 13 with SQLite, and on PHP 8.4 × Laravel 12 and 13 with MySQL 8.4 and with PostgreSQL 17 (the fixtures' JSON columns are `jsonb` there); `.github/workflows/code-quality.yml` runs `composer validate`, Pint and Larastan.
- Layout: `src` (PSR-4, namespace `Folklore\JsonData\`), `tests`, `docs`.
