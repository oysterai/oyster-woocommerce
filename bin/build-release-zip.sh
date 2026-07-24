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

echo "Built dist/$ZIP_NAME (v$VERSION, from $(git rev-parse --short HEAD))"
