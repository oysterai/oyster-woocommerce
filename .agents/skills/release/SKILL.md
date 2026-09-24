---
name: release
description: Cut a release of this plugin. Use when asked to release, publish a version, cut a tag, draft release notes, or ship an update to merchants. Covers why a release must never be created by hand, and what breaks when it is.
---

# Releasing this plugin

This plugin is not on wordpress.org. Merchants get updates because
`Support\Self_Updater` points Plugin Update Checker at this repo's GitHub
Releases, and it downloads **the zip attached to the release**, not GitHub's
auto-generated "Source code" archive.

So a release without that zip attached does not update anybody. That is the
single thing this skill exists to prevent.

## The rule

**A published release is immutable. Assets can only be attached while it is a
draft.**

Uploading afterwards fails with `HTTP 422: Cannot upload assets to an immutable
release`, and you are left with a live release carrying no zip. The only fix at
that point is deleting the release and its tag and starting again, which means
deleting something merchants may already have seen.

Never run `gh release create` without attaching the zip in the same command.

## Cutting a release

1. **Bump the version in both places.** The `Version:` header in the main plugin
   file and `Stable tag:` in `readme.txt`. They must match.

2. **Complete the changelog.** `readme.txt` must describe everything since the
   last released version, not just the change that prompted the bump. Check
   what actually landed:

   ```bash
   git log --no-merges --oneline origin/main..origin/dev
   ```

   If the version has not shipped yet, extend its existing entry rather than
   bumping again.

3. **Get it onto `main`.** Releases are cut from `main`. Open `dev` -> `main`
   and merge it.

4. **Build and verify the zip.**

   ```bash
   bin/build-release-zip.sh
   unzip -Z1 dist/<slug>-<version>.zip | head
   ```

   It builds from `HEAD`, not the working tree, so uncommitted work cannot leak
   in. It fails if a runtime file on disk is missing from the archive.

5. **Create the release.** Preferred: push the tag and let
   `.github/workflows/release.yml` stage the draft with the zip attached.

   ```bash
   git tag v<version> origin/main && git push origin v<version>
   ```

   If the tag push is refused by a repository rule, create the draft directly
   against `main` instead and attach the zip in the same command. Publishing
   creates the tag, so no tag push is needed:

   ```bash
   gh release create v<version> --draft --target main \
     --title "<version> — <headline>" --notes "..." \
     dist/<slug>-<version>.zip
   ```

6. **Check the draft before publishing.**

   ```bash
   gh release view v<version> --json isDraft,assets \
     -q '"draft=\(.isDraft) assets=\([.assets[].name] | join(", "))"'
   ```

   `draft=true` and the zip listed. If assets is empty, stop: publishing now
   ships a release nobody can install.

7. **Publish.** That is what reaches merchants.

## Release notes

Written for a merchant, not a changelog reader. Lead with what they can now do.
Show the code only where a developer needs the exact hook or filter name.

Both repos are public. Never name another Oyster repo, a PR or issue number in
one, or the internal classes, tables or endpoints of anything outside this repo.

## Traps that have actually bitten

- **`.gitignore` swallowing the vendored library's own `vendor/`.** An
  unanchored `vendor/` also matches `lib/plugin-update-checker/vendor/`, so
  Parsedown was never committed, every zip omitted it, and the updater fataled
  the first time it parsed a release body. `git archive` ships only tracked
  files, which is why the build script guards for this. Keep the rule anchored
  (`/vendor/`).

- **Publishing a hand-made draft.** A draft created without the zip looks
  finished and publishes fine. It is only broken on merchant sites.

- **Anything added at the repo root** that is not runtime needs an
  `export-ignore` line in `.gitattributes`, or it ships to every merchant.
