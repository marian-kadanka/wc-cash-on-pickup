/**
 * Availability matrix for the BLOCK based checkout.
 *
 * For every combination of gateway settings, sets up a cart through the Store API, loads the
 * Checkout block in headless Chrome and asserts on what is actually rendered - plus on the
 * gateway list the server returns, and on the browser console staying clean.
 *
 * Requires the site to be prepared with tests/bin/setup-site.sh (the block page must be the
 * store's checkout page, otherwise WooCommerce does not register its "pickup_location" method).
 *
 *   node tests/e2e/block-checkout.test.js
 */
const fs = require('fs');
const path = require('path');
const { Chrome, sleep } = require('../lib/cdp.js');
const { artifactPath, profilePath } = require('../lib/artifacts.js');
const { setGatewaySettings } = require('../lib/wp.js');
const config = require('../config.js');

const CHECKOUT = config.checkoutUrl('block');
const R = config.rates;
const LOCAL_PICKUP_RATES = [R.localPickup, R.pickupLocation];

// --- what the documented feature set says should happen ------------------------------------
function expectCop(s, sc) {
  if (s.enabled !== 'yes') return false;
  if (!sc.needsShipping) return s.enable_for_virtual === 'yes';
  const allow = s.enable_for_methods;
  if (!allow.length) return true;
  if (!sc.rate) return true; // nothing chosen yet - the server has the final word
  return allow.some((m) => sc.rate === m || sc.rate.startsWith(m + ':'));
}
function expectOnlyCop(s, sc) {
  if (s.exclusive_for_local !== 'yes') return false;
  if (!expectCop(s, sc)) return false;           // never hide the others if COP itself is gone
  if (!sc.needsShipping || !sc.rate) return false;
  return LOCAL_PICKUP_RATES.includes(sc.rate);
}

const PAGE_API = `
  const nonce = window.wcBlocksMiddlewareConfig.storeApiNonce;
  const api = async (p, method, body) => {
    const res = await fetch('/wp-json/wc/store/v1' + p, {
      method, credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Nonce': nonce },
      body: body ? JSON.stringify(body) : undefined,
    });
    return { status: res.status, body: await res.json() };
  };
`;

async function setupCart(chrome, sc) {
  const { email, phone, ...addr } = config.address;
  return chrome.eval(`
    ${PAGE_API}
    await api('/cart/items', 'DELETE');
    for (const id of ${JSON.stringify(sc.products)}) {
      const r = await api('/cart/add-item', 'POST', { id, quantity: 1 });
      if (r.status >= 400) return { error: 'add-item ' + id + ': ' + JSON.stringify(r.body) };
    }
    const addr = ${JSON.stringify(addr)};
    const cu = await api('/cart/update-customer', 'POST', {
      shipping_address: addr,
      billing_address: { ...addr, email: ${JSON.stringify(email)}, phone: ${JSON.stringify(phone)} },
    });
    if (cu.status >= 400) return { error: 'update-customer: ' + JSON.stringify(cu.body) };
    if (${JSON.stringify(sc.rate)}) {
      const sel = await api('/cart/select-shipping-rate', 'POST', { package_id: 0, rate_id: ${JSON.stringify(sc.rate)} });
      if (sel.status >= 400) return { error: 'select-rate: ' + JSON.stringify(sel.body) };
    }
    const cart = (await api('/cart', 'GET')).body;
    return {
      needsShipping: cart.needs_shipping,
      serverPaymentMethods: cart.payment_methods,
      chosen: (cart.shipping_rates || []).flatMap(p => p.shipping_rates.filter(r => r.selected).map(r => r.rate_id)),
    };
  `);
}

async function readCheckout(chrome) {
  await chrome.waitFor(`document.querySelector('.wp-block-woocommerce-checkout-payment-block') && !document.querySelector('.wc-block-checkout.is-loading') && !document.querySelector('.wc-block-components-skeleton')`);
  await sleep(900);
  return chrome.eval(`
    const store = window.wp && wp.data && wp.data.select('wc/store/cart');
    const cartData = store ? store.getCartData() : null;
    const radios = [...document.querySelectorAll('input[name="radio-control-wc-payment-method-options"]')];
    return {
      methods: radios.map(r => r.value).sort(),
      checked: radios.filter(r => r.checked).map(r => r.value),
      labels: [...document.querySelectorAll('.wc-block-components-payment-method-label')].map(e => e.textContent.trim()),
      hydratedRates: cartData ? (cartData.shippingRates || []).flatMap(p => p.shipping_rates.filter(r => r.selected).map(r => r.rate_id)) : null,
      hydratedPaymentMethods: cartData ? cartData.paymentMethods : null,
    };
  `);
}

(async () => {
  const scenarios = [
    ...[R.flatRate, R.flatRateOther, R.localPickup, R.pickupLocation].map((rate) => (
      { name: `physical/${rate}`, products: [config.physicalProductId], rate, needsShipping: true }
    )),
    { name: `mixed/${R.localPickup}`, products: [config.physicalProductId, config.virtualProductId], rate: R.localPickup, needsShipping: true },
    { name: `mixed/${R.flatRate}`, products: [config.physicalProductId, config.virtualProductId], rate: R.flatRate, needsShipping: true },
    { name: 'virtual', products: [config.virtualProductId], rate: null, needsShipping: false },
  ];

  const settingsCombos = [];
  for (const efm of [[], ['local_pickup'], [R.flatRate], ['pickup_location']]) {
    for (const excl of ['no', 'yes']) {
      for (const virt of ['yes', 'no']) {
        settingsCombos.push({ enable_for_methods: efm, exclusive_for_local: excl, enable_for_virtual: virt });
      }
    }
  }
  settingsCombos.push({ enabled: 'no' }); // the gateway switched off entirely

  const chrome = await new Chrome({ profile: profilePath('block'), port: 9333 }).launch();
  const results = [];
  let pass = 0, fail = 0, n = 0;
  try {
    await chrome.goto(`${config.baseUrl}/?add-to-cart=${config.physicalProductId}`);
    for (const combo of settingsCombos) {
      const s = setGatewaySettings(combo);
      for (const sc of scenarios) {
        // enable_for_virtual only changes the virtual cart; do not repeat the rest for it.
        if (sc.needsShipping && s.enable_for_virtual === 'no') continue;
        n++;
        chrome.clearErrors();
        await chrome.goto(CHECKOUT);
        const setup = await setupCart(chrome, sc);
        if (setup.error) { console.log(`SETUP FAIL ${sc.name}: ${setup.error}`); fail++; continue; }
        await chrome.goto(CHECKOUT);
        let view = await readCheckout(chrome);

        // The page must really have this scenario's rate selected, otherwise we would be
        // asserting against a different situation than the one we set up. Rather than reload
        // and hope, pick the rate through the checkout's own data store - the same action the
        // shipping options in the UI dispatch - and wait for the cart to come back with it.
        if (sc.rate && !(view.hydratedRates || []).includes(sc.rate)) {
          await chrome.eval(`
            await wp.data.dispatch('wc/store/cart').selectShippingRate(${JSON.stringify(sc.rate)}, 0);
            for (let i = 0; i < 80; i++) {
              const cart = wp.data.select('wc/store/cart').getCartData();
              const selected = (cart.shippingRates || []).flatMap(p => p.shipping_rates.filter(r => r.selected).map(r => r.rate_id));
              if (selected.includes(${JSON.stringify(sc.rate)})) return { ok: true, selected };
              await new Promise(r => setTimeout(r, 150));
            }
            return { ok: false };
          `);
          await sleep(1200);
          view = await readCheckout(chrome);
        }
        const rateOk = !sc.rate || (view.hydratedRates || []).includes(sc.rate);

        const wantCop = expectCop(s, sc);
        const wantOnly = expectOnlyCop(s, sc);
        const gotCop = view.methods.includes('cop');
        const gotOnly = view.methods.length === 1 && gotCop;
        const serverCop = (setup.serverPaymentMethods || []).includes('cop');
        const errs = [...chrome.consoleErrors, ...chrome.pageErrors].filter((e) => !/favicon|404|net::ERR/i.test(e));

        const ok = rateOk && gotCop === wantCop && gotOnly === wantOnly && serverCop === wantCop && errs.length === 0;
        ok ? pass++ : fail++;
        const tag = `efm=${JSON.stringify(s.enable_for_methods)} excl=${s.exclusive_for_local} virt=${s.enable_for_virtual}${s.enabled === 'no' ? ' DISABLED' : ''}`;
        results.push({ tag, scenario: sc.name, wantCop, gotCop, serverCop, wantOnly, gotOnly, methods: view.methods, hydratedRates: view.hydratedRates, rateOk, errs, ok });
        if (!ok) {
          console.log(`FAIL ${tag} | ${sc.name}`);
          console.log(`     want cop=${wantCop} onlyCop=${wantOnly} | got cop=${gotCop} (server ${serverCop}) onlyCop=${gotOnly} methods=${JSON.stringify(view.methods)}`);
          console.log(`     rateOk=${rateOk} pageRate=${JSON.stringify(view.hydratedRates)} errs=${JSON.stringify(errs)}`);
        }
        if (n % 10 === 0) process.stdout.write(`  ...${n} cases (${fail} failed)\n`);
      }
    }
  } finally { await chrome.close(); }
  fs.writeFileSync(artifactPath('results-block.json'), JSON.stringify(results, null, 1));
  console.log(`\nBLOCK CHECKOUT: ${pass} passed, ${fail} failed, ${n} cases`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error('RUNNER ERROR:', e.stack); process.exit(2); });
