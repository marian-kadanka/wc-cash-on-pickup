/**
 * Site specific values for the tests, shared with the PHP helpers in tests/bin.
 * See tests/README.md for what each value means and how to find it on your own site.
 *
 * Checkout page ids and urls are deliberately NOT in config.json: page ids differ between
 * sites and slugs change (WooCommerce's "switch to block checkout" renames both pages).
 * bin/setup-site.php reads them from WooCommerce's own page settings - creating pages only
 * when the store has none - and writes them to site-state.json, which is merged in here.
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

module.exports = Object.assign({ artifactsBase, checkoutUrl }, config, state);
