<?php
/*
Plugin Name:       Cash On Pickup for WooCommerce
Plugin URI:        https://wordpress.org/plugins/wc-cash-on-pickup/
Description:       A WooCommerce Extension that adds the payment gateway "Cash On Pickup". Supports both the classic and the block based checkout.
Version:           2.0.0
Author:            Marian Kadanka
Author URI:        https://kadanka.net/
Text Domain:       wc-cash-on-pickup
Domain Path:       /languages
License:           GPL-2.0+
License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
GitHub Plugin URI: https://github.com/marian-kadanka/wc-cash-on-pickup
Requires at least: 4.4
Requires PHP:      7.4
WC requires at least: 3.4
WC tested up to:   11.1
*/

/**
 * Cash On Pickup for WooCommerce
 * Copyright (C) 2013-2014 Pinch Of Code. All rights reserved.
 * Copyright (C) 2017-2026 Marian Kadanka. All rights reserved.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.

 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.

 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'WC_COP_VERSION', '2.0.0' );
define( 'WC_COP_PLUGIN_FILE', __FILE__ );

/**
 * Start the plugin
 */
function wc_cop_init() {

	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	require_once( 'classes/class.wc-cop.php' );
}
add_action( 'plugins_loaded', 'wc_cop_init' );

/**
 * Load the plugin translations.
 */
function wc_cop_load_textdomain() {
	load_plugin_textdomain( 'wc-cash-on-pickup', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}
add_action( 'init', 'wc_cop_load_textdomain' );

/**
 * Add COP in WooCommerce payment gateways
 * @param $methods
 * @return array
 */
function wc_cop_register_gateway( $methods ) {
	$methods[] = 'WC_Gateway_Cash_on_pickup';
	return $methods;
}
add_filter( 'woocommerce_payment_gateways', 'wc_cop_register_gateway' );

/**
 * Show action links on the plugin screen.
 *
 * @param $links
 * @param $file
 * @return mixed
 */
function wc_cop_action_links( $links, $file ) {
	if ( $file == plugin_basename( __FILE__ ) ) {
		//Donate link
		array_unshift( $links, '<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_xclick&business=marian.kadanka@gmail.com&item_name=Donation+for+Marian+Kadanka" title="' . esc_attr__( 'Donate', 'wc-cash-on-pickup' ) . '" target="_blank">' . esc_html__( 'Donate', 'wc-cash-on-pickup' ) . '</a>' );
		//Settings link
		array_unshift( $links, '<a href="' . network_admin_url( 'admin.php?page=wc-settings&tab=checkout&section=cop' ) . '" title="' . esc_attr__( 'Settings', 'woocommerce' ) . '">' . esc_html__( 'Settings', 'woocommerce' ) . '</a>' );
	}

	return $links;
}
add_filter( 'plugin_action_links', 'wc_cop_action_links', 10, 4 );

/**
 * Register the gateway with the Cart and Checkout blocks.
 *
 * The block checkout renders payment methods client side, so on top of the gateway itself it
 * needs a payment method type that hands its settings and script over to the blocks registry.
 *
 * @param Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry
 */
function wc_cop_register_block_support( $payment_method_registry ) {
	if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}

	require_once plugin_dir_path( __FILE__ ) . 'classes/class.wc-cop-blocks.php';

	$payment_method_registry->register( new WC_Gateway_Cash_on_pickup_Blocks_Support() );
}
add_action( 'woocommerce_blocks_payment_method_type_registration', 'wc_cop_register_block_support' );

/**
 * Declare WooCommerce HPOS and Cart/Checkout blocks compatibility.
 */
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );