<?php
/**
 * Cash On Pickup for WooCommerce
 *
 * Checkout block (WooCommerce Blocks / Store API) support for the gateway.
 *
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

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! class_exists( 'WC_Gateway_Cash_on_pickup_Blocks_Support' ) ) :

/**
 * Exposes the Cash on Pickup gateway to the block based checkout.
 *
 * The block checkout renders payment methods in JavaScript, so everything the
 * classic checkout reads straight from the gateway object (title, description,
 * icon, availability rules) has to be handed over to the client as data.
 *
 * @class   WC_Gateway_Cash_on_pickup_Blocks_Support
 * @extends AbstractPaymentMethodType
 */
final class WC_Gateway_Cash_on_pickup_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * Payment method name. Matches the id of WC_Gateway_Cash_on_pickup.
	 *
	 * @var string
	 */
	protected $name = 'cop';

	/**
	 * Script handle registered for the checkout block integration.
	 *
	 * @var string
	 */
	const SCRIPT_HANDLE = 'wc-cop-blocks-integration';

	/**
	 * Cached gateway instance. False when the gateway is not registered.
	 *
	 * @var WC_Gateway_Cash_on_pickup|false|null
	 */
	private $gateway = null;

	/**
	 * Initializes the payment method type.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_cop_settings', array() );
	}

	/**
	 * Returns if this payment method should be active. If false, the scripts will not be enqueued.
	 *
	 * Mirrors what WooCommerce core does for its own offline gateways: this is only about the
	 * "enabled" setting, the per-cart availability rules are evaluated in canMakePayment() and
	 * server side in WC_Gateway_Cash_on_pickup::is_available().
	 *
	 * @return boolean
	 */
	public function is_active() {
		return filter_var( $this->get_setting( 'enabled', false ), FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Get the gateway instance so that the same filtered title/description/icon the classic
	 * checkout shows can be passed to the block checkout.
	 *
	 * @return WC_Gateway_Cash_on_pickup|false
	 */
	private function get_gateway() {
		if ( null === $this->gateway ) {
			$this->gateway = false;

			if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
				$gateways = WC()->payment_gateways()->payment_gateways();

				if ( isset( $gateways[ $this->name ] ) ) {
					$this->gateway = $gateways[ $this->name ];
				}
			}
		}

		return $this->gateway;
	}

	/**
	 * Returns an array of scripts/handles to be registered for this payment method.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		$dependencies = array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' );

		// Shipped by WooCommerce Blocks. Guarded so that a missing handle can never stop the
		// integration script itself from loading - the script degrades gracefully without it.
		if ( wp_script_is( 'wc-sanitize', 'registered' ) ) {
			$dependencies[] = 'wc-sanitize';
		}

		wp_register_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/js/blocks/checkout.js', WC_COP_PLUGIN_FILE ),
			$dependencies,
			WC_COP_VERSION,
			true
		);

		return array( self::SCRIPT_HANDLE );
	}

	/**
	 * Returns an array of supported features.
	 *
	 * @return string[]
	 */
	public function get_supported_features() {
		$gateway = $this->get_gateway();

		if ( $gateway && is_array( $gateway->supports ) && ! empty( $gateway->supports ) ) {
			return array_values( $gateway->supports );
		}

		return parent::get_supported_features();
	}

	/**
	 * Title shown next to the payment method radio button.
	 *
	 * Uses the gateway getter so the woocommerce_gateway_title filter keeps working, exactly
	 * like on the classic checkout.
	 *
	 * @return string
	 */
	private function get_title() {
		$gateway = $this->get_gateway();
		$title   = $gateway ? $gateway->get_title() : $this->get_setting( 'title' );

		return '' !== trim( (string) $title ) ? $title : __( 'Cash on pickup', 'wc-cash-on-pickup' );
	}

	/**
	 * Description shown once the payment method is selected.
	 *
	 * The classic checkout runs the description through wpautop()/wptexturize() in
	 * templates/checkout/payment-method.php, so do the same here to keep line breaks.
	 *
	 * @return string
	 */
	private function get_description() {
		$gateway     = $this->get_gateway();
		$description = $gateway ? $gateway->get_description() : $this->get_setting( 'description' );

		if ( '' === trim( (string) $description ) ) {
			return '';
		}

		return wptexturize( wpautop( wp_kses_post( $description ) ) );
	}

	/**
	 * Gateway icon URL, as filtered through woocommerce_cop_icon.
	 *
	 * @return string
	 */
	private function get_icon() {
		$gateway = $this->get_gateway();
		$icon    = $gateway ? $gateway->icon : '';

		return is_string( $icon ) ? $icon : '';
	}

	/**
	 * Return enable_for_virtual option.
	 *
	 * @return boolean True if the store accepts cash on pickup for orders that need no shipping.
	 */
	private function get_enable_for_virtual() {
		return filter_var( $this->get_setting( 'enable_for_virtual', 'yes' ), FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Return enable_for_methods option.
	 *
	 * @return array Shipping method (rate) ids that allow cash on pickup. Empty means "all".
	 */
	private function get_enable_for_methods() {
		$enable_for_methods = $this->get_setting( 'enable_for_methods', array() );

		if ( empty( $enable_for_methods ) || ! is_array( $enable_for_methods ) ) {
			return array();
		}

		return array_values( $enable_for_methods );
	}

	/**
	 * Returns an array of key=>value pairs of data made available to the payment methods script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'                    => $this->get_title(),
			'description'              => $this->get_description(),
			'icon'                     => $this->get_icon(),
			'enableForVirtual'         => $this->get_enable_for_virtual(),
			'enableForShippingMethods' => $this->get_enable_for_methods(),
			'allowedTags'              => $this->get_allowed_description_tags(),
			'supports'                 => $this->get_supported_features(),
		);
	}

	/**
	 * HTML tags kept when the description is sanitized client side.
	 *
	 * WooCommerce core allows a very short list; lists are added here because instructions
	 * frequently use them. Filterable so a store can widen or narrow it.
	 *
	 * @return array
	 */
	private function get_allowed_description_tags() {
		$tags = array( 'a', 'b', 'em', 'i', 'strong', 'p', 'br', 'abbr', 'ul', 'ol', 'li', 'span' );

		/**
		 * Filter the HTML tags allowed in the payment method description on the block checkout.
		 *
		 * @since 2.0.0
		 *
		 * @param array $tags Allowed tag names.
		 */
		return array_values( (array) apply_filters( 'wc_cop_blocks_description_allowed_tags', $tags ) );
	}
}

endif;
