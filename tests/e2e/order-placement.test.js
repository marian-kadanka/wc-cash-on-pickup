/**
 * Places real orders through the BLOCK checkout UI and verifies everything the gateway is
 * responsible for afterwards: order status, payment method and title, the instructions on the
 * order confirmation page and in the customer email, stock reduction and cart emptying.
 *
 * Requires tests/bin/setup-site.sh (which also allows guest checkout, so the tests do not
 * create user accounts).
 *
 *   node tests/e2e/order-placement.test.js
 */
const fs = require('fs');
const path = require('path');
const { Chrome, sleep } = require('../lib/cdp.js');
const { artifactPath, profilePath } = require('../lib/artifacts.js');
const { wpEval, setGatewaySettings } = require('../lib/wp.js');
const config = require('../config.js');

const BLOCK = config.baseUrl + config.blockCheckoutPath;
const MARKER = config.instructionsMarker;

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

(async () => {
  const cases = [];
  for (const status of ['on-hold', 'processing', 'completed']) {
    cases.push({ name: `physical+local pickup, status=${status}`, products: [config.physicalProductId], rate: config.rates.localPickup, status, exclusive: 'yes' });
    cases.push({ name: `virtual, status=${status}`, products: [config.virtualProductId], rate: null, status, exclusive: 'no' });
  }

  const chrome = await new Chrome({ profile: profilePath('orders'), port: 9335 }).launch();
  const results = [];
  let pass = 0, fail = 0;
  try {
    for (const c of cases) {
      chrome.clearErrors();
      setGatewaySettings({ default_order_status: c.status, exclusive_for_local: c.exclusive });
      await chrome.send('Network.clearBrowserCookies');
      await chrome.goto(`${config.baseUrl}/?add-to-cart=${c.products[0]}`);
      await chrome.goto(BLOCK);

      const { email, phone, ...addr } = config.address;
      const setup = await chrome.eval(`
        ${PAGE_API}
        await api('/cart/items', 'DELETE');
        for (const id of ${JSON.stringify(c.products)}) await api('/cart/add-item', 'POST', { id, quantity: 1 });
        const addr = ${JSON.stringify(addr)};
        await api('/cart/update-customer', 'POST', { shipping_address: addr, billing_address: { ...addr, email: ${JSON.stringify(email)}, phone: ${JSON.stringify(phone)} } });
        ${c.rate ? `await api('/cart/select-shipping-rate', 'POST', { package_id: 0, rate_id: ${JSON.stringify(c.rate)} });` : ''}
        const cart = (await api('/cart', 'GET')).body;
        return { paymentMethods: cart.payment_methods, itemsCount: cart.items_count };
      `);

      await chrome.goto(BLOCK);
      await chrome.waitFor(`document.querySelector('#radio-control-wc-payment-method-options-cop')`);
      await sleep(800);
      const pick = await chrome.eval(`
        document.querySelector('#radio-control-wc-payment-method-options-cop').click();
        const terms = document.querySelector('#terms-and-conditions');
        if (terms && !terms.checked) terms.click();
        await new Promise(r => setTimeout(r, 500));
        return {
          copChecked: document.querySelector('#radio-control-wc-payment-method-options-cop').checked,
          descriptionShown: (document.querySelector('.wc-block-components-radio-control-accordion-content')?.innerHTML || '').includes('<strong>cash</strong>'),
        };
      `);
      await chrome.eval(`document.querySelector('.wc-block-components-checkout-place-order-button').click(); return true;`);
      await chrome.waitFor(`location.pathname.includes('order-received')`, 40000);
      await sleep(1200);

      const confirmation = await chrome.eval(`
        return {
          hasInstructions: document.body.innerText.includes(${JSON.stringify(MARKER)}),
          orderId: (location.pathname.match(/order-received\\/(\\d+)/) || [])[1],
        };
      `);
      const orderId = parseInt(confirmation.orderId, 10);
      const server = JSON.parse(wpEval(`
        $o = wc_get_order( ${orderId} );
        $emails = WC()->mailer()->get_emails();
        $map = array( "on-hold" => "WC_Email_Customer_On_Hold_Order", "processing" => "WC_Email_Customer_Processing_Order", "completed" => "WC_Email_Customer_Completed_Order" );
        $email = $emails[ $map["${c.status}"] ];
        $email->object = $o; $email->recipient = ${JSON.stringify(email)};
        ob_start(); $body = $email->get_content_html(); ob_end_clean();
        echo wp_json_encode( array(
          "status" => $o->get_status(),
          "payment_method" => $o->get_payment_method(),
          "payment_title" => $o->get_payment_method_title(),
          "total" => $o->get_total(),
          "stock_reduced" => (bool) $o->get_data_store()->get_stock_reduced( $o->get_id() ),
          "date_paid" => $o->get_date_paid() ? $o->get_date_paid()->date( "c" ) : null,
          "shipping" => array_map( function( $m ) { return $m->get_method_id() . ":" . $m->get_instance_id(); }, array_values( $o->get_shipping_methods() ) ),
          "email_has_instructions" => strpos( $body, ${JSON.stringify(MARKER)} ) !== false,
        ) );
      `));
      const cartAfter = await chrome.eval(`
        const c = await (await fetch('/wp-json/wc/store/v1/cart', { credentials: 'same-origin' })).json();
        return { itemsCount: c.items_count };
      `);

      const errs = [...chrome.consoleErrors, ...chrome.pageErrors].filter((e) => !/favicon|404|net::ERR|Failed to load resource/i.test(e));
      const checks = {
        copOffered: (setup.paymentMethods || []).includes('cop'),
        exclusiveHonoured: c.exclusive !== 'yes' || JSON.stringify(setup.paymentMethods) === JSON.stringify(['cop']),
        copSelected: pick.copChecked,
        descriptionShown: pick.descriptionShown,
        orderCreated: !!orderId,
        statusCorrect: server.status === c.status,
        paymentMethodCorrect: server.payment_method === 'cop',
        titleCorrect: server.payment_title === config.gatewaySettings.title,
        thankYouInstructions: confirmation.hasInstructions,
        emailInstructions: server.email_has_instructions,
        stockReduced: server.stock_reduced,
        // Cash is only taken at handover, so an order must not be stamped paid until completed.
        datePaidCorrect: c.status === 'completed' ? !!server.date_paid : server.date_paid === null,
        cartEmptied: cartAfter.itemsCount === 0,
        noJsErrors: errs.length === 0,
      };
      const failed = Object.entries(checks).filter(([, v]) => !v).map(([k]) => k);
      failed.length ? fail++ : pass++;
      results.push({ case: c.name, orderId, checks, failed, errs, server });
      console.log(`${failed.length ? 'FAIL' : 'OK  '} ${c.name} -> order ${orderId} status=${server.status}${failed.length ? ' failed: ' + failed.join(', ') : ''}`);
    }
  } finally { await chrome.close(); }
  fs.writeFileSync(artifactPath('results-orders.json'), JSON.stringify(results, null, 1));
  console.log(`\nORDER PLACEMENT: ${pass} passed, ${fail} failed`);
  console.log('orders created: ' + results.map((r) => r.orderId).join(', '));
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error('RUNNER ERROR:', e.stack); process.exit(2); });
