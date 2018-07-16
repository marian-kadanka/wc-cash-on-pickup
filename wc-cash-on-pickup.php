<?php
/*
Plugin Name:       WooCommerce Cash On Pickup
Plugin URI:        https://wordpress.org/plugins/wc-cash-on-pickup/
Description:       A WooCommerce Extension that adds the payment gateway "Cash On Pickup"
Version:           1.5
Author:            Marian Kadanka
Author URI:        https://github.com/marian-kadanka
Text Domain:       wc-cash-on-pickup
Domain Path:       /i18n
License:           GPL-2.0+
License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
GitHub Plugin URI: https://github.com/marian-kadanka/wc-cash-on-pickup
WC tested up to:   3.4
*/

/**
 * WooCommerce Cash On Pickup
 * Copyright (C) 2013-2014 Pinch Of Code. All rights reserved.
 * Copyright (C) 2017-2018 Marian Kadanka. All rights reserved.
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

/**
 * Start the plugin
 */
function wc_cop_init() {
	global $woocommerce;

	if ( !isset( $woocommerce ) ) {
		return;
	}

	require_once( 'classes/class.wc-cop.php' );
}
add_action( 'plugins_loaded', 'wc_cop_init' );

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
