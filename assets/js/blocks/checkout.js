/**
 * Cash On Pickup for WooCommerce - block based checkout integration.
 *
 * Registers the "cop" payment method with the WooCommerce Blocks registry. Written against the
 * globals WooCommerce Blocks exposes (no build step) so the plugin stays installable as-is.
 *
 * The availability rules mirror WC_Gateway_Cash_on_pickup::is_available() on the server. The
 * server remains the authority - the Store API only lists gateways that pass is_available() -
 * but repeating the rules here lets the checkout hide the method the moment a shipping method
 * changes, instead of waiting for the cart request to come back.
 */
( function ( window ) {
	'use strict';

	var wc = window.wc || {};
	var wp = window.wp || {};

	if ( ! wc.wcBlocksRegistry || ! wc.wcSettings || ! wp.element ) {
		return;
	}

	var PAYMENT_METHOD_NAME = 'cop';

	var createElement = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var RawHTML = wp.element.RawHTML;

	var settings = wc.wcSettings.getPaymentMethodData( PAYMENT_METHOD_NAME, {} ) || {};

	var decodeEntities =
		wp.htmlEntities && wp.htmlEntities.decodeEntities
			? wp.htmlEntities.decodeEntities
			: function ( value ) {
					return value;
			  };

	var label = decodeEntities( settings.title || '' ) || 'Cash on pickup';

	/**
	 * Description shown when the payment method is selected.
	 *
	 * Sanitized with WooCommerce's own sanitizer when available; without it the markup is
	 * dropped rather than trusted, and the plain text is shown instead.
	 */
	var Content = function () {
		var description = settings.description || '';

		if ( ! description ) {
			return null;
		}

		if ( ! wc.sanitize || ! wc.sanitize.sanitizeHTML ) {
			return createElement(
				'p',
				null,
				decodeEntities( description.replace( /<[^>]*>/g, '' ) )
			);
		}

		return createElement(
			RawHTML,
			null,
			wc.sanitize.sanitizeHTML( description, {
				tags: settings.allowedTags || undefined,
			} )
		);
	};

	/**
	 * Label (and optional gateway icon) shown next to the radio button.
	 */
	var Label = function ( props ) {
		var components = props.components || {};
		var PaymentMethodLabel = components.PaymentMethodLabel;
		var PaymentMethodIcons = components.PaymentMethodIcons;

		var labelElement = PaymentMethodLabel
			? createElement( PaymentMethodLabel, { text: label } )
			: label;

		if ( ! settings.icon || ! PaymentMethodIcons ) {
			return labelElement;
		}

		return createElement(
			Fragment,
			null,
			labelElement,
			createElement( PaymentMethodIcons, {
				icons: [
					{
						id: PAYMENT_METHOD_NAME,
						src: settings.icon,
						alt: label,
					},
				],
				align: 'right',
			} )
		);
	};

	/**
	 * Mirrors the server side availability rules.
	 *
	 * @param {Object} args                         Arguments passed by the checkout.
	 * @param {boolean} args.cartNeedsShipping      Whether the cart contains shippable items.
	 * @param {Object} args.selectedShippingMethods Chosen rate id per shipping package.
	 * @return {boolean} Whether cash on pickup can be used.
	 */
	var canMakePayment = function ( args ) {
		args = args || {};

		// Virtual order: only the "Accept for virtual orders" setting decides.
		if ( ! args.cartNeedsShipping ) {
			return !! settings.enableForVirtual;
		}

		var enabledMethods = settings.enableForShippingMethods || [];

		if ( ! enabledMethods.length ) {
			return true;
		}

		var selectedShippingMethods = args.selectedShippingMethods || {};
		var chosenRateIds = Object.keys( selectedShippingMethods ).map( function ( packageKey ) {
			return String( selectedShippingMethods[ packageKey ] );
		} );

		// Shipping has not been picked yet - stay visible, the server has the final word.
		if ( ! chosenRateIds.length ) {
			return true;
		}

		// Settings hold either "method_id" or "method_id:instance_id"; chosen rates are always
		// "method_id:instance_id", so a prefix match covers both. One matching package is
		// enough, which is what get_matching_rates() does server side.
		return chosenRateIds.some( function ( rateId ) {
			return enabledMethods.some( function ( enabledMethod ) {
				return rateId === enabledMethod || rateId.indexOf( enabledMethod + ':' ) === 0;
			} );
		} );
	};

	wc.wcBlocksRegistry.registerPaymentMethod( {
		name: PAYMENT_METHOD_NAME,
		label: createElement( Label, null ),
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		canMakePayment: canMakePayment,
		ariaLabel: label,
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )( window );
