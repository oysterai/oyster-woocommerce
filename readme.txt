=== Oyster for WooCommerce ===
Contributors: oysterskin
Tags: woocommerce, skincare, ai, recommendations, face scan
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
WC requires at least: 8.0
WC tested up to: 9.6
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add Oyster's AI face-scan skincare concierge to your WooCommerce store. Shoppers scan their skin and get personalized product recommendations from your own catalog.

== Description ==

Oyster for WooCommerce connects your store to your Oyster vendor account and puts an AI-powered skin-scan widget on your storefront. Shoppers take a quick face scan, receive a personalized routine built from **your** products, and check out natively — with every order attributed back to the scan that drove it.

All AI analysis, recommendation, and reporting happens in Oyster's platform; this plugin is a thin, secure bridge between your WooCommerce store and Oyster. Analytics, orders, and billing live in your Oyster vendor dashboard, which the plugin deep-links to.

Features:

* Connect your store to an Oyster vendor account (log in or sign up).
* Floating skin-scan launcher on your storefront, with configurable branding.
* Inline scan via the "Oyster Skin Scan" block or the `[oyster_scan]` shortcode.
* Automatic catalog sync — published simple and variable products sync to Oyster
  whenever you save them, plus a one-click full import/re-sync under **Oyster → Catalog**.
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
3. Go to **Oyster → Connection** in wp-admin and log in with (or create) your Oyster vendor account.
4. Configure the launcher under **Oyster → Widget**.
5. Run your first catalog sync under **Oyster → Catalog**.

== Changelog ==

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
