<?php
/*
Plugin Name:       WooCommerce Cash On Pickup
Plugin URI:        https://wordpress.org/plugins/wc-cash-on-pickup/
Description:       A WooCommerce Extension that adds the payment gateway "Cash On Pickup"
Version:           1.1.2
Author:            Pinch Of Code
Author URI:        http://pinchofcode.com
Textdomain:        wc_cop
Domain Path:       /i18n
License:           GPL-2.0+
License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
GitHub Plugin URI: hhttps://github.com/PinchOfCode/wc-cash-on-pickup
*/

/**
 * WooCommerce Cash On Pickup
 * Copyright (C) 2013-2014 Pinch Of Code. All rights reserved.
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
 *
 * Contact the author at info@pinchofcode.com
 */

/**
 * Start the plugin
 */
function wc_cop_init() {
    global $woocommerce;

    if( !isset( $woocommerce ) ) { return; }

    require_once( 'classes/class.wc-cop.php' );
}
add_action( 'plugins_loaded', 'wc_cop_init' );

/**
 * Add COP in WooCommerce payment gateways
 * @param $methods
 * @return array
 */
function add_cash_on_pickup( $methods ) {
    $methods[] = 'WC_Gateway_Cash_on_pickup';
    return $methods;
}
add_filter( 'woocommerce_payment_gateways', 'add_cash_on_pickup' );

/**
 * Add "Donate" link in plugins list page
 *
 * @param $links
 * @param $file
 * @return mixed
 */
function wc_cop_add_donate_link( $links, $file ) {
    if( $file == plugin_basename( __FILE__ ) ) {
        //Settings link
        array_unshift( $links, '<a href="' . site_url() . '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=wc_gateway_cash_on_pickup" title="' . __( 'Settings', 'wc_cop' ) . '">' . __( 'Settings', 'wc_cop' ) . '</a>' );
        //Donate link
        array_unshift( $links, '<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_xclick&business=paypal@pinchofcode.com&item_name=Donation+for+Pinch+Of+Code" title="' . __( 'Donate', 'wc_pgec' ) . '" target="_blank">' . __( 'Donate', 'wc_cop' ) . '</a>' );
    }

    return $links;
}
add_filter( 'plugin_action_links', 'wc_cop_add_donate_link', 10, 4 );
