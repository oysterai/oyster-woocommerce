#!/usr/bin/env bash
#
# Builds a distributable plugin zip from the current HEAD commit — not the
# working tree, so uncommitted changes never leak into a release. Packages
# only what's committed to git, minus the dev-only files listed in
# .gitattributes (export-ignore): this script itself, callouts.md, .gitignore,
# .gitattributes. No Composer/npm step — this plugin is intentionally
# dependency-free, so `git archive` alone is a complete, self-contained build.
#
# Usage: bin/build-release-zip.sh
# Output: dist/oyster-woocommerce-<version>.zip

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

SLUG="oyster-woocommerce"
MAIN_FILE="$SLUG.php"

if [ ! -f "$MAIN_FILE" ]; then
  echo "error: $MAIN_FILE not found — run this from the plugin repo." >&2
  exit 1
fi

VERSION=$(sed -n -E 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]+([0-9][0-9.]*).*/\1/p' "$MAIN_FILE" | head -1)
if [ -z "$VERSION" ]; then
  echo "error: could not read a Version from $MAIN_FILE header." >&2
  exit 1
fi

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  echo "warning: tracked files have uncommitted changes — this zip is built from the last commit (HEAD), not your working tree. Untracked files are irrelevant here (git archive never includes them)." >&2
fi

DIST_DIR="$ROOT_DIR/dist"
ZIP_NAME="${SLUG}-${VERSION}.zip"

mkdir -p "$DIST_DIR"
rm -f "$DIST_DIR/$ZIP_NAME"

git archive --format=zip --prefix="$SLUG/" -o "$DIST_DIR/$ZIP_NAME" HEAD

# Guard: every runtime file present on disk must also be in the zip.
#
# `git archive` only packages *tracked* files, so a runtime file that git
# ignores is dropped silently — it still works locally (the file is on disk)
# and only breaks on merchant sites. That shipped once: .gitignore's
# unanchored `vendor/` also matched lib/plugin-update-checker/vendor/, so
# Parsedown was never committed, every release omitted it, and the
# self-updater fataled with "Class Parsedown not found" the moment it parsed
# a GitHub release body. Nothing caught it because the zip was otherwise
# complete and the plugin itself ran fine.
ZIP_LIST=$(unzip -Z1 "$DIST_DIR/$ZIP_NAME")
MISSING_LIST=""
MISSING_COUNT=0

while IFS= read -r file; do
  if ! printf '%s\n' "$ZIP_LIST" | grep -qxF "$SLUG/$file"; then
    MISSING_LIST="${MISSING_LIST}         ${file}
"
    MISSING_COUNT=$((MISSING_COUNT + 1))
  fi
done < <(find includes lib assets -type f \
  \( -name '*.php' -o -name '*.js' -o -name '*.css' \
     -o -name '*.svg' -o -name '*.png' -o -name '*.jpg' \) | sort)

if [ "$MISSING_COUNT" -gt 0 ]; then
  echo "error: $MISSING_COUNT runtime file(s) exist on disk but are missing from the zip." >&2
  echo "       They are almost certainly untracked or gitignored — 'git archive' ships only tracked files." >&2
  echo "       Check 'git check-ignore -v <path>' for each:" >&2
  printf '%s' "$MISSING_LIST" >&2
  rm -f "$DIST_DIR/$ZIP_NAME"
  exit 1
fi

echo "Built dist/$ZIP_NAME (v$VERSION, from $(git rev-parse --short HEAD))"
echo "Verified $(printf '%s\n' "$ZIP_LIST" | grep -c '\.php$') PHP files packaged; no runtime file missing."
