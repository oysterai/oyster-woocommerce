# `oyster-woocommerce` — Manual Test Guide

An internal maintainer's guide — testing against a local backend assumes
access to Oyster's (private) backend repo, which external contributors won't
have. Everything else here works for anyone: clone this repo, run `bin/dev.sh`,
and point `OYSTER_WOO_API_BASE_URL` at the public production API.

Run through linearly — each section depends on the previous. Uses [WordPress
Playground](https://wordpress.github.io/wordpress-playground/) for a
disposable WP + WooCommerce store — no Docker, no local MySQL.

## Prerequisites

| Need | Why | Get it |
|---|---|---|
| Node.js ≥ 20.18 | Runs the Playground CLI | already on this machine (v24) |
| Existing Oyster vendor account | Test the Connect flow | Use your account on whichever backend you point at |
| (Optional) local backend | Test against unreleased backend changes | `cd ../<backend repo> && php artisan serve` |

Playground is SQLite-backed and resets on every restart — that's a feature
for repeatable testing, not a bug. For anything sensitive to real MySQL
behavior (Action Scheduler timing, WP-Cron under load), use a full stack
like [LocalWP](https://localwp.com) instead; this guide covers the fast
inner loop.

## One-time setup

Nothing to install — `bin/dev.sh` shells out to `npx @wp-playground/cli`,
which Node fetches on first run.

```bash
cd oyster-woocommerce
bin/dev.sh
```

This boots at `http://localhost:9400`, logs you in as `admin`, installs +
activates WooCommerce, activates this plugin (auto-mounted from your
working tree — edits to PHP files need a server restart to pick up;
static JS/CSS can be hard-refreshed), seeds 3 products with SKUs, and
points the plugin's API base at `http://localhost:8000` — **your local
backend, which must already be running**:

```bash
# terminal 1
cd <backend repo> && php artisan serve

# terminal 2
cd oyster-woocommerce && bin/dev.sh
```

Local is the default on purpose — it's the only way a test session can
never accidentally hit production. Vendor credentials you use here must
exist in your **local** backend database, not production; a login
that "should work" but 401s is usually this — check the local backend's
logs for whether the request even arrived locally.

To test against the real production backend instead:

```bash
cd oyster-woocommerce && bin/dev.sh --production
```

## 1. Connect

1. Land on **Oyster → Connection** (`wp-admin/admin.php?page=oyster-woocommerce`).
2. Enter vendor credentials, submit.
3. Expect: bearer stored (encrypted via `Support\Crypto`), redirect to
   **Oyster → Widget**, connection status shows "Connected" with the
   vendor name/email from `GET /api/v1/vendors/profile`.
4. Disconnect and reconnect once to confirm the encrypted option is
   cleared and re-written cleanly (`oyster_woocommerce_connection`).

## 2. Widget

1. On **Oyster → Widget**, confirm settings pull `button_color` / `logo_url`
   from `GET /api/v1/vendors/widget/config` and the toggle for
   float-launcher vs. inline block/shortcode works.
2. Visit the storefront (any page). Expect the float launcher to render
   via `assets/js/oyster-loader.js` with `app: 'woocommerce'` as the
   channel.
3. Add the block/shortcode to a page in the block editor; confirm it
   renders inline in place of (or alongside) the float launcher per your
   settings.
4. Open devtools — confirm the loader's outbound request carries
   `app: 'woocommerce'`. The channel is accepted end-to-end now (merged on
   both the widget SDK and backend sides) — a scan here should attribute
   as `woocommerce`, not fall back to the generic `widget` channel. Note:
   this only takes effect once widget-lib ships a release containing
   those merges; if you're testing against the CDN bundle rather than a
   local widget-core tunnel, you may still see `widget` until that
   release goes out.

## 3. Catalog

1. Go to **Oyster → Catalog**. On a fresh install, expect a warning notice
   ("No categories or tags are selected below, so nothing syncs yet.") —
   **the safe default is to sync nothing** until a scope is chosen, so a
   large general-inventory store can never accidentally dump its whole
   catalog into Oyster just by installing the plugin.
2. Set "Sync scope" to "Sync all published products" and save — this is
   the deliberate opt-in the rest of this guide assumes as a baseline.
   Trigger a sync.
3. Expect the 3 seeded products (`OYS-CLN-001`, `OYS-SER-002`,
   `OYS-MOI-003`) to bulk-upsert to the backend's
   `/api/v1/integrations/woocommerce/*` catalog endpoint.
4. Edit a product's price/title in WooCommerce, confirm the product-hook
   sync fires (Action Scheduler) and the change propagates.
5. Note: Action Scheduler timing under Playground's WP-Cron is not
   representative of production — if P2 sync scheduling itself is what
   you're testing (not just the payload), do that pass in LocalWP.
6. Sync scope narrowing: set "Sync scope" to "Only sync selected
   categories/tags", pick a category none of the 3 seeded products belong
   to, and re-sync — expect all 3 to be skipped (not upserted). Switch back
   to "Sync all published products" and re-sync to restore the baseline for
   the rest of this guide.

## 4. Checkout attribution

P3 is implemented (`Checkout\Cart_Controller` + `Checkout\Order_Attribution`),
but only on `feature/checkout-attribution` / PR #2 — check out that branch
if `dev` doesn't have it merged yet, or this whole section will silently
no-op (the pre-P3 loader just logged the checkout event instead of acting
on it; that's expected on `dev` alone, not a bug).

1. Trigger a recommendation from the widget, add the recommended product
   to cart, complete checkout (WooCommerce's default Cash on Delivery or
   Check payment gateway works fine here — no real payment needed).
2. Expect: devtools shows a POST to `/wp-json/oyster-woocommerce/v1/cart/add`,
   followed by a redirect to checkout with the item(s) already in the WC
   cart. Requires the seeded products to have synced first (§3) — cart-add
   resolves Oyster product ids to WooCommerce ids via `resolve-variants`,
   so nothing to resolve means nothing gets added.
3. After completing checkout, confirm the order has `_oyster_batch_id`
   order meta, and check the backend's `orders` table (or its logs) for a
   `channel=woocommerce` row created on `payment_complete`.
4. Known open question: whether the recommendation payload itself needs to
   carry the Woo variation id, or whether server-side resolution here is
   sufficient on its own — this step is exactly what answers that.

## 5. Self-update

Not distributed through wordpress.org — updates come from GitHub Releases via
`Support\Self_Updater` (Plugin Update Checker, vendored at
`lib/plugin-update-checker/`). Playground resets state on every restart, so
this needs a **persistent** install (LocalWP or a real site) rather than
`bin/dev.sh`.

1. Install a build with a lower `Version:` than the latest tagged GitHub
   release (e.g. hand-edit `oyster-woocommerce.php`'s header + a matching
   `OYSTER_WOO_VERSION` to something older, or install right before a new tag
   goes out).
2. On **Plugins**, click "Check for updates" (top of the list) — expect an
   "Update available" row for Oyster for WooCommerce, pulling from the
   release's attached zip, not GitHub's auto-generated source archive.
3. Cutting a release: bump `Version:`/`OYSTER_WOO_VERSION` in
   `oyster-woocommerce.php`, merge, then `git tag vX.Y.Z && git push --tags`.
   `.github/workflows/release.yml` builds the zip and attaches it — nothing
   to upload by hand. The workflow fails loudly if the tag doesn't match the
   header's `Version:`, since that mismatch would make the release
   invisible to the self-updater.

## Resetting

Playground state doesn't persist across `bin/dev.sh` restarts — just
stop (`Ctrl+C`) and re-run for a clean store. Nothing to tear down.
