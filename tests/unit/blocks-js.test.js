/**
 * Unit tests for assets/js/blocks/checkout.js.
 *
 * Loads the real integration script against stubbed WooCommerce Blocks globals and checks
 * what it registers: the label, the sanitized description, and the canMakePayment matrix.
 * Needs no running site - run it with `node tests/unit/blocks-js.test.js`.
 */
const fs = require('fs');
const path = require('path');
const SCRIPT = path.join(__dirname, '..', '..', 'assets', 'js', 'blocks', 'checkout.js');

function makeWindow(paymentData) {
  const el = (type, props, ...children) => ({ type: typeof type === 'function' ? (type.name || 'Component') : type, props, children, _fn: type });
  return {
    wc: {
      wcBlocksRegistry: { registerPaymentMethod: (cfg) => { captured = cfg; } },
      wcSettings: { getPaymentMethodData: (name, fallback) => (name === 'cop' ? paymentData : fallback) },
      sanitize: { sanitizeHTML: (html, opts) => '[sanitized:' + html + '|tags=' + (opts && opts.tags ? opts.tags.join(',') : 'default') + ']' },
    },
    wp: {
      element: { createElement: el, Fragment: 'Fragment', RawHTML: 'RawHTML' },
      htmlEntities: { decodeEntities: (s) => String(s).replace(/&amp;/g, '&') },
    },
  };
}

let captured = null;
function load(paymentData) {
  captured = null;
  const src = fs.readFileSync(SCRIPT, 'utf8');
  const win = makeWindow(paymentData);
  new Function('window', src)(win);
  return { cfg: captured, win };
}

let pass = 0, fail = 0;
function check(name, got, expected) {
  const ok = JSON.stringify(got) === JSON.stringify(expected);
  ok ? pass++ : fail++;
  console.log(`${ok ? 'OK  ' : 'FAIL'} ${name}  got=${JSON.stringify(got)}${ok ? '' : ' expected=' + JSON.stringify(expected)}`);
}

// --- registration ---
const base = {
  title: 'Cash on pickup &amp; more',
  description: '<p>Pay with <strong>cash</strong>.</p>',
  icon: 'https://example.test/icon.png',
  enableForVirtual: true,
  enableForShippingMethods: ['local_pickup'],
  allowedTags: ['p', 'strong'],
  supports: ['products'],
};
const { cfg } = load(base);
check('registered name', cfg.name, 'cop');
check('ariaLabel decoded', cfg.ariaLabel, 'Cash on pickup & more');
check('supports', cfg.supports, { features: ['products'] });

// --- content renders sanitized description ---
const content = cfg.content._fn();
check('content is RawHTML', content.type, 'RawHTML');
check('content sanitized w/ allowedTags', content.children[0], '[sanitized:<p>Pay with <strong>cash</strong>.</p>|tags=p,strong]');

// --- label with icon ---
const comps = { components: { PaymentMethodLabel: function PaymentMethodLabel() {}, PaymentMethodIcons: function PaymentMethodIcons() {} } };
const label = cfg.label._fn(comps);
check('label is Fragment when icon set', label.type, 'Fragment');
check('label text', label.children[0].props.text, 'Cash on pickup & more');
check('icon passed', label.children[1].props.icons[0].src, 'https://example.test/icon.png');

// --- label without icon, and without the icons component ---
const noIcon = load(Object.assign({}, base, { icon: '' })).cfg;
check('label is plain label when no icon', noIcon.label._fn(comps).type, 'PaymentMethodLabel');

// --- empty description renders nothing ---
const noDesc = load(Object.assign({}, base, { description: '' })).cfg;
check('empty description renders null', noDesc.content._fn(), null);

// --- canMakePayment matrix ---
function cmp(data, args) { return load(data).cfg.canMakePayment(args); }
const restricted = Object.assign({}, base, { enableForShippingMethods: ['local_pickup', 'flat_rate:5'] });

check('virtual + enabled', cmp(base, { cartNeedsShipping: false }), true);
check('virtual + disabled', cmp(Object.assign({}, base, { enableForVirtual: false }), { cartNeedsShipping: false }), false);
check('no restrictions', cmp(Object.assign({}, base, { enableForShippingMethods: [] }), { cartNeedsShipping: true, selectedShippingMethods: { 0: 'flat_rate:1' } }), true);
check('no rate chosen yet', cmp(restricted, { cartNeedsShipping: true, selectedShippingMethods: {} }), true);
check('method id matches instance rate', cmp(restricted, { cartNeedsShipping: true, selectedShippingMethods: { 0: 'local_pickup:2' } }), true);
check('exact rate id matches', cmp(restricted, { cartNeedsShipping: true, selectedShippingMethods: { 0: 'flat_rate:5' } }), true);
check('other instance of same method does not match', cmp(restricted, { cartNeedsShipping: true, selectedShippingMethods: { 0: 'flat_rate:1' } }), false);
check('non matching method', cmp(restricted, { cartNeedsShipping: true, selectedShippingMethods: { 0: 'free_shipping:9' } }), false);
check('blocks pickup rate', cmp(Object.assign({}, base, { enableForShippingMethods: ['pickup_location'] }), { cartNeedsShipping: true, selectedShippingMethods: { 0: 'pickup_location:0' } }), true);
check('one of several packages matches', cmp(restricted, { cartNeedsShipping: true, selectedShippingMethods: { 0: 'free_shipping:9', 1: 'local_pickup:2' } }), true);
check('no prefix false positive', cmp(Object.assign({}, base, { enableForShippingMethods: ['local_pickup'] }), { cartNeedsShipping: true, selectedShippingMethods: { 0: 'local_pickup_plus:1' } }), false);

// --- degrades without wc.sanitize ---
const src = fs.readFileSync(SCRIPT, 'utf8');
const win = makeWindow(base);
delete win.wc.sanitize;
captured = null;
new Function('window', src)(win);
const fallback = captured.content._fn();
check('falls back to stripped text', fallback.children[0], 'Pay with cash.');

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
