/**
 * Storefront loader for the Oyster widget on WooCommerce.
 *
 * Unlike the Shopify build, config isn't fetched over a signed App Proxy call —
 * the PHP plugin injects it inline as `window.OysterWooConfig` because it runs
 * server-side and already holds the vendor's public key. This script just:
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

  /**
   * Handles the widget's `checkout` event on WooCommerce.
   *
   * P3 (checkout attribution) implements this fully: resolve Oyster product ids
   * to Woo product/variation ids, add them to the Woo cart via the Store API,
   * and stamp oyster_batch_id / oyster_routine_id onto the order so
   * woocommerce_payment_complete can attribute it back to the scan. For now we
   * log the payload so the event contract can be verified against a live scan.
   */
  function wooCheckoutHandoff(payload) {
    console.debug('[oyster] checkout event (handoff lands in P3)', payload)
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
          // The widget constructor validates `app` and maps it to the request
          // channel. 'woocommerce' must be an accepted value in
          // vendor-widget-core (mirrors the existing 'shopify' case).
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
