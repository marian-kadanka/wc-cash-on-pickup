<?php
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
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( class_exists( 'WC_Gateway_Cash_on_pickup' ) ) {
	return;
}

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
	 * Gateway instructions that will be added to the thank you page and emails.
	 *
	 * @var string
	 */
	public $instructions;

	/**
	 * Enable for shipping methods.
	 *
	 * @var array
	 */
	public $enable_for_methods;

	/**
	 * Default order status.
	 *
	 * @var string
	 */
	public $default_order_status;

	/**
	 * Exclusive for local pickup.
	 *
	 * @var string
	 */
	public $exclusive_for_local;

	/**
	 * Enable for virtual orders.
	 *
	 * @var string
	 */
	public $enable_for_virtual;

	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {
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
		$this->enable_for_virtual   = $this->get_option( 'enable_for_virtual', 'yes' ) === 'yes';

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );

		// Customer Emails
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );

		// Cash only changes hands at pickup, so don't let WooCommerce record the order
		// as paid as soon as it reaches "processing". See change_payment_complete_order_status().
		add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'change_payment_complete_order_status' ), 10, 3 );

		if ( ! is_admin() ) {

			// Disable other payment methods for local pickup
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
	 * Shipping method ids that count as "local pickup".
	 *
	 * Uses WooCommerce's own list, which the Checkout block extends with its "pickup_location"
	 * method, so the Pickup tab of the block checkout is recognised as local pickup too.
	 *
	 * @return array
	 */
	private function get_local_pickup_method_ids() {
		$method_ids = apply_filters( 'woocommerce_local_pickup_methods', array( 'legacy_local_pickup', 'local_pickup' ) );

		/**
		 * Filter the shipping method ids treated as local pickup by this gateway.
		 *
		 * @since 2.0.0
		 *
		 * @param array $method_ids Shipping method ids.
		 */
		return (array) apply_filters( 'wc_cop_local_pickup_methods', $method_ids );
	}

	/**
	 * Check whether a chosen shipping rate id is a local pickup one.
	 *
	 * @param string $rate_id Rate id, either 'method_id' or 'method_id:instance_id'.
	 * @return bool
	 */
	private function is_local_pickup_method( $rate_id ) {
		if ( in_array( $this->get_string_before_colon( $rate_id ), $this->get_local_pickup_method_ids(), true ) ) {
			return true;
		}

		// Back compat: matches third party methods such as Local Pickup Plus.
		return strpos( (string) $rate_id, 'local_pickup' ) !== false;
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

		if ( empty( $shipping_methods ) ) {
			return false;
		}

		foreach( $shipping_methods as $shipping_method )  {
			if ( ! $this->is_local_pickup_method( $shipping_method ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get the rate ids of the shipping methods the customer has chosen.
	 *
	 * Reads the calculated cart first - that is what the Store API (block checkout) works with -
	 * and falls back to the session, which is what the classic checkout keeps up to date.
	 *
	 * @return array Rate ids, e.g. array( 'local_pickup:3' ).
	 */
	private function get_chosen_shipping_rate_ids() {
		$rate_ids = array();

		if ( WC()->cart && is_callable( array( WC()->cart, 'get_shipping_methods' ) ) ) {
			foreach ( (array) WC()->cart->get_shipping_methods() as $rate ) {
				if ( is_object( $rate ) && is_callable( array( $rate, 'get_id' ) ) ) {
					$rate_ids[] = $rate->get_id();
				}
			}
		}

		if ( empty( $rate_ids ) && WC()->session ) {
			$rate_ids = (array) WC()->session->get( 'chosen_shipping_methods' );
		}

		// Local Pickup Plus fix
		unset( $rate_ids["undefined"] );

		return array_filter( array_map( 'strval', (array) $rate_ids ) );
	}

	/**
	 * COP will be the only payment method available when every shipping method chosen is a local pickup method
	 *
	 * @param array $gateways Payment methods to filter.
	 * @return array of filtered methods
	 */
	public function maybe_cop_only_if_local_pickup_shipping( $gateways ) {
		if ( ! isset( $gateways[ $this->id ] ) ) {
			return $gateways;
		}

		if ( WC()->session && $this->is_available() ) {
			$chosen_shipping_methods = $this->get_chosen_shipping_rate_ids();
			if ( $chosen_shipping_methods && $this->only_local_pickups_selected( $chosen_shipping_methods ) ) {
				return array( $this->id => $gateways[ $this->id ] );
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
	 * Checks whether the current request is the gateway's own settings screen.
	 *
	 * Building the shipping method list walks every shipping zone and instantiates every shipping
	 * method, which costs dozens of queries, so it is only worth doing where the list is actually
	 * shown. Mirrors what WooCommerce core does for its own Cash on Delivery gateway.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	private function is_accessing_settings() {
		if ( is_admin() ) {
			// phpcs:disable WordPress.Security.NonceVerification
			if ( ! function_exists( 'is_wc_admin_settings_page' ) || ! is_wc_admin_settings_page() ) {
				return false;
			}
			if ( ! isset( $_REQUEST['tab'] ) || 'checkout' !== $_REQUEST['tab'] ) {
				return false;
			}
			if ( ! isset( $_REQUEST['section'] ) || $this->id !== $_REQUEST['section'] ) {
				return false;
			}
			// phpcs:enable WordPress.Security.NonceVerification

			return true;
		}

		// The gateway settings are also read over the REST API by the payments settings screen.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			global $wp;
			if ( isset( $wp->query_vars['rest_route'] ) && false !== strpos( $wp->query_vars['rest_route'], '/payment_gateways' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build the options for the "Enable for shipping methods" setting.
	 *
	 * @since 2.0.0
	 *
	 * @return array
	 */
	private function load_shipping_method_options() {
		if ( ! $this->is_accessing_settings() ) {
			return array();
		}

		$shipping_methods = array();

		if ( version_compare( WC_VERSION, '3.4', '<' ) ) {
			foreach ( WC()->shipping()->load_shipping_methods() as $method ) {
				$shipping_methods[ $method->id ] = $method->get_method_title();
			}

			return $shipping_methods;
		}

		$data_store = WC_Data_Store::load( 'shipping-zone' );
		$raw_zones  = $data_store->get_zones();
		$zones      = array();

		foreach ( $raw_zones as $raw_zone ) {
			$zones[] = new WC_Shipping_Zone( $raw_zone );
		}

		$zones[] = new WC_Shipping_Zone( 0 );

		foreach ( WC()->shipping()->load_shipping_methods() as $method ) {

			$group_title = $method->get_method_title();

			// Two methods can share a title - the classic "local_pickup" and the Checkout
			// block's "pickup_location" both call themselves "Local pickup". Keep both
			// instead of letting the later one reset the group, and tell them apart.
			if ( isset( $shipping_methods[ $group_title ] ) ) {
				// Translators: %1$s shipping method name.
				$any_method_title = sprintf( __( 'Any &quot;%1$s&quot; method', 'woocommerce' ), $group_title ) . ' (' . $method->id . ')';
			} else {
				$shipping_methods[ $group_title ] = array();

				// Translators: %1$s shipping method name.
				$any_method_title = sprintf( __( 'Any &quot;%1$s&quot; method', 'woocommerce' ), $group_title );
			}

			$shipping_methods[ $group_title ][ $method->id ] = $any_method_title;

			foreach ( $zones as $zone ) {

				foreach ( $zone->get_shipping_methods() as $shipping_method_instance_id => $shipping_method_instance ) {

					if ( $shipping_method_instance->id !== $method->id ) {
						continue;
					}

					$option_id = $shipping_method_instance->get_rate_id();

					// Translators: %1$s shipping method title, %2$s shipping method id.
					$option_instance_title = sprintf( __( '%1$s (#%2$s)', 'woocommerce' ), $shipping_method_instance->get_title(), $shipping_method_instance_id );

					// Translators: %1$s zone name, %2$s shipping method instance name.
					$option_title = sprintf( __( '%1$s &ndash; %2$s', 'woocommerce' ), $zone->get_id() ? $zone->get_zone_name() : __( 'Other locations', 'woocommerce' ), $option_instance_title );

					$shipping_methods[ $group_title ][ $option_id ] = $option_title;
				}
			}
		}

		return $shipping_methods;
	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		$shipping_methods = $this->load_shipping_method_options();
		$order_statuses   = array();

		if ( is_admin() ) {
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
				'type'        => 'safe_text',
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
				'title'             => __( 'Enable for shipping methods', 'woocommerce' ),
				'type'              => 'multiselect',
				'class'             => 'wc-enhanced-select chosen_select',
				'css'               => 'width: 450px;',
				'default'           => '',
				'description'       => __( 'If COP is only available for certain methods, set it up here. Leave blank to enable for all methods.', 'wc-cash-on-pickup' ),
				'options'           => $shipping_methods,
				'desc_tip'          => true,
				'custom_attributes' => array(
					'data-placeholder' => __( 'Select shipping methods', 'woocommerce' ),
				),
			),
			'default_order_status' => array(
				'title'       => __( 'Default order status', 'wc-cash-on-pickup' ),
				'type'        => 'select',
				'default'     => apply_filters( 'wc_cop_default_order_status', 'on-hold' ),
				'options'     => $order_statuses,
			),
			'exclusive_for_local' => array(
				'title'       => __( 'Disable other payment methods for local pickup', 'wc-cash-on-pickup' ),
				'label'       => __( 'Make cash on pickup the only payment method when local pickup is selected at checkout', 'wc-cash-on-pickup' ),
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
		} elseif ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			$order_id = absint( get_query_var( 'order-pay' ) );
			$order    = wc_get_order( $order_id );

			// Test if order needs shipping.
			if ( $order && 0 < count( $order->get_items() ) ) {
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
		if ( version_compare( WC_VERSION, '3.4', '>=' ) ) {
			if ( ! empty( $this->enable_for_methods ) && $needs_shipping ) {
				$order_shipping_items = is_object( $order ) ? $order->get_shipping_methods() : false;

				if ( $order_shipping_items ) {
					$canonical_rate_ids = $this->get_canonical_order_shipping_item_rate_ids( $order_shipping_items );
				} else {
					$canonical_rate_ids = $this->get_canonical_cart_rate_ids();
				}

				// While no shipping method is chosen yet keep the gateway available - the block
				// checkout asks for availability before the customer has picked a rate.
				if ( ! empty( $canonical_rate_ids ) && ! count( $this->get_matching_rates( $canonical_rate_ids ) ) ) {
					return false;
				}
			}
		} else {
			if ( ! empty( $this->enable_for_methods ) && $needs_shipping ) {
				$chosen_shipping_methods         = array();
				$chosen_shipping_methods_session = WC()->session ? WC()->session->get( 'chosen_shipping_methods' ) : array();

				if ( is_object( $order ) ) {
					$chosen_shipping_methods = array_unique( array_map( array( $this, 'get_string_before_colon' ), $order->get_shipping_methods() ) );
				} elseif ( $chosen_shipping_methods_session ) {
					$chosen_shipping_methods = array_unique( array_map( array( $this, 'get_string_before_colon' ), $chosen_shipping_methods_session ) );
				}

				// Local Pickup Plus fix
				unset( $chosen_shipping_methods["undefined"] );

				if ( 0 < count( array_diff( $chosen_shipping_methods, $this->enable_for_methods ) ) ) {
					return false;
				}
			}
		}

		return parent::is_available();
	}

	/**
	 * Converts the chosen rate IDs generated by Shipping Methods to a canonical 'method_id:instance_id' format.
	 *
	 * @since  3.4.0
	 *
	 * @param  array $order_shipping_items  Array of WC_Order_Item_Shipping objects.
	 * @return array $canonical_rate_ids    Rate IDs in a canonical format.
	 */
	private function get_canonical_order_shipping_item_rate_ids( $order_shipping_items ) {

		$canonical_rate_ids = array();

		foreach ( $order_shipping_items as $order_shipping_item ) {
			$canonical_rate_ids[] = $order_shipping_item->get_method_id() . ':' . $order_shipping_item->get_instance_id();
		}

		return $canonical_rate_ids;
	}

	/**
	 * Rate IDs of the shipping methods chosen for the current cart, in a canonical
	 * 'method_id:instance_id' format.
	 *
	 * The calculated cart is read first because that is what is populated during Store API
	 * (block checkout) requests; the session based lookup stays as a fallback.
	 *
	 * @return array $canonical_rate_ids Rate IDs in a canonical format.
	 */
	private function get_canonical_cart_rate_ids() {
		$canonical_rate_ids = array();

		if ( WC()->cart && is_callable( array( WC()->cart, 'get_shipping_methods' ) ) ) {
			foreach ( (array) WC()->cart->get_shipping_methods() as $rate ) {
				if ( is_object( $rate ) && is_callable( array( $rate, 'get_method_id' ) ) && is_callable( array( $rate, 'get_instance_id' ) ) ) {
					$canonical_rate_ids[] = $rate->get_method_id() . ':' . $rate->get_instance_id();
				}
			}
		}

		if ( empty( $canonical_rate_ids ) && WC()->session ) {
			$canonical_rate_ids = $this->get_canonical_package_rate_ids( WC()->session->get( 'chosen_shipping_methods' ) );
		}

		return array_unique( $canonical_rate_ids );
	}

	/**
	 * Converts the chosen rate IDs generated by Shipping Methods to a canonical 'method_id:instance_id' format.
	 *
	 * @since  3.4.0
	 *
	 * @param  array $chosen_package_rate_ids Rate IDs as generated by shipping methods. Can be anything if a shipping method doesn't honor WC conventions.
	 * @return array $canonical_rate_ids  Rate IDs in a canonical format.
	 */
	private function get_canonical_package_rate_ids( $chosen_package_rate_ids ) {

		$shipping_packages  = WC()->shipping()->get_packages();
		$canonical_rate_ids = array();

		if ( ! empty( $chosen_package_rate_ids ) && is_array( $chosen_package_rate_ids ) ) {
			foreach ( $chosen_package_rate_ids as $package_key => $chosen_package_rate_id ) {
				if ( ! empty( $shipping_packages[ $package_key ]['rates'][ $chosen_package_rate_id ] ) ) {
					$chosen_rate          = $shipping_packages[ $package_key ]['rates'][ $chosen_package_rate_id ];
					$canonical_rate_ids[] = $chosen_rate->get_method_id() . ':' . $chosen_rate->get_instance_id();
				}
			}
		}

		return $canonical_rate_ids;
	}

	/**
	 * Indicates whether a rate exists in an array of canonically-formatted rate IDs that activates this gateway.
	 *
	 * @since  3.4.0
	 *
	 * @param array $rate_ids Rate ids to check.
	 * @return boolean
	 */
	private function get_matching_rates( $rate_ids ) {
		// First, match entries in 'method_id:instance_id' format. Then, match entries in 'method_id' format by stripping off the instance ID from the candidates.
		return array_unique( array_merge( array_intersect( $this->enable_for_methods, $rate_ids ), array_intersect( $this->enable_for_methods, array_unique( array_map( 'wc_get_string_before_colon', $rate_ids ) ) ) ) );
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		$order->update_status(
			apply_filters( 'wc_cop_default_order_status', $this->default_order_status ),
			__( 'Payment to be made upon pickup.', 'wc-cash-on-pickup' )
		);

		// Reduce stock levels
		if ( version_compare( WC_VERSION, '3.0', '>=' ) ) {
			wc_reduce_stock_levels( $order_id );
		} else {
			$order->reduce_order_stock();
		}

		// Remove cart if it still matches the order being processed.
		if ( WC()->cart && ( ! is_callable( array( $order, 'has_cart_hash' ) ) || $order->has_cart_hash( WC()->cart->get_cart_hash() ) ) ) {
			WC()->cart->empty_cart();
		}

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
	 * Treat "completed" as the payment complete status for COP orders.
	 *
	 * With cash on pickup the money only changes hands when the order is handed over,
	 * so WC_Order::maybe_set_date_paid() must not stamp date_paid the moment an order
	 * reaches "processing" - that would report an unpaid order as paid on the order
	 * screen, in Analytics and over the REST API. Declaring "completed" as the payment
	 * complete status moves date_paid to the point the order is marked completed, which
	 * is when the cash was actually taken.
	 *
	 * Mirrors WC_Gateway_COD::change_payment_complete_order_status().
	 *
	 * @param  string         $status   Status to use when payment is complete.
	 * @param  int            $order_id Order ID.
	 * @param  WC_Order|false $order    Order object.
	 * @return string
	 */
	public function change_payment_complete_order_status( $status, $order_id = 0, $order = false ) {
		if ( $order && $this->id === $order->get_payment_method() ) {
			$status = 'completed';
		}

		return $status;
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

		if ( ! $this->instructions || $sent_to_admin || $this->id !== $payment_method ) {
			return;
		}

		/**
		 * Filter the order statuses the pickup instructions are withheld from.
		 *
		 * The cash is handed over when the order is collected, so the instructions stay useful
		 * for as long as that can still happen - including once the order is marked completed,
		 * which does not mean it has been picked up. They are only pointless for orders that
		 * will never be collected.
		 *
		 * Before 2.0.0 the instructions were shown only while the order matched the gateway's
		 * "default order status" setting. That tied past orders to a value that can be changed
		 * at any time: editing the setting silently removed the instructions from emails for
		 * every order already placed under the old one.
		 *
		 * @since 2.0.0
		 *
		 * @param array    $statuses Order statuses that suppress the instructions.
		 * @param WC_Order $order    The order object.
		 */
		$skip_statuses = apply_filters(
			'wc_cop_email_instructions_skip_order_statuses',
			array( 'cancelled', 'refunded', 'failed' ),
			$order
		);

		if ( ! empty( $skip_statuses ) && $order->has_status( $skip_statuses ) ) {
			return;
		}

		echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) . PHP_EOL );
	}
}
