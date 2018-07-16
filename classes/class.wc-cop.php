<?php
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
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if( ! class_exists( 'WC_Gateway_Cash_on_pickup' ) ):

/**
 * Main plugin class
 *
 * Provides a Cash on Pickup Payment Gateway.
 *
 * @class 		WC_Gateway_Cash_on_pickup
 * @extends		WC_Payment_Gateway
 */
class WC_Gateway_Cash_on_pickup extends WC_Payment_Gateway {

	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {
		load_plugin_textdomain( 'wc-cash-on-pickup', false, dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/i18n/' );

		// Setup general properties
		$this->setup_properties();

		// Load the settings
		$this->init_form_fields();
		$this->init_settings();

		// Get settings
		$this->enabled              = $this->get_option( 'enabled' );
		$this->title                = $this->get_option( 'title' );
		$this->description          = $this->get_option( 'description' );
		$this->instructions         = $this->get_option( 'instructions' );
		$this->enable_for_methods   = $this->get_option( 'enable_for_methods', array() );
		$this->default_order_status = $this->get_option( 'default_order_status', apply_filters( 'wc_cop_default_order_status', 'on-hold') );
		$this->exclusive_for_local  = $this->get_option( 'exclusive_for_local' );
		$this->enable_for_virtual   = $this->get_option( 'enable_for_virtual', 'yes' ) === 'yes' ? true : false;

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );

		// Customer Emails
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );

		if ( ! is_admin() ) {

			// Disable other payment methods if local pickup shippings
			if ( 'yes' === $this->enabled && 'yes' === $this->exclusive_for_local ) {
				add_filter( 'woocommerce_available_payment_gateways', array( $this, 'maybe_cop_only_if_local_pickup_shipping' ) );
			}

		}
	}

	/**
	 * Get part of a string before :.
	 *
	 * Used for example in shipping methods ids where they take the format
	 * method_id:instance_id
	 *
	 * @param  string $string
	 * @return string
	 */
	private function get_string_before_colon( $string ) {
		return trim( current( explode( ':', (string) $string ) ) );
	}

	/**
	 * Check if every of the shipping methods is local pickup
	 *
	 * @param array $shipping_methods Shipping methods to check.
	 * @return bool
	 */
	private function only_local_pickups_selected( $shipping_methods ) {

		// Local Pickup Plus fix
		unset( $shipping_methods["undefined"] );

		foreach( $shipping_methods as $shipping_method )  {
			if ( strpos( $shipping_method, 'local_pickup' ) === false ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * COP will be the only payment method available if each of the shipping methods chosen is local pickup only
	 *
	 * @param array $gateways Payment methods to filter.
	 * @return array of filtered methods
	 */
	public function maybe_cop_only_if_local_pickup_shipping( $gateways ) {
		if ( WC()->session ) {
			$chosen_shipping_methods_session = WC()->session->get( 'chosen_shipping_methods' ); 
			if ( $chosen_shipping_methods_session && $this->only_local_pickups_selected( $chosen_shipping_methods_session ) ) {
				if ( isset( $gateways['cop'] ) ) {
					return array( 'cop' => $gateways['cop'] );
				}
				else {
					return array();
				}
			}
		}

		return $gateways;
	}

	/**
	 * Setup general properties for the gateway.
	 */
	protected function setup_properties() {
		$this->id                 = 'cop';
		$this->icon               = apply_filters( 'woocommerce_cop_icon', '' );
		$this->method_title       = __( 'Cash on pickup', 'wc-cash-on-pickup' );
		$this->method_description = __( 'Have your customers pay with cash (or by other means) on pickup.', 'wc-cash-on-pickup' );
		$this->has_fields         = false;
	}

	/**
	 * Initialise Gateway Settings Form Fields.
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
				'title'       => __( 'Enable/Disable', 'woocommerce' ),
				'label'       => __( 'Enable cash on pickup', 'wc-cash-on-pickup' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
			'title' => array(
				'title'       => __( 'Title', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Payment method description that the customer will see on your checkout.', 'woocommerce' ),
				'default'     => __( 'Cash on pickup', 'wc-cash-on-pickup' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description that the customer will see on your website.', 'woocommerce' ),
				'default'     => __( 'Pay with cash on pickup.', 'wc-cash-on-pickup' ),
				'desc_tip'    => true,
			),
			'instructions' => array(
				'title'       => __( 'Instructions', 'woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Instructions that will be added to the thank you page.', 'woocommerce' ),
				'default'     => __( 'Pay with cash on pickup.', 'wc-cash-on-pickup' ),
				'desc_tip'    => true,
			),
			'enable_for_methods' => array(
				'title'       => __( 'Enable for shipping methods', 'woocommerce' ),
				'type'        => 'multiselect',
				'class'       => 'chosen_select',
				'css'         => 'width: 450px;',
				'default'     => '',
				'description' => __( 'If COP is only available for certain methods, set it up here. Leave blank to enable for all methods.', 'wc-cash-on-pickup' ),
				'options'     => $shipping_methods,
				'desc_tip'    => true,
			),
			'default_order_status' => array(
				'title'       => __( 'Default order status', 'wc-cash-on-pickup' ),
				'type'        => 'select',
				'default'     => apply_filters( 'wc_cop_default_order_status', 'on-hold' ),
				'options'     => $order_statuses,
			),
			'exclusive_for_local' => array(
				'title'       => __( 'Disable other payment methods if local pickup', 'wc-cash-on-pickup' ),
				'label'       => __( 'Cash on pickup will be the only payment method available if local pickup is selected on checkout', 'wc-cash-on-pickup' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
			'enable_for_virtual' => array(
				'title'       => __( 'Accept for virtual orders', 'woocommerce' ),
				'label'       => __( 'Accept COP if the order is virtual', 'wc-cash-on-pickup' ),
				'type'        => 'checkbox',
				'default'     => 'yes',
			),
		);
	}

	/**
	 * Check If The Gateway Is Available For Use.
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
					if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
						$_product = $order->get_product_from_item( $item );
					} else {
						$_product = $item->get_product();
					}
					if ( $_product && $_product->needs_shipping() ) {
						$needs_shipping = true;
						break;
					}
				}
			}
		}

		$needs_shipping = apply_filters( 'woocommerce_cart_needs_shipping', $needs_shipping );

		// Virtual order, with virtual disabled
		if ( ! $this->enable_for_virtual && ! $needs_shipping ) {
			return false;
		}

		// Only apply if all packages are being shipped via chosen method, or order is virtual.
		if ( ! empty( $this->enable_for_methods ) && $needs_shipping ) {
			$chosen_shipping_methods = array();

			if ( is_object( $order ) ) {
				$chosen_shipping_methods = array_unique( array_map( array( $this, 'get_string_before_colon' ), $order->get_shipping_methods() ) );
			} elseif ( $chosen_shipping_methods_session = WC()->session->get( 'chosen_shipping_methods' ) ) {
				$chosen_shipping_methods = array_unique( array_map( array( $this, 'get_string_before_colon' ), $chosen_shipping_methods_session ) );
			}

			// Local Pickup Plus fix
			unset( $chosen_shipping_methods["undefined"] );

			if ( 0 < count( array_diff( $chosen_shipping_methods, $this->enable_for_methods ) ) && ! ( 'yes' === $this->exclusive_for_local && $this->only_local_pickups_selected( $chosen_shipping_methods ) ) ) {
				return false;
			}
		}

		return parent::is_available();
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		$order->update_status( apply_filters( 'wc_cop_default_order_status', $this->default_order_status ) );

		// Reduce stock levels
		if ( version_compare( WC_VERSION, '3.0', '>=' ) ) {
			wc_reduce_stock_levels( $order_id );
		} else {
			$order->reduce_order_stock();
		}

		// Remove cart
		WC()->cart->empty_cart();

		// Return thankyou redirect
		return array(
			'result' 	=> 'success',
			'redirect'	=> $this->get_return_url( $order ),
		);
	}

	/**
	 * Output for the order received page.
	 */
	public function thankyou_page() {
		if ( $this->instructions ) {
			echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) );
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
		$payment_method = version_compare( WC_VERSION, '3.0', '>=' ) ? $order->get_payment_method() : $order->payment_method;
		if ( $this->instructions && ! $sent_to_admin && $this->id === $payment_method && $order->has_status( $this->default_order_status ) ) {
			echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) . PHP_EOL );
		}
	}
}
endif;
