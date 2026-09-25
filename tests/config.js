/**
 * Site specific values for the tests, shared with the PHP helpers in tests/bin.
 * See tests/README.md for what each value means and how to find it on your own site.
 *
 * Checkout page ids, shipping rate ids and the store shape are deliberately NOT in config.json.
 * Page ids differ between sites and slugs change (WooCommerce's "switch to block checkout"
 * renames both pages), and shipping instance ids are a global auto-increment that every run
 * pushes higher, so none of them can be written down. bin/setup-site.php resolves the pages from
 * WooCommerce's settings, creates the shipping methods it needs, and writes the result to
 * site-state.json, which is merged in here.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');

const config = require('./config.json');

// Where a run keeps its files. lib/artifacts.js and the PHP helpers use the same rule.
const artifactsBase = config.artifactsDir || path.join(os.tmpdir(), 'wc-cop-tests');

let state = {};
try {
  state = JSON.parse(fs.readFileSync(path.join(artifactsBase, 'site-state.json'), 'utf8'));
} catch (e) {
  // setup-site.php has not run yet; checkoutUrl() below explains that when a test asks.
}

/**
 * Absolute url of the discovered checkout page.
 *
 * @param {'classic'|'block'} kind Which checkout to drive.
 */
function checkoutUrl(kind) {
  const p = kind === 'block' ? state.blockCheckoutPath : state.classicCheckoutPath;
  if (!p) {
    throw new Error(
      `No ${kind} checkout page recorded. Run bin/setup-site.php first:\n` +
        `  wp eval-file wp-content/plugins/wc-cash-on-pickup/tests/bin/setup-site.php`
    );
  }
  return config.baseUrl + p;
}

/**
 * A shipping rate id recorded by setup-site.php.
 *
 * Throws rather than returning undefined: a matrix that silently tested `undefined` as a rate
 * would pass for the wrong reason. `localPickup` legitimately does not exist on a block-first
 * store, so ask with rateOrNull() where absence is expected.
 *
 * @param {string} name One of flatRate, flatRateOther, localPickup, pickupLocation, pickupLocationOther.
 */
function rate(name) {
  const value = (state.rates || {})[name];
  if (!value) {
    throw new Error(
      `No "${name}" shipping rate recorded for the ${state.storeShape || 'unknown'} store shape.\n` +
        `Run bin/setup-site.php first:\n` +
        `  wp eval-file wp-content/plugins/wc-cash-on-pickup/tests/bin/setup-site.php [block-first]`
    );
  }
  return value;
}

/** Same, but undefined when the current store shape does not have that rate. */
function rateOrNull(name) {
  return (state.rates || {})[name];
}

module.exports = Object.assign({ artifactsBase, checkoutUrl, rate, rateOrNull }, config, state);
