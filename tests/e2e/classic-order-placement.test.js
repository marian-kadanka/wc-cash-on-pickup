/**
 * Places real orders through the CLASSIC checkout UI.
 *
 * The block checkout is covered by order-placement.test.js; this one exists because the two
 * flows reach process_payment() by different routes. In particular it is what proves the cart
 * hash guard added in 2.0.0 still empties the cart on a normal classic checkout.
 *
 * Needs guest checkout enabled and the classic page as the store's checkout page - that is the
 * state tests/bin/setup-site.php plus run-all.sh's classic phase set up.
 *
 *   node tests/e2e/classic-order-placement.test.js
 */
const fs = require('fs');
const path = require('path');
const { Chrome, sleep } = require('../lib/cdp.js');
const { artifactPath, profilePath } = require('../lib/artifacts.js');
const { wpEval, setGatewaySettings } = require('../lib/wp.js');
const config = require('../config.js');

const CHECKOUT = config.checkoutUrl('classic');
const MARKER = config.instructionsMarker;

/** Sets a checkout field the way a customer typing into it would. */
const fillField = (id, value) => `
  (() => {
    const el = document.getElementById(${JSON.stringify(id)});
    if (!el) return false;
    el.value = ${JSON.stringify(value)};
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  })()`;

(async () => {
  const cases = [
    { name: 'physical + local pickup, status=on-hold', products: [config.physicalProductId], rate: config.rate('localPickup'), status: 'on-hold' },
    { name: 'virtual, status=processing', products: [config.virtualProductId], rate: null, status: 'processing' },
  ];

  const chrome = await new Chrome({ profile: profilePath('classic-orders'), port: 9336 }).launch();
  const results = [];
  let pass = 0, fail = 0;
  try {
    for (const c of cases) {
      chrome.clearErrors();
      setGatewaySettings({ default_order_status: c.status, exclusive_for_local: 'no' });
      await chrome.send('Network.clearBrowserCookies');
      for (const id of c.products) await chrome.goto(`${config.baseUrl}/?add-to-cart=${id}`);
      await chrome.goto(CHECKOUT);
      await chrome.waitFor(`document.querySelector('form.checkout') && typeof window.jQuery === 'function'`);
      await sleep(800);

      const a = config.address;
      const filled = await chrome.eval(`
        window.__updated = false;
        jQuery(document.body).on('updated_checkout', () => { window.__updated = true; });
        const results = {
          first_name: ${fillField('billing_first_name', a.first_name)},
          last_name: ${fillField('billing_last_name', a.last_name)},
          address_1: ${fillField('billing_address_1', a.address_1)},
          city: ${fillField('billing_city', a.city)},
          postcode: ${fillField('billing_postcode', a.postcode)},
          email: ${fillField('billing_email', a.email)},
          phone: ${fillField('billing_phone', a.phone)},
        };
        jQuery(document.body).trigger('update_checkout');
        return results;
      `);
      await chrome.waitFor('window.__updated === true', 25000);
      await sleep(600);

      if (c.rate) {
        const picked = await chrome.eval(`
          const input = document.querySelector('input.shipping_method[value="${c.rate}"]');
          if (!input) return { error: 'rate not offered' };
          if (input.checked) return { alreadySelected: true };
          window.__updated = false;
          input.click();
          return { ok: true };
        `);
        if (picked.error) { console.log(`SETUP FAIL ${c.name}: ${picked.error}`); fail++; continue; }
        if (!picked.alreadySelected) { await chrome.waitFor('window.__updated === true', 25000); await sleep(600); }
      }

      const pick = await chrome.eval(`
        const cop = document.querySelector('#payment_method_cop');
        if (!cop) return { error: 'cop not offered', methods: [...document.querySelectorAll('input[name=payment_method]')].map(i => i.value) };
        cop.click();
        const terms = document.querySelector('#terms');
        if (terms && !terms.checked) terms.click();
        await new Promise(r => setTimeout(r, 400));
        return {
          copChecked: document.querySelector('#payment_method_cop').checked,
          descriptionShown: (document.querySelector('.payment_method_cop .payment_box')?.innerHTML || '').includes('<strong>cash</strong>'),
        };
      `);
      if (pick.error) { console.log(`SETUP FAIL ${c.name}: ${pick.error} (offered ${JSON.stringify(pick.methods)})`); fail++; continue; }

      await chrome.eval(`document.querySelector('#place_order').click(); return true;`);
      try {
        await chrome.waitFor(`location.pathname.includes('order-received')`, 45000);
      } catch (e) {
        const errors = await chrome.eval(`return [...document.querySelectorAll('.woocommerce-error li, .woocommerce-error')].map(n => n.innerText.trim()).slice(0, 5);`);
        console.log(`FAIL ${c.name}: order not placed. checkout errors: ${JSON.stringify(errors)}`);
        fail++; continue;
      }
      await sleep(1000);

      const confirmation = await chrome.eval(`
        return {
          hasInstructions: document.body.innerText.includes(${JSON.stringify(MARKER)}),
          orderId: (location.pathname.match(/order-received\\/(\\d+)/) || [])[1],
        };
      `);
      const orderId = parseInt(confirmation.orderId, 10);
      const server = JSON.parse(wpEval(`
        $o = wc_get_order( ${orderId} );
        echo wp_json_encode( array(
          "status" => $o->get_status(),
          "payment_method" => $o->get_payment_method(),
          "payment_title" => $o->get_payment_method_title(),
          "created_via" => $o->get_created_via(),
          "stock_reduced" => (bool) $o->get_data_store()->get_stock_reduced( $o->get_id() ),
          "date_paid" => $o->get_date_paid() ? $o->get_date_paid()->date( "c" ) : null,
          "has_cart_hash" => "" !== $o->get_cart_hash(),
          "shipping" => array_map( function( $m ) { return $m->get_method_id() . ":" . $m->get_instance_id(); }, array_values( $o->get_shipping_methods() ) ),
        ) );
      `));
      // Read the cart through the Store API rather than scraping a theme fragment: reads need
      // no nonce, and the answer does not depend on which mini cart markup the theme ships.
      const cartAfter = await chrome.eval(`
        const res = await fetch('/wp-json/wc/store/v1/cart', { credentials: 'same-origin' });
        const cart = await res.json();
        return { itemsCount: cart.items_count, itemsTotal: (cart.items || []).length };
      `);

      const errs = [...chrome.consoleErrors, ...chrome.pageErrors].filter((e) => !/favicon|404|net::ERR|Failed to load resource/i.test(e));
      const checks = {
        allFieldsPresent: Object.values(filled).every(Boolean),
        copSelected: pick.copChecked,
        descriptionShown: pick.descriptionShown,
        orderCreated: !!orderId,
        createdViaCheckout: server.created_via === 'checkout',
        orderHasCartHash: server.has_cart_hash,
        statusCorrect: server.status === c.status,
        paymentMethodCorrect: server.payment_method === 'cop',
        titleCorrect: server.payment_title === config.gatewaySettings.title,
        thankYouInstructions: confirmation.hasInstructions,
        stockReduced: server.stock_reduced,
        // Cash is only taken at handover, so an order must not be stamped paid until completed.
        datePaidCorrect: c.status === 'completed' ? !!server.date_paid : server.date_paid === null,
        cartEmptied: cartAfter.itemsCount === 0,
        noJsErrors: errs.length === 0,
      };
      const failed = Object.entries(checks).filter(([, v]) => !v).map(([k]) => k);
      failed.length ? fail++ : pass++;
      results.push({ case: c.name, orderId, checks, failed, errs, server, cartAfter });
      console.log(`${failed.length ? 'FAIL' : 'OK  '} ${c.name} -> order ${orderId} status=${server.status}${failed.length ? ' failed: ' + failed.join(', ') : ''}`);
    }
  } finally { await chrome.close(); }
  fs.writeFileSync(artifactPath('results-classic-orders.json'), JSON.stringify(results, null, 1));
  console.log(`\nCLASSIC ORDER PLACEMENT: ${pass} passed, ${fail} failed`);
  console.log('orders created: ' + results.map((r) => r.orderId).join(', '));
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error('RUNNER ERROR:', e.stack); process.exit(2); });
