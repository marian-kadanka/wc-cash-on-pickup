=== WooCommerce Cash On Pickup ===
Contributors: mariankadanka
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_xclick&business=marian.kadanka@gmail.com&item_name=Donation+for+Marian+Kadanka
Tags: woocommerce, cash, pickup, cop, payment, gateway
Requires at least: 3.5
Tested up to: 4.9
Stable tag: 1.5
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Have your customers pay with cash on pickup

== Description ==

Accept "cash on pickup" payment method on your WooCommerce store.

Features:

* customizable instructions are printed on the checkout and "thank you" page, and added to the email sent to the customer
* ability to make the cash on pickup the only payment method available if the customer chooses the local pickup "shipping" on the checkout page
* it's possible to make the cash on pickup payment available only for some of the shipping methods
* option to select the status of new orders that are paid on pickup
* option to accept cash on pickup payment if the order is virtual
* Local Pickup Plus compatible
* WPML support

== Installation ==

1. Go to Plugins > Add New > Search
2. Type WooCommerce Cash On Pickup in the search box and hit Enter
3. Click on the button Install and then activate the plugin

= Manual Installation =

The manual installation method involves downloading our plugin and uploading it to your webserver via your favourite FTP application. The WordPress codex contains [instructions on how to do this here](http://codex.wordpress.org/Managing_Plugins#Manual_Plugin_Installation).

== Screenshots ==

1. Cash on Pickup settings page

== Changelog ==

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
