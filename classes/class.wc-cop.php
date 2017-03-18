<?php
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

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if( !class_exists( 'WC_Gateway_Cash_on_pickup' ) ):

/**
 * Main plugin class
 *
 * @usedby WC_Payment_Gateway
 */
class WC_Gateway_Cash_on_pickup extends WC_Payment_Gateway {

    /**
     * Init languages files and gateway settigns
     */
    public function __construct() {
        load_plugin_textdomain( 'wc_cop', false, dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/i18n/' );

        $this->id                = 'cop';
        $this->icon              = apply_filters('woocommerce_cop_icon', '');
        $this->has_fields        = false;
        $this->method_title      = __( 'Cash on pickup', 'wc_cop' );
        $this->order_button_text = apply_filters( 'woocommerce_cop_order_button_text', __( 'Place order', 'wc_cop' ) );

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        // Get settings
        $this->title              = $this->get_option( 'title' );
        $this->description        = $this->get_option( 'description' );
        $this->instructions       = $this->get_option( 'instructions' );
        $this->enable_for_methods = $this->get_option( 'enable_for_methods', array() );
        $this->default_order_status = $this->get_option( 'default_order_status', apply_filters( 'wc_cop_default_order_status', 'on-hold') );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_thankyou_cop', array( $this, 'thankyou_page' ) );

        // Customer Emails
        add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
    }

    /**
     * Admin Panel Options
     * - Options for bits like 'title' and availability on a country-by-country basis
     *
     * @access public
     * @return void
     */
    function admin_options() {
        ?>
        <h3><?php _e('Cash on Pickup','wc_cop'); ?></h3>
        <p><?php _e('Have your customers pay with cash (or by other means) on pickup.', 'wc_cop' ); ?></p>
        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>
    <?php
    }

    /**
     * Create form fields for the payment gateway
     *
     * @return void
     */
    public function init_form_fields() {
        $shipping_methods = array();
        $order_statuses = array();

        if ( is_admin() ) {
            foreach ( WC()->shipping->load_shipping_methods() as $method ) {
                $shipping_methods[ $method->id ] = $method->get_method_title();
            }

            $statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
            foreach ( $statuses as $status => $status_name ) {
                $order_statuses[ substr( $status, 3 ) ] = $status_name;
            }
        }

        $this->form_fields = array(
            'enabled' => array(
                'title' => __( 'Enable/Disable', 'wc_cop' ),
                'type' => 'checkbox',
                'label' => __( 'Enable Cash On Pickup', 'wc_cop' ),
                'default' => 'yes'
            ),
            'title' => array(
                'title' => __( 'Title', 'wc_cop' ),
                'type' => 'text',
                'description' => __( 'This controls the title which the user sees during checkout', 'wc_cop' ),
                'default' => __( 'Cash on pickup', 'wc_cop' ),
                'desc_tip'      => true,
            ),
            'description' => array(
                'title' => __( 'Customer Message', 'wc_cop' ),
                'type' => 'textarea',
                'default' => __( 'Pay your order in cash as you pick it up at our store.', 'wc_cop' )
            ),
            'instructions' => array(
                'title' => __( 'Instructions', 'wc_cop' ),
                'type' => 'textarea',
                'description' => __( 'Instructions that will be added to the thank you page.', 'wc_cop' ),
                'default' => __( 'Pay with cash on pickup at [Store address].', 'wc_cop' )
            ),
            'enable_for_methods' => array(
                'title'         => __( 'Enable for shipping methods', 'wc_cop' ),
                'type'          => 'multiselect',
                'class'         => 'chosen_select',
                'css'           => 'width: 450px;',
                'default'       => '',
                'description'   => __( 'If COP is only available for certain methods, set it up here. Leave blank to enable for all methods.', 'wc_cop' ),
                'options'       => $shipping_methods,
                'desc_tip'      => true,
            ),
            'default_order_status' => array(
                'title'         => __( 'Default Order Status', 'wc_cop' ),
                'type'          => 'select',
                'default'       => apply_filters( 'wc_cop_default_order_status', 'on-hold' ),
                'options'       => $order_statuses,
            )
        );
    }

    /**
     * Check If The Gateway Is Available For Use
     *
     * @return bool
     */
    public function is_available() {
        $order          = null;
        $needs_shipping = false;

        // Test if shipping is needed first
        if ( WC()->cart && WC()->cart->needs_shipping() ) {
            $needs_shipping = true;
        } elseif ( is_page( wc_get_page_id( 'checkout' ) ) && 0 < get_query_var( 'order-pay' ) ) {
            $order_id = absint( get_query_var( 'order-pay' ) );
            $order    = wc_get_order( $order_id );

            // Test if order needs shipping.
            if ( 0 < sizeof( $order->get_items() ) ) {
                foreach ( $order->get_items() as $item ) {
                    $_product = $order->get_product_from_item( $item );
                    if ( $_product && $_product->needs_shipping() ) {
                        $needs_shipping = true;
                        break;
                    }
                }
            }
        }

        $needs_shipping = apply_filters( 'woocommerce_cart_needs_shipping', $needs_shipping );

        // Virtual order
        if ( ! $needs_shipping ) {
            return false;
        }

        // Check methods
        if ( ! empty( $this->enable_for_methods ) && $needs_shipping ) {

            // Only apply if all packages are being shipped via chosen methods, or order is virtual
            $chosen_shipping_methods_session = WC()->session->get( 'chosen_shipping_methods' );

            if ( isset( $chosen_shipping_methods_session ) ) {
                $chosen_shipping_methods = array_unique( $chosen_shipping_methods_session );
            } else {
                $chosen_shipping_methods = array();
            }

            $check_method = false;

            if ( is_object( $order ) ) {
                if ( $order->shipping_method ) {
                    $check_method = $order->shipping_method;
                }

            } elseif ( empty( $chosen_shipping_methods ) || sizeof( $chosen_shipping_methods ) > 1 ) {
                $check_method = false;
            } elseif ( sizeof( $chosen_shipping_methods ) == 1 ) {
                $check_method = $chosen_shipping_methods[0];
            }

            if ( ! $check_method ) {
                return false;
            }

            $found = false;

            foreach ( $this->enable_for_methods as $method_id ) {
                if ( strpos( $check_method, $method_id ) === 0 ) {
                    $found = true;
                    break;
                }
            }

            if ( ! $found ) {
                return false;
            }
        }

        return parent::is_available();
    }

    /**
     * Process the order payment status
     *
     * @param int $order_id
     * @return array
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        $order->update_status( apply_filters( 'wc_cop_default_order_status', $this->default_order_status ) );

        // Reduce stock levels
        $order->reduce_order_stock();

        // Remove cart
        WC()->cart->empty_cart();

        // Return thankyou redirect
        return array(
            'result'    => 'success',
            'redirect'  => $this->get_return_url( $order )
        );
    }

    /**
     * Output for the order received page.
     */
    public function thankyou_page() {
        if ( $this->instructions ) {
            echo wpautop( wptexturize( wp_kses_post( $this->instructions ) ) );
        }
    }

    /**
     * Add content to the WC emails.
     *
     * @access public
     * @param WC_Order $order
     * @param bool $sent_to_admin
     * @param bool $plain_text
     */
    public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
        if ( $this->instructions && ! $sent_to_admin && 'cop' === $order->payment_method && $order->has_status( $this->default_order_status ) ) {
            echo wpautop( wptexturize( $this->instructions ) ) . PHP_EOL;
        }
    }
}
endif;
