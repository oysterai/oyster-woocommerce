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

To test against a hosted backend instead — sandbox, or the real production
API:

```bash
cd oyster-woocommerce && bin/dev.sh --sandbox     # https://api.sandbox.oysterskin.com
cd oyster-woocommerce && bin/dev.sh --production  # https://api.oysterskin.com
```

Sandbox is the one to reach for when you want a hosted backend without
touching production data — it's a separate database, so your production
vendor credentials won't work there. Create a sandbox vendor account at
<https://sandbox.dash.oysterskin.com/register> and verify its email first;
the plugin declines to connect an unverified account.

`--sandbox` points all three overridable URLs at sandbox — the API, the
vendor dashboard links, and the storefront widget bundle. Note the
hostnames aren't consistently shaped: the API is `api.sandbox.…`, while
the dashboard and widget bundle take `sandbox.` as a prefix
(`sandbox.dash.…`, `sandbox.widget-lib.…`). Copy them rather than
extrapolating.

### Pointing an ordinary WordPress install at sandbox

Outside Playground, the same three constants go in `wp-config.php`, above
the `/* That's all, stop editing! */` line:

```php
define( 'OYSTER_WOO_API_BASE_URL',      'https://api.sandbox.oysterskin.com' );
define( 'OYSTER_WOO_DASHBOARD_URL',     'https://sandbox.dash.oysterskin.com' );
define( 'OYSTER_WOO_WIDGET_BUNDLE_URL', 'https://sandbox.widget-lib.oysterskin.com/v1/oysterskin-vendor-widget-web.umd.js' );
```

Two things worth knowing before you debug a surprise:

- A constant that fails `Support\Url_Guard` validation is **rejected
  silently and replaced with the production default** — a typo doesn't
  throw, it just quietly keeps talking to production. Confirm the override
  took by checking **WooCommerce → Status → Logs** (source
  `oyster-woocommerce`) for a `was rejected … using the default instead`
  warning. No warning means the override is live.
- **Disconnect before switching environments.** The bearer and vendor ID
  persist in `oyster_woocommerce_connection` across a base-URL change, so
  the admin keeps showing "Connected" while every call 401s against the
  new environment. Disconnect, switch, reconnect.

## 1. Connect

Connecting is two steps now: password, then a one-time code Oyster emails.
Connecting produces a credential this store keeps using until it disconnects,
so a password alone no longer earns one.

1. Land on **Oyster → Connection** (`wp-admin/admin.php?page=oyster-woocommerce`).
2. Enter vendor credentials, submit.
3. Expect: the **Enter your code** step, with the masked address the code went
   to. Nothing is written to `oyster_woocommerce_connection` yet — check the
   option is still absent.
4. Enter the code from your inbox, submit.
5. Expect: the store credential stored (encrypted via `Support\Crypto`), redirect
   to **Oyster → Widget**, connection status "Connected" with the vendor
   name/email from `GET /api/v1/vendors/profile`.

Then the paths that matter more than the happy one:

6. **Wrong code** — connect again, enter `000000`. Expect a "not accepted"
   notice and the option still absent. Repeat five times: the code burns, and
   even the real one stops working until you request a new one.
7. **Resend** — use *Send a new code*. The previous code stops working; the new
   one works. Click it half a dozen times quickly and expect a "too many codes"
   notice rather than an endless stream of email.
8. **Cancel** — start a connection, then *Cancel*. Expect the login step back,
   and the parked session gone (`oyster_woo_pending_connect_<user id>` transient
   deleted).
9. **Expiry** — start a connection, wait past 10 minutes, submit a code. Expect
   "that took too long" and the login step back.

10. **Disconnect** — disconnect, then reconnect, and confirm the encrypted option
    is cleared and re-written cleanly. Disconnecting also retires the credential
    at Oyster, so the previous one stops working: confirm the *old* stored value
    is rejected if you kept a copy. Reconnecting always issues a fresh one.
11. **Disconnect while offline** — point `OYSTER_WOO_API_BASE_URL` at something
    unreachable and disconnect. Expect it to still disconnect locally: refusing
    would strand someone with no way to finish.
12. **Uninstall** — with a store connected, *Delete* the plugin from the Plugins
    screen. The credential is retired on the way out, so it stops working even
    though nobody clicked disconnect. This is the only moment that can happen
    automatically, which is why it is worth checking.

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

## 4b. Scan-result email CTA

The shop links in the scan-result email Oyster sends the shopper point at
this store, not back at the widget: `Checkout\Email_Handoff` turns them into
a prefilled cart. Reproducible without waiting for a real email — the link is
a plain storefront URL.

1. Run a scan through the widget (§2) and let it recommend at least one
   product that has synced (§3). Note the batch id and the Oyster product
   ids from the recommendation payload in devtools.
2. Visit `{store}/?oyster_checkout=1&oyster_batch=<batch>&oyster_products=<comma-separated ids>`
   in a fresh browser profile (an email click is usually a first-touch session).
   Every parameter is `oyster_`-prefixed deliberately — WordPress reserves `p`
   and friends as public query vars and will 301 a bare one away to a post
   permalink, dropping it. If you ever see this link land on an unrelated post,
   that's the cause.
3. Expect: a redirect straight to **Checkout** with those products in the
   cart. Complete it and confirm the order carries `_oyster_batch_id` meta,
   same as §4 — an email-driven order has to be attributed identically to a
   widget-driven one.
4. Adversarial passes worth doing once: an id that was never synced, an id
   for a product that's out of stock, and a garbage `p=` value. All three
   should land on **Cart** with the "couldn't automatically add" notice
   rather than an empty checkout or a fatal.

## 4c. Scan payments through your own checkout

Only runs for vendors Oyster has switched to collecting scan payments
themselves. Ask for your test vendor to be switched on before running this —
without it the widget opens Oyster's own checkout and none of this fires.

Two things that will otherwise waste your time:

- **The vendor needs a non-zero scan rate.** With a zero rate there is nothing to
  charge, so Oyster never asks for payment and scans simply run free. That looks
  identical to the feature being broken.
- **The store needs an enabled payment gateway.** `bin/dev.sh` now enables Cash on
  delivery for exactly this; on any other store, enable one under
  **WooCommerce → Settings → Payments** or the pay page has nothing to submit.
- **The store must not be in "Coming soon" mode.** WooCommerce turns it on for a
  fresh install, and it puts a launch placeholder in front of the pay-for-order
  page. The order is created and the URL is correct — the page simply never
  renders, which reads as a broken handoff. `bin/dev.sh` now turns it off;
  elsewhere it is **WooCommerce → Settings → Site visibility**.

1. Open the widget on the storefront and take a scan as a shopper who has to
   pay. Expect to be sent to **your store's** pay-for-order page, not Oyster's
   checkout.
2. Check **WooCommerce → Orders**: a pending order for one "Skin scan" line, at
   the amount the widget quoted, with the shopper's email on it.
3. Pay the order with any configured gateway. Expect the scan to start on its
   own within a few seconds — the widget is waiting on the payment, not polling
   the browser. The order gains a note saying Oyster was told it settled.
4. **Cancel instead** — start another, then set the order to Cancelled. Expect
   the widget to report the payment did not complete rather than sitting until
   it times out.
5. **Double-submit** — start a payment, reload the widget, start it again with
   the same scan. Expect **one** order, not two: a shopper must never be charged
   twice for one scan.
6. **Confirmed once** — pay an order, then move it Processing → Completed.
   Expect only one confirmation note; the later transitions must not re-confirm.
7. **Hidden product** — confirm "Skin scan" does not appear in the storefront
   catalog or search. Delete it, then take another scan: it is recreated and the
   payment still works.
8. **Not connected** — disconnect the store and take a scan. The widget should
   report that payment could not be started rather than hanging.

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
