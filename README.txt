=== Cash On Pickup for WooCommerce ===
Contributors: mariankadanka
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_xclick&business=marian.kadanka@gmail.com&item_name=Donation+for+Marian+Kadanka
Tags: woocommerce, cash, pickup, payment, gateway
Requires at least: 4.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Have your customers pay with cash on pickup

== Description ==

Accept "cash on pickup" payment method on your WooCommerce store. Works with both the classic and the block based (Checkout block) checkout.

Features:

* customizable instructions are printed on the checkout and "thank you" page, and added to the email sent to the customer
* ability to make cash on pickup the only payment method available when the customer chooses local pickup at checkout
* it's possible to make the cash on pickup payment available only for some of the shipping methods
* option to select the status of new orders that are paid on pickup
* option to accept cash on pickup payment if the order is virtual
* Local Pickup Plus compatible
* WooCommerce Blocks (Cart and Checkout blocks) support, including the block checkout's own "Pickup" (local pickup) option
* High Performance Order Storage (HPOS) compatible
* WPML support

= Block based checkout =

All of the settings above apply to the block based checkout as well. One caveat: the setting
"Disable other payment methods for local pickup" hides the regular payment methods, but it cannot
hide express payment buttons (Apple Pay, Google Pay and similar), because those are rendered by
the browser before the store is asked which payment methods are available.

== Installation ==

1. Go to Plugins > Add New > Search
2. Type "Cash On Pickup for WooCommerce" in the search box and hit Enter
3. Click on the button Install and then activate the plugin

= Manual Installation =

The manual installation method involves downloading our plugin and uploading it to your webserver via your favourite FTP application. The WordPress codex contains [instructions on how to do this here](http://codex.wordpress.org/Managing_Plugins#Manual_Plugin_Installation).

== Screenshots ==

1. Cash on Pickup settings page

== Upgrade Notice ==

= 2.0.0 =
Adds block checkout support. On a block checkout, "Disable other payment methods for local
pickup" used to leave no payment methods at all - fixed. Email instructions no longer follow the
"default order status" setting. Orders now count as paid when completed, not when processing.

== Changelog ==

= 2.0.0 =
* Add: support for the block based checkout (Cart and Checkout blocks). Title, description, instructions, the shipping method restriction, the virtual order setting, the default order status and the "local pickup only" exclusivity all work there
* Add: the block checkout's own local pickup method ("Pickup") is now recognised as local pickup
* Fix: with "Disable other payment methods for local pickup" enabled, a block based checkout was left with no payment methods at all when local pickup was chosen, so the order could not be placed
* Fix: the "Enable for shipping methods" setting no longer loses the classic Local Pickup entries on stores using the block checkout, where two shipping methods share the title "Local pickup"
* Fix: availability is now read from the calculated cart, which makes the shipping method restriction reliable during Store API requests
* Fix: only empty the cart the order was created from when the payment is processed, so paying for an existing order no longer discards an unrelated cart
* Fix: the instructions in the customer email no longer depend on the "default order status" setting. Changing that setting used to silently remove them from emails for every order already placed under the old value; they are now withheld only from orders that will never be collected (cancelled, refunded, failed)
* Fix: an order is no longer recorded as paid the moment it reaches "processing". Cash on pickup is only collected at handover, so the payment date is now stamped when the order is marked completed. The order screen no longer shows a "Paid" total on an order nobody has collected, and Analytics no longer books the revenue on the order date. This mirrors WooCommerce's own Cash on delivery gateway
* Add: new filters wc_cop_local_pickup_methods, wc_cop_blocks_description_allowed_tags and wc_cop_email_instructions_skip_order_statuses
* Performance: the shipping method list for the "Enable for shipping methods" setting is now only built on the gateway's own settings screen instead of on every admin page load (55 database queries saved per request on a small store)
* Tweak: the order note now records that payment is to be made upon pickup, like WooCommerce's own offline gateways
* Tweak: the title setting is sanitized on save, and the shipping method selector uses WooCommerce's current enhanced select styling with a placeholder
* Tweak: orders being paid through the "order pay" page are now detected by endpoint, which also works when that endpoint is not on the configured checkout page
* Tested up to WordPress version 7.1, WooCommerce version 11.1, PHP 8.4

= 1.7.1 =
* Tweak: Change plugin name due to trademark violation
* Tested up to WordPress version 6.8, WooCommerce version 10.3, PHP 8.4

= 1.7.0 =
* Add: HPOS support
* Tested up to WordPress version 6.3, WooCommerce version 8.1, PHP 8.2

= 1.6.1 =
* Fix: don't disable other payment methods if Cash On Pickup itself isn't available
* Tested up to WordPress version 5.9, WooCommerce version 6.3

= 1.6 =
* Add: support for shipping zones introduced in WooCommerce 3.4, props Peter Morvay
* Tested up to WordPress version 5.5
* Bump 'WC tested up to' version

= 1.5 =
* Add: option to accept Cash on pickup payment if the order is virtual

= 1.4.4 =
* Fix: Add and update WooCommerce < 3.0 backward compatibility

= 1.4.3 =
* Fix: https://wordpress.org/support/topic/warnings-on-my-account-pages/
* Bump 'WC tested up to' version

= 1.4.2 =
* Fix: Menus editor not showing, due to woocommerce_available_payment_gateways filter hook firing in the admin

= 1.4.1 =
* Fix: Rename the text domain to comply with the language packs requirements

= 1.4 =
* Add: option to disable other payment methods if local pickup "shipping" is selected on the checkout page

= 1.3.1 =
* Fix: wpml-config.xml fix

= 1.3 =
* Fix: gateway not available if shipping is disabled in WooCommerce general settings
* Tested up to WordPress version 4.9

= 1.2 =
* Plugin maintainer changed
* Add: option to choose default status of new orders added
* Add: instructions are now added to the email send to a customer
* Fix: broken admin input field "Enable for shipping methods"
* Fix: WooCommerce 3.0 compatibility
* Fix: code and indentation cleanup, more code imported from WooCommerce COD gateway

= 1.1.2 =
* Add: WPML support

= 1.1.1 =
* Fix: Error that prevent the payment method to show on checkout page

= 1.1 =
* Add: Added "Settings" link in plugins list page
* Add: filter wc_cop_default_order_status
* Add: "Place Order" button text and filter woocommerce_cop_order_button_text
* Fix: Compatible with WooCommerce 2.1+
* Fix: minor changes

= 1.0 =
* First release
