#!/usr/bin/env bash
# Checks that CI is green on a commit: the jobs of tests.yml and
# code-quality.yml have all completed successfully. Needs the gh CLI, with
# GH_TOKEN set in GitHub Actions.
#
#   .github/scripts/check-green.sh <owner/repo> <sha>

set -euo pipefail

repo="${1:?Usage: check-green.sh <owner/repo> <sha>}"
sha="${2:?Usage: check-green.sh <owner/repo> <sha>}"

# The job of code-quality.yml, and the prefix of the jobs of tests.yml
# ("PHP 8.3 - Laravel 12.*", "PHP 8.4 - Laravel 13.* - MySQL 8.4"…).
quality="Composer, Pint and Larastan"
tests_prefix="PHP "

runs="$(gh api --paginate "repos/${repo}/commits/${sha}/check-runs" \
    --jq '.check_runs[] | [.name, .status, (.conclusion // "")] | @tsv')"

# One line per job: whether it passed. A job can run more than once (re-runs):
# one success is enough.
jobs="$(printf '%s\n' "$runs" | awk -F '\t' -v quality="$quality" -v prefix="$tests_prefix" '
    $1 == quality || index($1, prefix) == 1 {
        seen[$1] = 1
        if ($2 == "completed" && $3 == "success") passed[$1] = 1
        else if (!($1 in state)) state[$1] = $2 " " $3
    }
    END { for (name in seen) print (name in passed ? "pass" : "fail") "\t" name "\t" state[name] }
' | sort -k2)"

failed=0
if ! printf '%s\n' "$jobs" | awk -F '\t' -v quality="$quality" '$2 == quality { found = 1 } END { exit !found }'; then
    echo "✗ ${quality}: no run on ${sha}" >&2
    failed=1
fi
if ! printf '%s\n' "$jobs" | awk -F '\t' -v prefix="$tests_prefix" 'index($2, prefix) == 1 { found = 1 } END { exit !found }'; then
    echo "✗ Tests: no run on ${sha}" >&2
    failed=1
fi
while IFS=$'\t' read -r result name state; do
    [ -n "$name" ] || continue
    if [ "$result" = pass ]; then
        echo "✓ ${name}"
    else
        echo "✗ ${name}: ${state}" >&2
        failed=1
    fi
done <<<"$jobs"

if [ "$failed" -ne 0 ]; then
    echo "CI isn't green on ${sha}: tag a commit whose checks have all passed." >&2
    exit 1
fi
