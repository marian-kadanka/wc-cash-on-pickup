/**
 * The block checkout's "Pickup locations" UI.
 *
 * The other matrices select a shipping rate through the Store API and never touch the
 * Ship / Pickup toggle. That skips the only surface a customer collecting an order actually
 * uses, and it is where the block checkout differs most from the classic one:
 *
 *   - the Pickup tab is not rendered until it is selected, and selecting it auto-picks the
 *     first collection rate;
 *   - the tab merges *both* kinds of collection into one "Pickup locations" list - zone based
 *     local_pickup (a plain row) and the block's own pickup_location (a row per configured
 *     location, with its address and opening details);
 *   - every row in that list must count as local pickup for the gateway, which is what
 *     "Disable other payment methods for local pickup" depends on.
 *
 * Requires bin/setup-site.php. Adapts to the store shape it set up: a block-first store has no
 * zone local_pickup, so the list is pickup_location rows only.
 *
 *   node tests/e2e/block-pickup-ui.test.js
 */
const fs = require('fs');
const { Chrome, sleep } = require('../lib/cdp.js');
const { artifactPath, profilePath } = require('../lib/artifacts.js');
const { setGatewaySettings } = require('../lib/wp.js');
const config = require('../config.js');

const CHECKOUT = config.checkoutUrl('block');
const R = config.rates;

// What the Pickup list must contain, in the shape setup-site.php produced.
const EXPECTED_PICKUP_RATES = [R.localPickup, R.pickupLocation, R.pickupLocationOther].filter(Boolean);

function expectCop(s, rateId) {
  if (s.enabled !== 'yes') return false;
  const allow = s.enable_for_methods;
  if (!allow.length) return true;
  return allow.some((m) => rateId === m || rateId.startsWith(m + ':'));
}

// Every row in the Pickup list is a collection rate, so exclusivity applies to all of them.
function expectOnlyCop(s, rateId) {
  return s.exclusive_for_local === 'yes' && expectCop(s, rateId);
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

async function setupCart(chrome) {
  const { email, phone, ...addr } = config.address;
  return chrome.eval(`
    ${PAGE_API}
    await api('/cart/items', 'DELETE');
    const r = await api('/cart/add-item', 'POST', { id: ${config.physicalProductId}, quantity: 1 });
    if (r.status >= 400) return { error: 'add-item: ' + JSON.stringify(r.body) };
    const addr = ${JSON.stringify(addr)};
    const cu = await api('/cart/update-customer', 'POST', {
      shipping_address: addr,
      billing_address: { ...addr, email: ${JSON.stringify(email)}, phone: ${JSON.stringify(phone)} },
    });
    if (cu.status >= 400) return { error: 'update-customer: ' + JSON.stringify(cu.body) };
    return { ok: true };
  `);
}

async function settled(chrome) {
  await chrome.waitFor(`document.querySelector('.wp-block-woocommerce-checkout-payment-block') && !document.querySelector('.wc-block-checkout.is-loading') && !document.querySelector('.wc-block-components-skeleton')`);
  await sleep(900);
}

/** Clicks the Pickup half of the Ship / Pickup toggle and waits for the list to render. */
async function openPickupTab(chrome) {
  const clicked = await chrome.eval(`
    // WooCommerce renders the toggle as [Ship, Pickup] in that order. Identify Pickup by its
    // position, not by which half is unselected - once a collection rate is in the cart the
    // Pickup half is the selected one, and "the unchecked option" is then Ship.
    const opts = [...document.querySelectorAll('.wc-block-checkout__shipping-method-option')];
    if (opts.length < 2) return { error: 'no Ship/Pickup toggle rendered' };
    const pickup = opts[1];
    if (pickup.getAttribute('aria-checked') === 'true') return { alreadyOpen: true };
    pickup.click();
    return { clicked: true };
  `);
  if (clicked.error) return clicked;
  await chrome.waitFor(`!!document.querySelector('.wc-block-checkout__pickup-options input[type=radio]')`);
  await sleep(700);
  return clicked;
}

/** The rows of the "Pickup locations" list, as rendered. */
async function readPickupOptions(chrome) {
  return chrome.eval(`
    const rows = [...document.querySelectorAll('.wc-block-checkout__pickup-options .wc-block-components-radio-control__option')];
    return rows.map(row => {
      const input = row.querySelector('input[type=radio]') || document.getElementById(row.getAttribute('for'));
      const text = row.innerText.replace(/\\n+/g, ' | ').trim();
      return {
        rateId: input ? input.value : null,
        checked: input ? input.checked : false,
        text,
        // pickup_location rows carry the location's address; a plain local_pickup row does not.
        hasAddress: /\\d/.test(text.split(' | ').slice(2).join(' ')),
      };
    });
  `);
}

/** Selects a row through the UI - not through the data store - and waits for the cart to agree. */
async function selectPickupOption(chrome, rateId) {
  const res = await chrome.eval(`
    const input = document.querySelector('.wc-block-checkout__pickup-options input[value=' + JSON.stringify(${JSON.stringify(rateId)}) + ']');
    if (!input) return { error: 'no radio for ${rateId}' };
    if (input.checked) return { alreadySelected: true };
    input.click();
    return { clicked: true };
  `);
  if (res.error) return res;
  const confirmed = await chrome.eval(`
    for (let i = 0; i < 80; i++) {
      const cart = wp.data.select('wc/store/cart').getCartData();
      const selected = (cart.shippingRates || []).flatMap(p => p.shipping_rates.filter(r => r.selected).map(r => r.rate_id));
      if (selected.includes(${JSON.stringify(rateId)})) return { ok: true, selected };
      await new Promise(r => setTimeout(r, 150));
    }
    return { ok: false };
  `);
  return Object.assign(res, confirmed);
}

/** Switches back to Ship and reports the rate that ended up selected. */
async function switchToShip(chrome, pickupRates) {
  return chrome.eval(`
    const opts = [...document.querySelectorAll('.wc-block-checkout__shipping-method-option')];
    if (opts.length < 2) return { error: 'no Ship/Pickup toggle rendered' };
    opts[0].click();
    const pickup = ${JSON.stringify(pickupRates)};
    for (let i = 0; i < 80; i++) {
      const cart = wp.data.select('wc/store/cart').getCartData();
      const selected = (cart.shippingRates || []).flatMap(p => p.shipping_rates.filter(r => r.selected).map(r => r.rate_id));
      if (selected.length && !selected.some(r => pickup.includes(r))) return { ok: true, rate: selected[0] };
      await new Promise(r => setTimeout(r, 150));
    }
    return { ok: false };
  `);
}

async function readPayment(chrome) {
  await settled(chrome);
  return chrome.eval(`
    // Changing the shipping rate re-renders the payment list a moment after the cart store has
    // already updated, so wait for the list to stop moving instead of guessing a delay. Reading
    // too early was good for five false failures.
    const sig = () => [...document.querySelectorAll('input[name="radio-control-wc-payment-method-options"]')]
      .map(r => r.value).sort().join(',');
    await new Promise(r => setTimeout(r, 600));
    let last = sig(), stable = 0;
    for (let i = 0; i < 60 && stable < 4; i++) {
      await new Promise(r => setTimeout(r, 250));
      const now = sig();
      stable = now === last ? stable + 1 : 0;
      last = now;
    }
    const cart = wp.data.select('wc/store/cart').getCartData();
    return {
      methods: last ? last.split(',') : [],
      // Kept so a failure shows whether the server or only the rendering was behind.
      serverMethods: cart.paymentMethods,
      selectedRates: (cart.shippingRates || []).flatMap(p => p.shipping_rates.filter(r => r.selected).map(r => r.rate_id)),
    };
  `);
}

(async () => {
  const results = [];
  let pass = 0, fail = 0;
  const check = (name, ok, detail) => {
    ok ? pass++ : fail++;
    results.push({ name, ok, detail });
    console.log(`${ok ? 'OK  ' : 'FAIL'} ${name}${ok ? '' : '  -> ' + JSON.stringify(detail)}`);
  };

  // Everything the "Enable for shipping methods" field can be set to where collection is
  // concerned. It is a multiselect, so the combinations that hold two entries at once matter
  // as much as the single ones - and they are the reason each pass also checks a shipped rate,
  // without which "match anything that collects" and "match everything" look identical.
  const LP = R.localPickup;                       // a zone local_pickup instance, when the shape has one
  const PL0 = config.rate('pickupLocation');      // one specific pickup location
  const combos = [
    { label: 'no value - enabled everywhere',                   enable_for_methods: [] },
    { label: 'any "Local pickup" method',                        enable_for_methods: ['local_pickup'], zoneOnly: true },
    { label: 'one local pickup zone instance',                   enable_for_methods: [LP], zoneOnly: true },
    { label: 'any pickup_location',                              enable_for_methods: ['pickup_location'] },
    { label: 'one specific pickup location',                     enable_for_methods: [PL0] },
    { label: 'any local_pickup AND any pickup_location',         enable_for_methods: ['local_pickup', 'pickup_location'], zoneOnly: true },
    { label: 'one local pickup instance AND any pickup_location', enable_for_methods: [LP, 'pickup_location'], zoneOnly: true },
    { label: 'no value, exclusive',                              enable_for_methods: [], exclusive_for_local: 'yes' },
    { label: 'any local_pickup AND any pickup_location, exclusive', enable_for_methods: ['local_pickup', 'pickup_location'], exclusive_for_local: 'yes', zoneOnly: true },
  ].filter((c) => !(c.zoneOnly && !LP))
   .map((c) => Object.assign({ exclusive_for_local: 'no' }, c));

  const chrome = await new Chrome({ profile: profilePath('pickup-ui'), port: 9336 }).launch();
  try {
    console.log(`store shape: ${config.storeShape}`);
    console.log(`expected pickup rates: ${EXPECTED_PICKUP_RATES.join(', ')}\n`);

    setGatewaySettings({ enable_for_methods: [], exclusive_for_local: 'no' });
    await chrome.goto(`${config.baseUrl}/?add-to-cart=${config.physicalProductId}`);
    await chrome.goto(CHECKOUT);
    const cart = await setupCart(chrome);
    if (cart.error) throw new Error('cart setup: ' + cart.error);
    await chrome.goto(CHECKOUT);
    await settled(chrome);

    // ---- structure ------------------------------------------------------------------------
    const toggle = await chrome.eval(`
      return [...document.querySelectorAll('.wc-block-checkout__shipping-method-option')]
        .map(o => ({ text: o.innerText.trim(), selected: o.getAttribute('aria-checked') === 'true' }));
    `);
    check('Ship / Pickup toggle is offered', toggle.length === 2, toggle);
    check('Ship is selected first', !!(toggle[0] && toggle[0].selected), toggle);

    const opened = await openPickupTab(chrome);
    check('Pickup tab opens', !opened.error, opened);

    const options = await readPickupOptions(chrome);
    const listed = options.map((o) => o.rateId).sort();
    check(
      'Pickup list holds exactly the collection rates',
      JSON.stringify(listed) === JSON.stringify([...EXPECTED_PICKUP_RATES].sort()),
      { listed, expected: EXPECTED_PICKUP_RATES }
    );
    check(
      'no shipped rate leaks into the Pickup list',
      !listed.some((r) => r.startsWith('flat_rate:')),
      listed
    );
    check('opening Pickup auto-selects a collection rate', options.some((o) => o.checked), options);

    // The "new type" of pickup location: a named place with an address, not just a method.
    for (const rateId of [R.pickupLocation, R.pickupLocationOther].filter(Boolean)) {
      const row = options.find((o) => o.rateId === rateId);
      check(`${rateId} shows its location name and address`, !!row && row.hasAddress && row.text.length > 0, row);
    }
    if (R.localPickup) {
      const row = options.find((o) => o.rateId === R.localPickup);
      check(`${R.localPickup} is listed as a plain pickup row`, !!row && !row.hasAddress, row);
    }

    // ---- selecting each location through the UI, per gateway setting ------------------------
    for (const combo of combos) {
      const { label, zoneOnly, ...settings } = combo;
      const s = setGatewaySettings(settings);
      const tag = `${label} | efm=${JSON.stringify(s.enable_for_methods)} excl=${s.exclusive_for_local}`;
      await chrome.goto(CHECKOUT);
      await settled(chrome);
      const opened2 = await openPickupTab(chrome);
      if (opened2.error) { check(`${tag}: Pickup tab opens`, false, opened2); continue; }

      for (const rateId of EXPECTED_PICKUP_RATES) {
        const sel = await selectPickupOption(chrome, rateId);
        if (sel.error || sel.ok === false) { check(`${tag} | ${rateId}: selectable in the UI`, false, sel); continue; }
        const view = await readPayment(chrome);
        const gotCop = view.methods.includes('cop');
        const gotOnly = view.methods.length === 1 && gotCop;
        const wantCop = expectCop(s, rateId);
        const wantOnly = expectOnlyCop(s, rateId);
        const rateHeld = view.selectedRates.includes(rateId);
        check(
          `${tag} | ${rateId}`,
          rateHeld && gotCop === wantCop && gotOnly === wantOnly,
          { wantCop, gotCop, wantOnly, gotOnly, rateHeld, methods: view.methods, serverMethods: view.serverMethods, selectedRates: view.selectedRates }
        );
      }

      // ...and the same settings against a shipped rate. Without this, "enabled for anything
      // that collects" and "enabled everywhere" would be indistinguishable.
      const shipped = await switchToShip(chrome, EXPECTED_PICKUP_RATES);
      if (shipped.error || shipped.ok === false) {
        check(`${tag} | back to Ship`, false, shipped);
      } else {
        const view = await readPayment(chrome);
        const gotCop = view.methods.includes('cop');
        const wantCop = expectCop(s, shipped.rate);
        const gotOnly = view.methods.length === 1 && gotCop;
        check(
          `${tag} | shipped ${shipped.rate}`,
          gotCop === wantCop && gotOnly === false,
          { wantCop, gotCop, gotOnly, methods: view.methods, serverMethods: view.serverMethods, selectedRates: view.selectedRates }
        );
      }

      // The next combo starts from the Pickup tab again.
      await chrome.goto(CHECKOUT);
      await settled(chrome);
      const reopened = await openPickupTab(chrome);
      if (reopened.error) { check(`${tag}: Pickup tab reopens`, false, reopened); }
    }

    // ---- switching back to Ship lifts the pickup state --------------------------------------
    setGatewaySettings({ enable_for_methods: [], exclusive_for_local: 'yes' });
    await chrome.goto(CHECKOUT);
    await settled(chrome);
    await openPickupTab(chrome);
    await selectPickupOption(chrome, EXPECTED_PICKUP_RATES[0]);
    const onPickup = await readPayment(chrome);
    check('exclusivity holds while collecting', onPickup.methods.length === 1 && onPickup.methods.includes('cop'), onPickup);

    await chrome.eval(`
      const ship = [...document.querySelectorAll('.wc-block-checkout__shipping-method-option')][0];
      ship.click();
      for (let i = 0; i < 80; i++) {
        const cart = wp.data.select('wc/store/cart').getCartData();
        const selected = (cart.shippingRates || []).flatMap(p => p.shipping_rates.filter(r => r.selected).map(r => r.rate_id));
        if (selected.some(r => r.startsWith('flat_rate:'))) return { ok: true, selected };
        await new Promise(r => setTimeout(r, 150));
      }
      return { ok: false };
    `);
    const onShip = await readPayment(chrome);
    check(
      'switching back to Ship restores the other payment methods',
      onShip.methods.length > 1 && !onShip.selectedRates.some((r) => EXPECTED_PICKUP_RATES.includes(r)),
      onShip
    );
  } finally {
    await chrome.close();
  }

  fs.writeFileSync(artifactPath('results-pickup-ui.json'), JSON.stringify(results, null, 1));
  console.log(`\nBLOCK PICKUP UI: ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error('RUNNER ERROR:', e.stack); process.exit(2); });
