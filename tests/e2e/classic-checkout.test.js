/**
 * Availability matrix for the CLASSIC (shortcode) checkout.
 *
 * Drives the page the way a customer does: clicks a shipping method radio, lets WooCommerce's
 * own update_order_review AJAX refresh the page, then reads the payment methods that are
 * offered. Guards against regressions in the shared PHP while the block support is developed.
 *
 * Run with the store's normal classic checkout page active (tests/bin/restore-site.sh state):
 *
 *   node tests/e2e/classic-checkout.test.js
 */
const fs = require('fs');
const path = require('path');
const { Chrome, sleep } = require('../lib/cdp.js');
const { artifactPath, profilePath } = require('../lib/artifacts.js');
const { setGatewaySettings } = require('../lib/wp.js');
const config = require('../config.js');

const CHECKOUT = config.checkoutUrl('classic');
const R = config.rates;

// The classic matrix drives zone based local_pickup, which only a grandfathered store has.
// config.rate() throws with an explanation if the run was set up block-first.
const LOCAL_PICKUP = config.rate('localPickup');
// The block checkout's "pickup_location" method only exists when the Checkout block is the
// store's checkout page, so the classic matrix never sees it.
const LOCAL_PICKUP_RATES = [LOCAL_PICKUP];

function expectCop(s, sc) {
  if (s.enabled !== 'yes') return false;
  if (!sc.needsShipping) return s.enable_for_virtual === 'yes';
  const allow = s.enable_for_methods;
  if (!allow.length) return true;
  if (!sc.rate) return true;
  return allow.some((m) => sc.rate === m || sc.rate.startsWith(m + ':'));
}
function expectOnlyCop(s, sc) {
  if (s.exclusive_for_local !== 'yes') return false;
  if (!expectCop(s, sc)) return false;
  if (!sc.needsShipping || !sc.rate) return false;
  return LOCAL_PICKUP_RATES.includes(sc.rate);
}

const READ = `
  const inputs = [...document.querySelectorAll('input[name="payment_method"]')];
  return {
    methods: inputs.map(i => i.value).sort(),
    checked: inputs.filter(i => i.checked).map(i => i.value),
    copDescription: document.querySelector('.payment_method_cop .payment_box')?.innerHTML?.trim() || null,
    offeredRates: [...document.querySelectorAll('input.shipping_method')].map(i => i.value),
    chosenRate: [...document.querySelectorAll('input.shipping_method')].filter(i => i.checked).map(i => i.value),
  };
`;

(async () => {
  const scenarios = [
    { name: `physical/${R.flatRate}`, products: [config.physicalProductId], rate: R.flatRate, needsShipping: true },
    { name: `physical/${R.flatRateOther}`, products: [config.physicalProductId], rate: R.flatRateOther, needsShipping: true },
    { name: `physical/${LOCAL_PICKUP}`, products: [config.physicalProductId], rate: LOCAL_PICKUP, needsShipping: true },
    { name: `mixed/${LOCAL_PICKUP}`, products: [config.physicalProductId, config.virtualProductId], rate: LOCAL_PICKUP, needsShipping: true },
    { name: 'virtual', products: [config.virtualProductId], rate: null, needsShipping: false },
  ];
  const settingsCombos = [];
  for (const efm of [[], ['local_pickup'], [R.flatRate], ['pickup_location'], [LOCAL_PICKUP]]) {
    for (const excl of ['no', 'yes']) {
      for (const virt of ['yes', 'no']) {
        settingsCombos.push({ enable_for_methods: efm, exclusive_for_local: excl, enable_for_virtual: virt });
      }
    }
  }
  settingsCombos.push({ enabled: 'no' });

  const chrome = await new Chrome({ profile: profilePath('classic'), port: 9334 }).launch();
  const results = [];
  let pass = 0, fail = 0, n = 0;
  try {
    for (const combo of settingsCombos) {
      const s = setGatewaySettings(combo);
      for (const sc of scenarios) {
        if (sc.needsShipping && s.enable_for_virtual === 'no') continue;
        n++;
        chrome.clearErrors();
        await chrome.send('Network.clearBrowserCookies'); // fresh session => empty cart
        for (const id of sc.products) await chrome.goto(`${config.baseUrl}/?add-to-cart=${id}`);
        await chrome.goto(CHECKOUT);
        await chrome.waitFor(`document.querySelector('form.checkout') && typeof window.jQuery === 'function'`);
        await sleep(700);

        if (sc.rate) {
          const clicked = await chrome.eval(`
            const input = document.querySelector('input.shipping_method[value="${sc.rate}"]');
            if (!input) return { error: 'rate not offered', rates: [...document.querySelectorAll('input.shipping_method')].map(i => i.value) };
            // Clicking the rate that is already selected fires no change event, so WooCommerce
            // never refreshes and there is nothing to wait for - the page already shows it.
            if (input.checked) return { alreadySelected: true };
            window.__updated = false;
            jQuery(document.body).on('updated_checkout', () => { window.__updated = true; });
            input.click();
            return { ok: true };
          `);
          if (clicked.error) { console.log(`SETUP FAIL ${sc.name}: ${clicked.error} (offered ${JSON.stringify(clicked.rates)})`); fail++; continue; }
          if (!clicked.alreadySelected) {
            await chrome.waitFor('window.__updated === true', 25000);
            await sleep(600);
          }
        }
        const view = await chrome.eval(READ);

        const wantCop = expectCop(s, sc);
        const wantOnly = expectOnlyCop(s, sc);
        const gotCop = view.methods.includes('cop');
        const gotOnly = view.methods.length === 1 && gotCop;
        const errs = [...chrome.consoleErrors, ...chrome.pageErrors].filter((e) => !/favicon|404|net::ERR|Failed to load resource/i.test(e));
        const rateOk = !sc.rate || view.chosenRate.includes(sc.rate);
        const ok = rateOk && gotCop === wantCop && gotOnly === wantOnly && errs.length === 0;
        ok ? pass++ : fail++;
        const tag = `efm=${JSON.stringify(s.enable_for_methods)} excl=${s.exclusive_for_local} virt=${s.enable_for_virtual}${s.enabled === 'no' ? ' DISABLED' : ''}`;
        results.push({ tag, scenario: sc.name, wantCop, gotCop, wantOnly, gotOnly, methods: view.methods, chosenRate: view.chosenRate, rateOk, errs, ok });
        if (!ok) {
          console.log(`FAIL ${tag} | ${sc.name}`);
          console.log(`     want cop=${wantCop} onlyCop=${wantOnly} | got cop=${gotCop} onlyCop=${gotOnly} methods=${JSON.stringify(view.methods)} chosen=${JSON.stringify(view.chosenRate)} errs=${JSON.stringify(errs)}`);
        }
        if (n % 10 === 0) process.stdout.write(`  ...${n} cases (${fail} failed)\n`);
      }
    }
  } finally { await chrome.close(); }
  fs.writeFileSync(artifactPath('results-classic.json'), JSON.stringify(results, null, 1));
  console.log(`\nCLASSIC CHECKOUT: ${pass} passed, ${fail} failed, ${n} cases`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error('RUNNER ERROR:', e.stack); process.exit(2); });
