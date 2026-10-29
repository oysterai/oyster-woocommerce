const assert = require('node:assert/strict')
const { readFileSync } = require('node:fs')
const { join } = require('node:path')
const { test } = require('node:test')
const vm = require('node:vm')

async function load(response, ok = true) {
  const requests = []
  const alerts = []
  let callback
  const window = {
    OysterWooConfig: { publicKey: 'public-test-key', cartUrl: '/cart' },
    OysterskinWidget: { createScanWidget: (options) => { callback = options.callback } },
    location: { href: 'https://shop.example.test/routine', origin: 'https://shop.example.test', protocol: 'https:' },
    alert: (message) => alerts.push(message),
  }
  const document = {
    readyState: 'complete',
    createElement: () => ({}),
    head: { appendChild: (script) => script.onload() },
    querySelectorAll: () => [{ dataset: {} }],
  }
  vm.runInNewContext(readFileSync(join(__dirname, '../../assets/js/oyster-loader.js'), 'utf8'), {
    window, document, URL,
    console: { debug() {}, warn() {}, error() {} },
    fetch: async (url, options) => {
      requests.push({ url, ...options, body: JSON.parse(options.body) })
      return { ok, status: ok ? 200 : 409, json: async () => response }
    },
  })
  await new Promise(setImmediate)
  return { window, requests, alerts, checkout: async (data) => {
    callback({ event: 'checkout', data })
    await new Promise(setImmediate)
  } }
}

const reward = { reward_id: 'saved-reward', claim_token: 'opaque-saved-token', redemption_authority: 'woocommerce' }

test('gift claims resolve in this store without adding or clearing routine products', async () => {
  const app = await load({ redirect: 'https://shop.example.test/?oyster_wheel_claim=saved-reward&token=opaque-saved-token' })
  await app.checkout({ intent: 'wheel_reward', wheel_reward: reward, checkout_items: [{ product_id: 123 }] })
  assert.equal(app.requests.length, 1)
  assert.equal(app.requests[0].url, '/wp-json/oyster/v1/wheel/rewards/claim')
  assert.deepEqual(app.requests[0].body, reward)
  assert.match(app.window.location.href, /oyster_wheel_claim=/)
})

test('a gift cannot redirect to another store and a failed claim leaves the page in place', async () => {
  for (const [response, ok] of [[{ redirect: 'https://attacker.example/' }, true], [{}, false]]) {
    const app = await load(response, ok)
    await app.checkout({ intent: 'wheel_reward', wheel_reward: reward })
    assert.equal(app.window.location.href, 'https://shop.example.test/routine')
    assert.equal(app.alerts.length, 1)
  }
})

test('ordinary checkout carries the saved reward through the existing cart handoff', async () => {
  const app = await load({ redirect: '/checkout' })
  await app.checkout({ wheel_reward: reward, checkout_items: [{ product_id: 123, quantity: 2 }] })
  assert.equal(app.requests[0].url, '/wp-json/oyster-woocommerce/v1/cart/add')
  assert.deepEqual(app.requests[0].body.wheel_reward, reward)
  assert.deepEqual(app.requests[0].body.items, [{ product_id: 123, quantity: 2 }])
  assert.equal(app.window.location.href, '/checkout')
})

test('failed or incomplete reward handoffs leave the customer on the widget', async () => {
  for (const ok of [false, true]) {
    const app = await load({}, ok)
    await app.checkout({ wheel_reward: reward, checkout_items: [{ product_id: 123 }] })
    assert.match(app.alerts[0], /could not apply your reward/)
    assert.equal(app.window.location.href, 'https://shop.example.test/routine')
  }
})

test('invalid reward checkout items cannot fall back to a full-price cart', async () => {
  const app = await load({})
  await app.checkout({ wheel_reward: reward, checkout_items: [{ product_id: 'invalid' }] })
  assert.equal(app.requests.length, 0)
  assert.equal(app.alerts.length, 1)
  assert.equal(app.window.location.href, 'https://shop.example.test/routine')
})

test('ordinary checkout without a reward retains its existing cart fallback', async () => {
  const app = await load({}, false)
  await app.checkout({ checkout_items: [{ product_id: 123 }] })
  assert.equal(app.alerts.length, 0)
  assert.equal(app.window.location.href, '/cart?oyster_checkout_error=1')
})
