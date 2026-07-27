/**
 * Storefront loader for the Oyster widget on WooCommerce.
 *
 * Config isn't fetched client-side — the PHP plugin injects it inline as
 * `window.OysterWooConfig` because it runs server-side and already holds the
 * vendor's public key. This script just:
 *
 *   1. Reads the injected config (publicKey, primaryColor, logoUrl, loaderUrl).
 *   2. Finds each anchor a block/launcher/shortcode emitted.
 *   3. Loads vendor-widget-web's UMD bundle and calls createScanWidget().
 *
 * Failure paths console.warn for self-diagnosis but never render fallback UI on
 * the storefront — a half-set-up install must not leak errors to shoppers.
 */
(function () {
  var DEFAULT_WIDGET_BUNDLE =
    'https://widget-lib.oysterskin.com/v1/oysterskin-vendor-widget-web.umd.js'

  function config() {
    return window.OysterWooConfig || {}
  }

  /**
   * Last resort when the checkout handoff can't complete (network error,
   * non-2xx from /cart/add, or a malformed response) — sends the shopper to
   * a real page instead of leaving them stuck watching the widget with no
   * feedback and no way forward. `?oyster_checkout_error=1` lets
   * Cart_Controller surface an actual WooCommerce notice on arrival.
   */
  function redirectToFallback() {
    var base = config().cartUrl || '/'
    var sep = base.indexOf('?') === -1 ? '?' : '&'
    window.location.href = base + sep + 'oyster_checkout_error=1'
  }

  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script')
      s.src = src
      s.async = true
      s.onload = resolve
      s.onerror = function () {
        reject(new Error('Failed to load script: ' + src))
      }
      document.head.appendChild(s)
    })
  }

  function postJson(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(body),
      // The plugin's cart-add endpoint mutates the visitor's own WooCommerce
      // cart session — the session cookie must ride along, and it must
      // survive into the checkout-page request the redirect below triggers.
      credentials: 'same-origin',
    }).then(function (res) {
      if (!res.ok) throw new Error(url + ' -> ' + res.status)
      return res.json()
    })
  }

  /**
   * Handles the widget's `checkout` event payload (CheckoutPayload):
   *   {
   *     total_quantity, total_amount, currency,
   *     checkout_items: [{
   *       product_id (Oyster id), product_name, quantity, sku,
   *       skin_analysis_batch_id, product_usage_routine_id, ...
   *     }],
   *     user?: { id?, email?, ... }
   *   }
   *
   * This never resolves Oyster ids or calls Oyster's API directly from the
   * browser — that would require exposing the vendor bearer client-side.
   * Instead the whole handoff (resolve + add to cart + attribution) happens
   * server-side in the plugin's own `/cart/add` REST route; this function
   * just builds that request and follows the redirect it returns.
   */
  function wooCheckoutHandoff(payload) {
    console.debug('[oyster] checkout handoff', payload)
    var items = (payload && payload.checkout_items) || []
    if (!items.length) {
      console.warn('[oyster] checkout payload had no checkout_items')
      return
    }

    var lineItems = items
      .map(function (item) {
        return item && typeof item.product_id === 'number'
          ? { product_id: item.product_id, quantity: item.quantity || 1 }
          : null
      })
      .filter(function (v) {
        return v !== null
      })

    if (!lineItems.length) {
      console.warn('[oyster] no valid product ids in checkout_items')
      redirectToFallback()
      return
    }

    // Attribution fields all live on the per-item record — the first item is
    // authoritative for a cart that happens to mix recommended + unrelated
    // products.
    var firstItem = items[0] || {}
    var batchId = firstItem.skin_analysis_batch_id || null
    var routineId = firstItem.product_usage_routine_id || null
    var attributionId = payload.widget_attribution_id || null

    postJson('/wp-json/oyster-woocommerce/v1/cart/add', {
      items: lineItems,
      batch_id: batchId,
      routine_id: routineId,
      widget_attribution_id: attributionId,
    })
      .then(function (result) {
        if (result && result.skipped) {
          console.warn(
            '[oyster] ' +
              result.skipped +
              ' recommended product(s) skipped — not yet synced or out of stock',
          )
        }
        if (result && result.redirect) {
          window.location.href = result.redirect
        } else {
          // 2xx with no redirect shouldn't happen, but don't strand the
          // shopper on a silently-broken widget if it does.
          console.warn('[oyster] cart/add succeeded but returned no redirect')
          redirectToFallback()
        }
      })
      .catch(function (err) {
        console.error('[oyster] checkout handoff failed', err)
        redirectToFallback()
      })
  }

  function widgetCallback(message) {
    console.debug('[oyster] widget callback', message)
    if (!message || message.event !== 'checkout') return
    wooCheckoutHandoff(message.data || {})
  }

  function bootAnchor(anchor) {
    var cfg = config()
    if (!cfg.publicKey) {
      console.warn('[oyster] no publicKey in OysterWooConfig — vendor not connected?')
      return
    }

    var mode = anchor.dataset.mode === 'inline' ? 'inline' : 'float'

    loadScript(cfg.loaderUrl || DEFAULT_WIDGET_BUNDLE)
      .then(function () {
        if (!window.OysterskinWidget) {
          console.warn('[oyster] bundle loaded but window.OysterskinWidget is undefined')
          return
        }

        var options = {
          mode: mode,
          publicKey: cfg.publicKey,
          primaryColor: anchor.dataset.primaryColor || cfg.primaryColor || '#0e1e3a',
          callback: widgetCallback,
          app: 'woocommerce',
        }

        if (mode === 'inline') {
          options.container = anchor
          var h = parseInt(anchor.dataset.inlineHeight, 10)
          if (h > 0) options.inlineHeight = h
        } else {
          options.autoOpen = anchor.dataset.autoOpen === 'true'
          options.displayLogo = anchor.dataset.displayLogo === 'true'
          options.introMessage = anchor.dataset.introMessage || ''
          options.messageBody = anchor.dataset.messageBody || ''
        }

        window.OysterskinWidget.createScanWidget(options)
      })
      .catch(function (err) {
        console.warn('[oyster] widget init failed', err && err.message ? err.message : err)
      })
  }

  function init() {
    var anchors = document.querySelectorAll('[data-oyster-widget]')
    if (!anchors.length) return
    Array.prototype.forEach.call(anchors, bootAnchor)
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init)
  } else {
    init()
  }
})()
