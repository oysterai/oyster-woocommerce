=== Oyster for WooCommerce ===
Contributors: oysterskin
Tags: woocommerce, skincare, ai, recommendations, face scan
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
WC requires at least: 8.0
WC tested up to: 9.6
Stable tag: 0.12.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add Oyster's AI face-scan skincare concierge to your WooCommerce store. Shoppers scan their skin and get personalized product recommendations from your own catalog.

== Description ==

Oyster for WooCommerce connects your store to your Oyster vendor account and puts an AI-powered skin-scan widget on your storefront. Shoppers take a quick face scan, receive a personalized routine built from **your** products, and check out natively — with every order attributed back to the scan that drove it.

All AI analysis, recommendation, and reporting happens in Oyster's platform; this plugin is a thin, secure bridge between your WooCommerce store and Oyster. Analytics, orders, and billing live in your Oyster vendor dashboard, which the plugin deep-links to.

Features:

* Connect your store to an Oyster vendor account.
* Floating skin-scan launcher on your storefront, with configurable branding.
* Inline scan via the "Oyster Skin Scan" block or the `[oyster_scan]` shortcode.
* Automatic catalog sync — published simple and variable products sync to Oyster
  whenever you save them, plus a one-click full import/re-sync under **Oyster → Catalog**.
* Richer product data for better recommendations — an **Oyster ingredients** field on
  every product (one ingredient per line), a **Skin Type** product attribute, and an
  **Oyster size / volume** field, all synced to Oyster. Populate them by hand or via the
  WooCommerce product CSV importer.
* Full attribute sync — a product's brand (native WooCommerce brands), primary category,
  and weight sync to Oyster automatically alongside price, stock and images.
* Native checkout attribution — the widget's recommended products add straight to your
  WooCommerce cart, and every resulting paid order is attributed back to the scan that
  drove it, visible in your Oyster vendor dashboard.

== Privacy ==

This plugin adds a small amount of information to a WooCommerce order when it's attributed
to an Oyster skin scan: a batch id, a routine id, and a widget attribution id (all opaque
identifiers, not personal data on their own). These are included in WordPress's built-in
**Tools → Export Personal Data** and **Tools → Erase Personal Data** requests alongside the
rest of that order's data. Your Oyster vendor connection settings (business name, widget
keys, storefront URL) are store configuration, not customer data, and aren't part of these
requests.

== External services ==

This plugin connects to Oyster's API (https://api.oysterskin.com) to authenticate your
vendor account and load your widget configuration, and loads the Oyster widget bundle
from https://widget-lib.oysterskin.com on your storefront. Your Oyster account
credentials are used only to obtain an access token, which is stored encrypted on your
site. See https://oysterskin.com/privacy for Oyster's privacy policy and
https://oysterskin.com/terms for terms of service.

== Installation ==

1. Install and activate WooCommerce.
2. Upload the plugin to `/wp-content/plugins/oyster-woocommerce` and activate it.
3. If you don't have an Oyster vendor account yet, create one at https://dash.oysterskin.com/register
   and verify your email.
4. Go to **Oyster → Connection** in wp-admin and log in with your Oyster vendor account.
5. Configure the launcher under **Oyster → Widget**.
6. Run your first catalog sync under **Oyster → Catalog**.

== Changelog ==

= 0.12.0 =
* If Oyster has set your account up to take scan payments yourself, shoppers now
  pay for their scan through your own checkout instead of Oyster's — using the
  payment methods you already accept, and appearing in your orders like any
  other sale. Their scan starts as soon as the order is paid. Nothing to set up
  here: it switches on from your Oyster account, and stores that aren't set up
  this way are unaffected.
* Scan payments appear as ordinary orders against a hidden "Skin scan" product,
  so refunds, taxes and reporting all work the way you'd expect. The product
  stays out of your storefront and search, and is safe to delete — it comes back
  when it's next needed.

= 0.11.0 =
* Connecting your store now takes a second step: after your password, Oyster
  emails you a one-time code to enter. Connecting creates a credential this
  store keeps using, so a password on its own no longer hands one out — if
  someone learns your password, they still cannot connect a store to your
  account without also reading your email.
* Your store now has its own connection credential instead of borrowing the
  login of whoever set it up. Two things stop breaking as a result: the
  connection no longer expires after about a month and quietly stops syncing,
  and it no longer dies when the person who connected it leaves or has their
  access changed.
* Disconnecting now actually disconnects. It used to clear this site's copy
  and leave the connection live on Oyster's side; it is now retired properly.
  Deleting the plugin does the same on its way out, so a store you take down
  does not leave a working connection behind.

= 0.10.0 =
* Shoppers coming back from their Oyster scan email now land straight on your
  checkout with the recommended routine already in the cart, instead of having
  to find the widget on your store and add every product again. Anything not
  synced yet or out of stock is left out, and if nothing can be added they land
  on the cart with a note explaining why rather than on an empty checkout.
  These orders are attributed to the scan exactly like ones started in the
  widget. Nothing to configure — it works as soon as this update is installed.

= 0.9.2 =
* The Oyster logo now appears where you'd expect it: the **Oyster** item in the
  wp-admin sidebar carries the Oyster mark instead of a generic placeholder, and
  the plugin shows its own icon on the **Updates** screen rather than WordPress's
  default puzzle piece.

= 0.9.1 =
* Fixed "Check for updates" causing a critical error. Part of the bundled
  update library was missing from the release package, so checking for a new
  version crashed instead of finding one — which also meant automatic updates
  could never install. Updating to 0.9.1 (once, by hand) fixes update checks
  from then on.

= 0.9.0 =
* Creating an Oyster account now happens in the Oyster dashboard rather than in
  wp-admin. The **Oyster → Connection** screen links straight to the sign-up page
  and keeps the log-in form for connecting an account you already have. If you're
  already connected, nothing changes for you.

= 0.8.0 =
* Self-updating: the plugin now checks for and installs updates directly (no
  wordpress.org listing yet), so you'll see "Update available" on the Plugins
  screen the same way you would for any other plugin.
* Catalog sync now defaults to syncing nothing until you choose a scope —
  protects stores with a lot of non-skincare inventory from accidentally
  syncing their whole catalog. Pick "sync all," or scope to specific
  categories/tags, on the **Oyster → Catalog** screen.
* Fixed the checkout handoff occasionally failing silently — the widget's
  "add to cart" step is now more reliable, and any failure sends the shopper
  to a normal page with a clear notice instead of leaving them stuck.
* New "Get set up with Oyster" checklist on the **Oyster → Connection**
  screen, walking through connecting, syncing your catalog, customizing the
  widget, and confirming your first scan.

= 0.7.0 =
* Per-product sync tracking: after each successful catalog push, the plugin records
  the product's Oyster id and sync timestamp in post meta (`_oyster_product_id`,
  `_oyster_synced_at`). A new **Oyster** column in **WooCommerce > Products** shows
  a green check with a "Synced X ago" tooltip for synced products and a dash for
  those not yet pushed, so merchants can see at a glance what's live in Oyster.
  The state is cleared automatically when a product is removed from Oyster.

= 0.6.0 =
* Catalog sync now carries a product's **brand** (from WooCommerce's native brands
  taxonomy, with the existing `oyster_woocommerce_product_brand` filter still able to
  override for third-party brand plugins), its **primary category**, and its **weight**
  and weight unit — so Oyster's recommendations and storefront can use them.
* Adds an **Oyster size / volume** product field (a number plus a unit, e.g. 300 ml) in
  the Product data > Inventory panel, since WooCommerce has no native volume field. It
  syncs to Oyster and can be populated in bulk via `Meta: _oyster_size_volume` and
  `Meta: _oyster_size_volume_unit` importer columns.

= 0.5.0 =
* Product data for recommendations: adds an **Oyster ingredients** field (one ingredient
  per line) to the product editor and registers a **Skin Type** global product attribute
  (Normal, Dry, Oily, Combination, Sensitive, Acne-prone, Mature). Both are sent with the
  catalog sync so Oyster can match products to a shopper's skin more accurately, and both
  can be populated in bulk via the WooCommerce product CSV importer
  (`Meta: _oyster_ingredients` and a global `Skin Type` attribute column).

= 0.4.0 =
* "Open Oyster dashboard" link now on every connected admin screen (Connection, Widget,
  Catalog), not just Connection — one click to analytics, orders, and billing from
  wherever you are.
* GDPR: order attribution fields (scan batch id, routine id, widget attribution id) are
  now included in WordPress's built-in personal-data export and erasure tools.
* Added `bin/build-release-zip.sh` for maintainers to package a distributable plugin zip.

= 0.3.0 =
* Checkout attribution: the widget's "Add to cart" adds recommended products straight to
  your WooCommerce cart (resolved server-side, so no vendor credentials ever reach the
  browser) and carries the originating scan through checkout. Paid orders are reported to
  your Oyster vendor dashboard for attribution — silently, with no effect on order
  processing, emails, or fulfilment.

= 0.2.0 =
* Catalog sync: WooCommerce product create/update/delete hooks push to Oyster automatically
  (via Action Scheduler, so a product save never waits on the network); a new
  **Oyster → Catalog** screen reports sync status and triggers the initial full import
  or a forced re-sync.

= 0.1.0 =
* Initial scaffold: connection flow, storefront widget (float launcher + inline block/shortcode).
