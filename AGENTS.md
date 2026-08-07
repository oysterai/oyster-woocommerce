# AGENTS.md

Guide for AI coding agents (and human contributors) working in this repo. This
is the canonical reference — other tool-specific files (e.g. `CLAUDE.md`)
just point back here so there's one source of truth to keep current.

## What this is

A WordPress plugin that connects a WooCommerce store to a vendor's Oyster
account: an AI face-scan skin-analysis widget on the storefront, automatic
catalog sync, and checkout attribution back to the scan that drove a sale.
Oyster itself (the AI analysis, the vendor dashboard, billing) is an external
service this plugin talks to over HTTPS — nothing else lives in this repo.

This repo is **public**. See "Public repo hygiene" below before writing any
comment that references how another Oyster product/service works internally.

## Architecture

- **Bootstrap:** `oyster-woocommerce.php` — plugin header, defines
  `OYSTER_WOO_*` constants, a small PSR-4-ish autoloader (no Composer — this
  plugin is deliberately dependency-free so the release zip is
  self-contained), activation/deactivation hooks, an admin notice + bail-out
  if PHP/WooCommerce requirements aren't met.
- **`includes/Plugin.php`** — the single wiring point. `Plugin::boot()`
  constructs every service and calls `->register()` on each. Admin-only
  classes are gated behind `is_admin()`; anything that must fire on
  storefront/REST/Action-Scheduler requests is not.
- **Namespaces mirror directories** under `Oyster\Woo\`:
  - `Api\` — `Client` (the only thing that ever calls Oyster's API) and
    `Api_Exception`.
  - `Admin\` — wp-admin screens (`Connect_Screen`, `Widget_Settings_Screen`,
    `Catalog_Screen`, `Setup_Guide`, `Menu`, `Sync_Status_Column`).
  - `Frontend\` — `Widget_Loader`, which injects config and enqueues
    `assets/js/oyster-loader.js` on the storefront.
  - `Checkout\` — `Cart_Filler` (the one place Oyster product ids become
    cart lines), `Cart_Controller` (the widget's checkout-handoff REST
    route), `Email_Handoff` (the scan-result email's checkout CTA, a
    `?oyster_checkout=1` storefront link) and `Order_Attribution` (cart →
    order → reported-paid-order).
  - `Sync\` — `Catalog_Sync` (Action Scheduler jobs, both incremental and
    full-import), `Product_Hooks` (WooCommerce hooks → `Catalog_Sync`),
    `Product_Mapper` (WC_Product → API row shape), `Sync_State` (per-product
    synced/oyster-id post meta).
  - `Catalog\` — product-editor additions the sync depends on
    (`Ingredients_Field`, `Size_Volume_Field`, `Skin_Type_Attribute`).
  - `Support\` — everything else: `Connection` (encrypted bearer storage),
    `Crypto`, `Widget_Settings`, `Catalog_Filter` (sync scope), `Onboarding`
    (Setup Guide manual acks), `Dashboard_Link`, `Url_Guard`, `Self_Updater`.
  - `Compliance\` — `Gdpr` (WP core personal-data export/erase hooks).
- **`lib/plugin-update-checker/`** — vendored third-party library (MIT,
  YahnisElsts/plugin-update-checker), not something to hand-edit.
- **`assets/js/oyster-loader.js`** — the only storefront JS. Reads
  `window.OysterWooConfig` (injected server-side by `Widget_Loader`), loads
  the widget SDK bundle, boots it on each anchor the block/shortcode/launcher
  emits, and handles the checkout handoff.

## Coding conventions

- `declare( strict_types=1 );` in every PHP file.
- WordPress coding style: tabs for indentation, snake_case method/function
  names (not camelCase — this is a WordPress convention, not an oversight;
  linters that flag it against generic PSR rules are wrong for this repo).
- No Composer, no npm build step. `lib/plugin-update-checker/` is the one
  exception (vendored, not installed) — don't add a second dependency
  mechanism alongside it.
- Comments explain *why*, not *what*. Don't restate what a line obviously
  does; do explain non-obvious constraints, invariants, or a bug a piece of
  code is specifically working around.
- Default to no comments; add one only when it would confuse a future reader
  to remove it.

## Security invariants — do not relax these

- **Never make a security-sensitive URL filterable.** `Client::base_url()`,
  `Widget_Loader::bundle_url()`, and `Dashboard_Link::url()` all resolve
  through `Support\Url_Guard::resolve()` — a wp-config constant, validated
  against an allow-list, with **no** `apply_filters()` anywhere in the
  chain. A WordPress filter has no permission model: any other active
  plugin can hook it, so a filter here would let an unrelated plugin
  silently redirect API traffic (bearer included), swap in arbitrary
  storefront JS, or repoint an admin's dashboard link at a phishing page.
  If you're adding a new URL that needs to be overridable, add a new
  constant and call `Url_Guard::resolve()` — never a fresh filter, never a
  hand-rolled copy of the allow-list logic.
- **`Url_Guard::ALLOWED_HTTPS_ROOT_DOMAINS` matching must stay a suffix
  check with the dot boundary enforced** (`$host === $root ||
  str_ends_with($host, '.'.$root)`), never `str_contains`/substring
  matching — the latter is vulnerable to domain-confusion tricks
  (`notoysterskin.com`, `oysterskin.com.evil.example`).
- **`Cart_Controller`'s `/cart/add` REST route is deliberately public**
  (`permission_callback: __return_true`) — it only ever mutates the
  *requesting visitor's own* cart session, exactly like a native WooCommerce
  add-to-cart form. It resolves Oyster ids to WooCommerce ids server-side
  specifically so the vendor's bearer token never reaches the browser.
  Don't "fix" the public permission callback without understanding this.
- **`Email_Handoff`'s `?oyster_checkout=1` link is deliberately unsigned.**
  A link clicked out of an email can't carry a nonce, and this plugin ships
  to merchants, so it can't hold a secret shared with Oyster to verify a
  signature against either. It doesn't need one: it goes through
  `Cart_Filler`, which resolves the ids server-side against *this* vendor's
  own synced catalog, so a hand-edited link can at worst put this store's
  own products into the clicker's own cart — what WooCommerce's built-in
  `?add-to-cart=` links already do. Don't add a signature scheme that
  requires shipping a shared secret in plugin code or in the page.
- **Both routes into the cart must go through `Cart_Filler`.** The in-stock
  and purchasability filtering, and the `oyster_attribution` stamp
  `Order_Attribution` later reads, live there once on purpose — a second
  hand-rolled `add_to_cart()` path is how one of the two surfaces silently
  stops being attributed.
- **A REST callback that touches `WC()->cart`/`WC()->session` must call
  `wc_load_cart()` first.** WooCommerce's cart/session is normally
  bootstrapped lazily on `wp_loaded` during a real front-end page load — a
  REST request never fires that hook, so `WC()->cart` is null unless you
  force the init yourself. (This was a real, live bug — see the git history
  around `Cart_Controller::handle_add`.)
- **The catalog sync's default is "sync nothing," not "sync everything."**
  `Catalog_Filter::defaults()` is deliberately an allow-list with nothing
  selected. A large general-inventory store must never have its entire
  catalog dumped into Oyster's data just by installing the plugin — "sync
  all" is a one-click, deliberate opt-in, never the silent unconfigured
  state. Don't add a fallback that silently reverts an empty allow-list to
  "sync all" — that's the exact danger this default exists to prevent.

## Public repo hygiene

**THIS REPO IS PUBLIC. Everything you write here is world-readable, and on
GitHub most of it cannot be fully unpublished afterwards.**

This applies to **every surface, not just code comments** — the rule below
has been broken via a PR description, so treat all of these as publishing:

- code comments and docblocks
- commit messages
- **PR titles, PR descriptions, and PR/issue comments**
- `readme.txt` (including the changelog), `AGENTS.md`, `TESTING.md`
- release notes and tag messages

The rule: never assert facts about, or name the internal structure of,
other Oyster repos/products a public reader can't access — the backend
API's codebase, the vendor dashboard, the Shopify integration's internals,
the widget SDK's repos. Concretely, never write:

- another Oyster repo's name, or a PR/issue/commit reference in one
  (`oysterai/<private-repo>`, `#1234`, "see the backend PR")
- internal class, trait, table, column, file or endpoint-handler names from
  those repos
- internal infrastructure details (what the other side is hosted on, how it
  signs things, what its secrets are called)
- internal team process, ticket ids, or roadmap/timeline specifics

It's fine — often necessary — to describe *behavior* of the backend Oyster
talks to ("the backend resolves incoming values by name," "Oyster emails a
verification code," "scan attribution is re-validated server-side"). Say
what a merchant or contributor can observe, not how it's built.

Cross-repo coordination still has to be expressible: describe it in terms
of *this* repo. "The email side of this ships separately on Oyster's own
release cycle; this plugin release should go out first" says everything a
reader needs without naming anything private.

### A PR description is a document, not a message

The list above says what not to *name*. This says who you are writing *to*.

A PR here is read by strangers, months later, with no other context — not by
the person reviewing it this afternoon. Writing it as a message to a colleague
publishes the working relationship along with the change: who is waiting on
whom, what is broken right now, what someone decided and why they hesitated.
None of that is a secret, and none of it belongs in a public repo.

Never put in a PR title, description or comment:

- **anyone addressed directly** — "your call", "over to you", "as you asked",
  "let me know". If a sentence has a *you* in it who works here, it belongs in
  the team channel
- **narration of your own process** — what you tried, what you got wrong, what
  you decided not to do. The diff is the claim; the reasoning that survives is
  the reasoning about the *code*
- **the live state of anything** — "X is currently broken", "this is not
  deployed yet", "waiting on the other release". Written down, that reads to an
  outsider as a running commentary on incidents
- **sequencing across repos or teams** — "merge this first", "needs the other
  thing live". Describe the dependency in terms of this repo's behaviour, or
  keep it out (see the paragraph above on cross-repo coordination)

What does belong: what changed, why it changed, what it affects, and how it was
verified. Enough for someone to review the diff and to understand it later.

The version that went out:

> ~~**v0.12.0 is live with zero assets.** … I have not deleted anything — that
> is a live release and it is your call.~~

and what it should have been:

> A published release is immutable, so assets can only be attached while it is
> still a draft. `Support\Self_Updater` calls `enableReleaseAssets()`, so a
> release without one falls back to GitHub's generated archive.

Same technical content, no incident, nobody addressed. **Status, coordination
and decisions go to the team channel. The PR gets the change.**

**Before publishing anything, re-read it once and ask: would this sentence
make sense to someone who has only ever seen this one repo?** If it names
something they can't look up, cut it or generalize it. If you notice a slip
after publishing, fix the text *and* say so — a PR description keeps its
previous versions in the "edited" history, and clearing that needs a manual
"Delete revision" in the GitHub UI.

## Git workflow

**Never push directly to `dev` or `main`.** Every change — including
one-line fixes and this file — goes through a feature branch and a PR:

```bash
git checkout -b fix/short-description origin/dev
# ... make changes, commit ...
git push -u origin fix/short-description
gh pr create --base dev --title "..." --body "..."
```

`dev` is the integration branch; `main` is periodically fast-forwarded from
it (that merge is also done via PR, not a direct push).

## Releases and self-update

Not distributed through wordpress.org — updates come from GitHub Releases,
detected by `Support\Self_Updater` (Plugin Update Checker). Cutting a
release:

1. Bump `Version:` in `oyster-woocommerce.php`'s header **and**
   `OYSTER_WOO_VERSION` to match. Update `readme.txt`'s `Stable tag` and
   add its changelog entry.
2. Merge that to `main`.
3. `git tag vX.Y.Z && git push --tags`.
4. **Publish the GitHub Release for that tag.** This is what ships it.

Step 4 is the one that matters. Pushing the tag builds nothing and reaches
nobody — the workflow runs on the release being *published*. Every install
polls the published release and offers merchants the update, so the moment it
appears is the moment it ships; that should be a decision, not a side effect
of pushing a ref. A tag can sit unreleased for as long as you like.

`.github/workflows/release.yml` then builds the zip (`bin/build-release-zip.sh`,
a `git archive` from the tag — only committed files ship, filtered by
`.gitattributes`' `export-ignore` entries) and attaches it to that Release,
plus an identically-named `oyster-woocommerce.zip` copy for a stable "always
latest" download link. It builds from the **tag**, not from `main`, so a
release always ships the code it names. The workflow **fails the build** if
the tag doesn't match the header's `Version:` — that mismatch would
silently make the release invisible to the self-updater, so don't skip it
or work around it.

Release notes are yours to write when you publish; the workflow does not
generate or overwrite them. To rebuild the assets for a release that already
exists, run the workflow manually and give it the tag.

## Testing

No PHPUnit/WP test harness exists yet. Verification today is:

- `php -l <file>` on every changed PHP file (syntax only).
- `node --check assets/js/*.js` for the JS.
- For security- or logic-critical changes with no test harness to lean on,
  write a small standalone PHP script that shims only the WP/WC functions a
  class actually calls, exercises it against a table of cases (happy path +
  adversarial/edge cases), and delete the script once it passes. This
  repo's git history has several examples (`Url_Guard`, `Catalog_Filter`)
  worth mirroring in shape.
- `TESTING.md` — a manual walkthrough using WordPress Playground
  (`bin/dev.sh`). Assumes access to Oyster's own (private) backend for the
  local-backend sections; everything else works against the public
  production API for any contributor.
