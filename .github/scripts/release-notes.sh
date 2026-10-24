#!/usr/bin/env bash
# Prints the section of CHANGELOG.md for a version, without its heading:
#
#   .github/scripts/release-notes.sh 1.0.0-alpha.1 [CHANGELOG.md]
#
# Fails if the changelog has no dated section for the version, or an empty one.

set -euo pipefail

version="${1:?Usage: release-notes.sh <version> [changelog]}"
changelog="${2:-CHANGELOG.md}"

if ! grep -qE "^## \[${version//./\\.}\] - [0-9]{4}-[0-9]{2}-[0-9]{2}$" "$changelog"; then
    echo "CHANGELOG.md has no section for ${version}: add a \"## [${version}] - YYYY-MM-DD\" section first." >&2
    exit 1
fi

notes="$(awk -v heading="## [${version}] - " '
    index($0, heading) == 1 { found = 1; next }
    found && /^## \[/ { exit }
    found && /^\[[^]]+\]: / { exit }
    found { print }
' "$changelog")"

# Trim the blank lines around the section.
notes="$(printf '%s\n' "$notes" | sed -e '/./,$!d' | sed -e ':a' -e '/^\n*$/{$d;N;ba' -e '}')"

# Relative links point to files of the repository, which a release page doesn't serve:
# make them absolute, at the tag.
repository="${GITHUB_REPOSITORY:-folkloreinc/laravel-json-data}"
notes="$(printf '%s\n' "$notes" | sed -E "s#\]\((\./)?([^):#][^):]*)\)#](https://github.com/${repository}/blob/v${version}/\2)#g")"

if [ -z "$notes" ]; then
    echo "The section of ${version} in CHANGELOG.md is empty." >&2
    exit 1
fi

printf '%s\n' "$notes"
