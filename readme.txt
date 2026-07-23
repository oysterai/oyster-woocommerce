=== Oyster for WooCommerce ===
Contributors: oysterskin
Tags: woocommerce, skincare, ai, recommendations, face scan
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
WC requires at least: 8.0
WC tested up to: 9.6
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add Oyster's AI face-scan skincare concierge to your WooCommerce store. Shoppers scan their skin and get personalized product recommendations from your own catalog.

== Description ==

Oyster for WooCommerce connects your store to your Oyster vendor account and puts an AI-powered skin-scan widget on your storefront. Shoppers take a quick face scan, receive a personalized routine built from **your** products, and check out natively — with every order attributed back to the scan that drove it.

All AI analysis, recommendation, and reporting happens in Oyster's platform; this plugin is a thin, secure bridge between your WooCommerce store and Oyster. Analytics, orders, and billing live in your Oyster vendor dashboard, which the plugin deep-links to.

Features (v0.1):

* Connect your store to an Oyster vendor account (log in or sign up).
* Floating skin-scan launcher on your storefront, with configurable branding.
* Inline scan via the "Oyster Skin Scan" block or the `[oyster_scan]` shortcode.

Coming next: automatic catalog sync, and native checkout attribution.

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

== Changelog ==

= 0.1.0 =
* Initial scaffold: connection flow, storefront widget (float launcher + inline block/shortcode).
