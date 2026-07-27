# Callouts

Tracker for things flagged during development that were deliberately **not**
solved on the spot — known gaps, open questions, and follow-ups that need a
decision or verification before we can call them closed. Not a general
roadmap (see the phase table in the README) — only items someone explicitly
called out as a risk, limitation, or unresolved question.

Update this file whenever a callout is opened, resolved, or its status
changes. Each entry keeps its number permanently, even after resolution —
don't renumber.

## Status legend

- 🔴 **Open** — unresolved, no owner/date yet.
- 🟡 **Needs decision** — blocked on a person, not on code.
- 🟢 **Resolved** — done; kept here for history, moved to the bottom.

---

## 🔴 Open

### 1. WooCommerce Blocks checkout — attribution hooks unverified

`Order_Attribution` wires only the **classic** hooks:
`woocommerce_checkout_create_order` and `woocommerce_payment_complete`
([includes/Checkout/Order_Attribution.php](includes/Checkout/Order_Attribution.php)).
These fire for the standard shortcode-based Cart/Checkout. WooCommerce
**Blocks** (block-based Cart/Checkout, increasingly the WC default for new
stores) has its own Store API extension-data hooks
(`woocommerce_store_api_cart_update_customer`,
`woocommerce_store_api_checkout_update_order_meta`) that may be needed
alongside the classic ones for attribution to survive on a Blocks-only store.

- **Raised:** [oyster-woocommerce#2](https://github.com/oysterai/oyster-woocommerce/pull/2) (PR description)
- **Why deferred:** no live store to test against yet; the classic hooks cover the common case (shortcode checkout) and Blocks fallback compatibility mode still fires them in many configurations — untested whether that holds for *this* plugin's flow specifically.
- **Resolves when:** tested against a real WooCommerce Blocks checkout (a merchant using the block-based Cart/Checkout, not the `[woocommerce_checkout]` shortcode). If attribution doesn't survive, add the Store API extension-data hooks alongside the classic ones.

### 2. Recommendation payload — does it need to carry the Woo variation id?

Original open question from the P1 scaffolding review: does the widget SDK's
recommendation payload need to carry `woocommerce_variation_id` per
recommended product for `woocommerce`-channel vendors, the way it might for
Shopify's variant model?

- **Raised:** initial P1 summary, this session (2026-07-23)
- **Why deferred:** `Cart_Controller::handle_add` resolves Oyster `product_id` → WooCommerce product/variation id **server-side**, at add-to-cart time, via the `resolve-variants` endpoint — so the payload itself may not need to change at all. This hasn't been confirmed either way against how the widget SDK actually builds `CheckoutPayload.checkout_items[].product_id` for a WooCommerce-synced recommendation.
- **Resolves when:** a live scan against a connected, catalog-synced WooCommerce store confirms `checkout_items[].product_id` reliably resolves via `resolve-variants` with no widget-side changes needed. If it doesn't resolve, trace why before assuming the payload needs a new field.

### 3. No variation-level delete on the backend

Product deletion on the backend is **product-level only** by design,
mirroring how the Shopify integration handles it. When a single variation is trashed/deleted
but its parent product survives, `Product_Hooks::on_post_removed`
([includes/Sync/Product_Hooks.php:56-72](includes/Sync/Product_Hooks.php#L56-L72))
re-syncs the parent (refreshing its remaining variations) rather than
deleting anything — there's no endpoint to prune a single variation's Oyster
row. The stale row lingers, still recommendable, until the whole parent
product is later deleted.

- **Raised:** [oyster-woocommerce#1](https://github.com/oysterai/oyster-woocommerce/pull/1) (PR description + code comment)
- **Why deferred:** edge case (removing one variation from a surviving variable product, as opposed to deleting the whole product) — lean-MVP scope didn't include a variation-level delete endpoint on the backend.
- **Resolves when:** either accepted as permanent behavior, or a `DELETE .../products/delete-variation` (or similar) endpoint is added on the backend and wired into `Product_Hooks`. Revisit if stale single-variation rows turn out to be a real merchant complaint.

### 7. Catalog filter changes don't retroactively remove now-ineligible synced products

`Support\Catalog_Filter` lets a vendor scope catalog sync to specific
categories/tags (allow-list or deny-list, OR-combined across both
taxonomies) — see [includes/Support/Catalog_Filter.php](includes/Support/Catalog_Filter.php).
The eligibility check runs whenever a product is *synced* (full import, or
an individual product save via `Product_Hooks`/`Catalog_Sync::sync_product`)
but changing the filter **settings** themselves doesn't rescan and
archive/delete products that were already synced under a looser scope and
are now out of it. A product that becomes ineligible (either because the
vendor tightened the filter, or because the merchant recategorized it)
simply stops receiving *updates* — its already-synced Oyster row lingers,
still recommendable, until the product itself is trashed/deleted in
WooCommerce (which already triggers the existing delete path via
`Product_Hooks::on_post_removed`).

- **Raised:** this session (2026-07-24), while building the category/tag sync-scope filter.
- **Why deferred:** cleaning this up properly needs a way to enumerate everything already synced for a vendor and diff it against the new filter — a meaningfully bigger feature than the filter itself (likely needs a new "list synced product ids" backend endpoint), out of scope for the initial build.
- **Resolves when:** either accepted as a documented limitation (merchant deletes/re-saves affected products by hand after narrowing scope), or a "prune now-ineligible products" action is added to the Catalog admin screen.

### 4. No PHPUnit/WP test harness in the plugin

The backend side of this integration is fully unit-tested. The plugin (`oyster-woocommerce`) has **no automated
test coverage** — verification so far is `php -l` (syntax only) and
`node --check` on the JS. No WP_UnitTestCase / Brain Monkey / WP-CLI test
scaffold exists yet.

- **Raised:** [oyster-woocommerce#1](https://github.com/oysterai/oyster-woocommerce/pull/1) and [#2](https://github.com/oysterai/oyster-woocommerce/pull/2) (PR descriptions, both)
- **Why deferred:** stood up the WP-side integration first; a real test harness (likely `wp-env` + PHPUnit + WooCommerce test helpers) is its own chunk of setup work.
- **Resolves when:** a test harness is added and the sync/checkout logic (`Product_Mapper`, `Catalog_Sync`, `Cart_Controller`, `Order_Attribution`) gets real coverage instead of relying on manual smoke tests.

---

## 🟡 Needs decision (Emeka)

---

## 🟢 Resolved

### 5. Dashboard deep-link URL

The "Open Oyster dashboard" button, surfaced on the Connect/Widget/Catalog
screens via [Support\Dashboard_Link](includes/Support/Dashboard_Link.php),
defaulted to `https://vendors.oysterskin.com`, overridable via a filter.
This was asked in the original P1 summary and never explicitly confirmed.

- **Raised:** initial P1 summary, this session (2026-07-23)
- **Resolved by:** Emeka corrected the default directly in code to `https://dash.oysterskin.com` (2026-07-24), during the same pass that removed the filter and moved the override to the `OYSTER_WOO_DASHBOARD_URL` wp-config constant (validated by [Support\Url_Guard](includes/Support/Url_Guard.php) — no filter, same reasoning as the API base URL lockdown).

### 6. `app: 'woocommerce'` channel support in the widget

Originally flagged as an unverified spike in the P1 summary: would the widget
SDK accept `'woocommerce'` as a valid embedding app, or silently fall back to
the generic `widget` channel?

- **Resolved by:** changes merged on both the widget SDK and backend sides, adding `woocommerce` as a recognized channel.
- **One remaining operational note (not re-opening this callout, just worth remembering):** the storefront loads the **production** widget bundle from `https://widget-lib.oysterskin.com` (see [assets/js/oyster-loader.js:17](assets/js/oyster-loader.js#L17) and [includes/Frontend/Widget_Loader.php:33](includes/Frontend/Widget_Loader.php#L33)). The source fix is merged, but it needs a widget-bundle release to actually reach real merchant storefronts. Until that release ships, production stores still see scans attributed to the `widget` channel, not `woocommerce`, even though the code is merged. Local testing can point at a pre-release build via `OYSTER_WOO_WIDGET_BUNDLE_URL`.
