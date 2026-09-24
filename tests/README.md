# Tests

Tests for Cash On Pickup for WooCommerce, covering both the classic and the block based
checkout. They drive a real development store: WP-CLI changes the gateway settings, headless
Chrome plays the customer, and the assertions are made against what is actually rendered.

Nothing here ships with the plugin (see `.distignore`).

## Requirements

- Node 22+ (uses the built-in `WebSocket`, no npm packages at all)
- `google-chrome`
- `wp` (WP-CLI) able to reach the site
- a WooCommerce store reachable over HTTP, with the fixtures listed under *Configuration*

## Running

```sh
tests/bin/run-all.sh          # unit tests, then the full end-to-end suite
tests/bin/run-all.sh unit     # unit tests only - touches no site state
```

Individual files:

```sh
node tests/unit/blocks-js.test.js                  # no site needed
wp eval-file <plugin>/tests/unit/gateway-logic.php # from the WordPress root
node tests/e2e/classic-checkout.test.js            # needs the classic page as checkout
node tests/e2e/classic-order-placement.test.js     # places real orders, classic checkout
node tests/e2e/block-checkout.test.js              # needs the block page as checkout
node tests/e2e/order-placement.test.js             # places real orders, block checkout
```

`run-all.sh` takes care of pointing the store at the right checkout page for each phase.

## Nothing here is reachable over HTTP

The plugin lives in the web root, so this directory is protected several ways over:

- `.htaccess` denies everything (Apache). On nginx add:
  `location ~* /wp-content/plugins/wc-cash-on-pickup/tests/ { deny all; return 404; }`
- every PHP file here exits unless WordPress is already loaded, so requesting one directly does
  nothing even where `.htaccess` is ignored
- an `index.php` in each directory prevents directory listings
- **nothing sensitive is written here in the first place** - see below

`.gitattributes` and `.distignore` keep the whole directory out of GitHub source zips and of
release builds, so it normally never reaches a live site at all.

## Where runs write their files

Not into the plugin. Browser profiles contain session cookies, the site backup contains the
store's option values, and result files contain order ids and customer emails - none of that
belongs under a web root. Everything goes to `wc-cop-tests` in the system temp directory
(`lib/artifacts.js`, and `wc_cop_tests_backup_file()` on the PHP side). Point `artifactsDir` in
`config.json` somewhere else if you prefer.

## Site state

The end-to-end tests change store settings, so `bin/setup-site.php` first writes every value it
is about to touch to `site-backup.json` in that directory, and `bin/restore-site.php` puts them
all back and deletes the backup. It writes `site-state.json` alongside it with the checkout
pages it resolved, and restore deletes any page it had to create. `run-all.sh` restores on exit
even when a phase fails.

What it changes while running: the checkout page, the gateway's own settings, the Checkout
block's local pickup configuration, and guest checkout (enabled so that placing orders does not
create user accounts). If a run is interrupted, restore by hand (the backup path is printed when it is written):

```sh
wp eval-file <plugin>/tests/bin/restore-site.php
```

**Orders are real.** The order placement tests create one order per case and leave them in
place, because that is the evidence that the flow worked. Delete them when you are done.

## Configuration

`config.json` holds everything site specific. To point the suite at another store:

| Key | What it is | How to find it |
| --- | --- | --- |
| `baseUrl`, `wpPath` | store URL and WordPress root | — |
| `physicalProductId`, `virtualProductId` | one product that needs shipping, one that does not | `wp wc product list --user=1` |
| `address` | an address inside the zone the rates below belong to | — |
| `rates` | rate ids offered for that address | see snippet below |
| `gatewaySettings` | the baseline the matrices vary from | — |

### Checkout pages

Page ids and slugs are never configured. `bin/setup-site.php` discovers both checkout pages
from WooCommerce's own settings and writes what it found to `site-state.json` next to the
backup; `config.js` merges that in, so the tests just ask for `config.checkoutUrl('classic')`
or `config.checkoutUrl('block')`.

How each one is resolved, in order:

1. the page the store has configured (`woocommerce_checkout_page_id`), if its content matches
   the kind being looked for
2. a page a previous run created, marked with the `_wc_cop_test_page` meta
3. the oldest published page whose content carries `[woocommerce_checkout]` or the
   `wp:woocommerce/checkout` block
4. failing all of that, a new page - using WooCommerce's own default block markup - which
   `bin/restore-site.php` deletes again afterwards

Both kinds are needed because WooCommerce only registers its block-only `pickup_location`
shipping method when the Checkout block *is* the store's checkout page. `run-all.sh` therefore
points `woocommerce_checkout_page_id` at the classic page for the classic phases and at the
block page for the block phases, using the ids `setup-site.php` resolved.

Not deriving these from settings was a real bug: WooCommerce's "switch to block checkout"
swaps the two pages' slugs, which silently pointed the suite at the wrong URLs.

List the rate ids a zone offers:

```sh
wp eval 'foreach ( array( 0, 1, 2 ) as $id ) { $z = new WC_Shipping_Zone( $id );
  echo $z->get_zone_name() . ":\n";
  foreach ( $z->get_shipping_methods( true ) as $m ) { echo "  " . $m->get_rate_id() . "  " . $m->get_title() . "\n"; } }'
```

## What is covered

**`unit/blocks-js.test.js`** loads `assets/js/blocks/checkout.js` against stubbed WooCommerce
Blocks globals: what it registers, the label with and without a gateway icon, the sanitized
description (including the fallback when `wc.sanitize` is missing), and the whole
`canMakePayment` matrix - virtual carts, unrestricted carts, a rate id matching by method id or
exactly, a wrong instance of the right method, and `local_pickup` not matching
`local_pickup_plus`.

**`unit/gateway-logic.php`** checks the shipping rules through reflection: that the block
checkout's `pickup_location` counts as local pickup next to `local_pickup`,
`legacy_local_pickup` and Local Pickup Plus, and that "only local pickup chosen" is decided
correctly for single, mixed and empty selections. It then walks a real order through every
relevant status to pin down when the customer email carries the pickup instructions - shown for
pending, on-hold, processing and completed, withheld for cancelled, refunded and failed, and
never shown to the admin or for another gateway's orders.

**`e2e/block-checkout.test.js`** and **`e2e/classic-checkout.test.js`** run the same matrix on
both checkouts: every combination of `enable_for_methods` (unrestricted / a method id / one
specific rate instance / the block pickup method) x `exclusive_for_local` x
`enable_for_virtual`, plus the gateway switched off, against physical, mixed and virtual carts
with each shipping rate. Each case asserts that the gateway is shown exactly when it should be,
that "Disable other payment methods for local pickup" leaves COP alone on the page exactly when
it should, that the server's own gateway list agrees, and that the browser console stays clean.

The block matrix also re-checks, at the moment it reads the page, that the shipping rate it set
up is really the selected one, and redoes the setup if it is not - otherwise a case can silently
assert against a different situation than the one it meant to create.

**`e2e/order-placement.test.js`** places orders through the block checkout UI for each default
order status against a physical-with-pickup and a virtual cart, then verifies the order status,
payment method and title, the instructions on the order confirmation page and in the customer
email, stock reduction and that the cart was emptied.

**`e2e/classic-order-placement.test.js`** does the same through the classic checkout, filling the
billing form and submitting it. The two checkouts reach `process_payment()` by different routes,
and this is what proves the cart hash guard still empties the cart on a normal classic order.
