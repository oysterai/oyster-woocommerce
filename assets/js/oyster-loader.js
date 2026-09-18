/**
 * Storefront loader for the Oyster widget on WooCommerce.
 *
 * Config is injected inline as `window.OysterWooConfig` rather than fetched:
 * the PHP side runs server-side and already holds the vendor's public key.
 *
 * Failure paths console.warn for self-diagnosis but never render fallback UI on
 * the storefront, so a half-set-up install cannot leak errors to shoppers.
 */
(function () {
  var DEFAULT_WIDGET_BUNDLE =
    'https://widget-lib.oysterskin.com/v1/oysterskin-vendor-widget-web.umd.js'

  function config() {
    return window.OysterWooConfig || {}
  }

  /**
   * Last resort when the checkout handoff cannot complete: a shopper left
   * watching the widget gets no feedback and no way forward. The query flag is
   * what lets Cart_Controller surface a real WooCommerce notice on arrival.
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
      // The cart session cookie has to ride along and survive into the
      // checkout-page request the redirect triggers.
      credentials: 'same-origin',
    }).then(function (res) {
      if (!res.ok) throw new Error(url + ' -> ' + res.status)
      return res.json()
    })
  }

  /**
   * Nothing is resolved against Oyster from the browser: that would mean
   * exposing the vendor bearer client-side. The whole handoff happens in the
   * plugin's own `/cart/add` route, and this only follows the redirect.
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

    // First item wins for a cart mixing recommended and unrelated products.
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
          // Shouldn't happen, but don't strand the shopper if it does.
          console.warn('[oyster] cart/add succeeded but returned no redirect')
          redirectToFallback()
        }
      })
      .catch(function (err) {
        console.error('[oyster] checkout handoff failed', err)
        redirectToFallback()
      })
  }

  /**
   * Holds nothing but the opaque batch id the widget is already working with.
   * PHP owns the name and lifetime because the checkout is what reads it back,
   * so a missing config (an older plugin) writes nothing.
   */
  function rememberScan(batchId) {
    var cookie = config().scanCookie
    if (!cookie || !cookie.name || !batchId) return

    var maxAge = (cookie.days || 90) * 24 * 60 * 60
    var secure = window.location.protocol === 'https:' ? '; Secure' : ''

    document.cookie =
      encodeURIComponent(cookie.name) +
      '=' +
      encodeURIComponent(batchId) +
      '; Max-Age=' +
      maxAge +
      '; Path=/; SameSite=Lax' +
      secure
  }

  function widgetCallback(message) {
    console.debug('[oyster] widget callback', message)
    if (!message) return

    if (message.event === 'scanCompleted') {
      var scan = message.data || {}
      // Both spellings: the two have drifted apart before, and a missed cookie
      // is invisible until attribution is quietly short.
      rememberScan(scan.batch_id || scan.batchId || null)
      return
    }

    if (message.event !== 'checkout') return
    wooCheckoutHandoff(message.data || {})
  }

  /**
   * Only ever called for vendors set up to take scan payments themselves.
   *
   * What is returned here only moves the widget's UI along: the scan is
   * unblocked when the order reaches a paid state and PHP says so, with a
   * credential that never touches this page.
   */
  function collectScanPayment(request) {
    var cfg = config()
    if (!cfg.scanPaymentUrl) {
      return Promise.resolve({
        status: 'failed',
        reason: 'This store is not set up to collect scan payments.',
      })
    }

    return postJson(cfg.scanPaymentUrl, {
      reference: request.reference,
      amount: request.amount,
      currency: request.currency,
      email: request.email,
      batch_id: request.batchId,
    })
      .then(function (res) {
        if (!res || !res.checkout_url) {
          return { status: 'failed', reason: 'Could not start the payment.' }
        }

        return { status: 'redirect', checkoutUrl: res.checkout_url }
      })
      .catch(function (err) {
        console.error('[oyster] scan payment could not be started', err)

        // A silent handler leaves the shopper through the widget's full timeout.
        return { status: 'failed', reason: 'Could not start the payment.' }
      })
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
          callback: widgetCallback,
          onCollectPayment: collectScanPayment,
          app: 'woocommerce',
        }

        // The widget applies the vendor's dashboard colour only for options the
        // host omits, so a default here would silently outrank it everywhere.
        var primaryColor = anchor.dataset.primaryColor || cfg.primaryColor
        if (primaryColor) options.primaryColor = primaryColor

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
