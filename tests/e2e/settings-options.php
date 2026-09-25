<?php
/**
 * The "Enable for shipping methods" option list on the gateway settings screen.
 *
 * This is what the merchant actually picks from, and it is built by
 * WC_Gateway_Cash_on_pickup::load_shipping_method_options(). Two things make it worth asserting:
 *
 *   - zone based local_pickup and the Checkout block's pickup_location both call themselves
 *     "Local pickup", and the builder groups by title, so without the disambiguation one would
 *     silently overwrite the other;
 *   - pickup_location has no zone instances, so it contributes only a group entry. A specific
 *     pickup location therefore cannot be chosen from this screen, even though the matching
 *     logic would honour one.
 *
 * Run after bin/setup-site.php, from the WordPress root:
 *   wp eval-file wp-content/plugins/wc-cash-on-pickup/tests/e2e/settings-options.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Only ever run inside WordPress, via `wp eval-file`. Never over HTTP.
}

$dir    = dirname( __DIR__ );
$config = json_decode( file_get_contents( $dir . '/config.json' ), true );
$base   = ! empty( $config['artifactsDir'] ) ? $config['artifactsDir'] : sys_get_temp_dir() . '/wc-cop-tests';
$state  = json_decode( (string) file_get_contents( $base . '/site-state.json' ), true );

if ( ! $state ) {
	echo "No site-state.json - run bin/setup-site.php first.\n";
	exit( 1 );
}

$shape = $state['storeShape'];
$rates = $state['rates'];

// load_shipping_method_options() deliberately does nothing unless the settings are being read,
// so that it costs no queries on every other admin page. The payments settings screen reads them
// over the REST API, which is the branch simulated here.
if ( ! defined( 'REST_REQUEST' ) ) {
	define( 'REST_REQUEST', true );
}
global $wp;
if ( ! $wp instanceof WP ) {
	$wp = new WP();
}
$wp->query_vars['rest_route'] = '/wc/v3/payment_gateways';

$gateway = new WC_Gateway_Cash_on_pickup();
$gateway->init_form_fields();
$groups = $gateway->form_fields['enable_for_methods']['options'];

// Flatten to option key => label, and remember which group each key came from.
$options = array();
$group_of = array();
foreach ( $groups as $group_title => $entries ) {
	foreach ( (array) $entries as $key => $label ) {
		$options[ $key ]  = $label;
		$group_of[ $key ] = $group_title;
	}
}

// wp eval-file includes this inside a function scope, so file level variables are not globals -
// the counters have to live in $GLOBALS explicitly or every result is silently discarded.
$GLOBALS['wc_cop_pass'] = 0;
$GLOBALS['wc_cop_fail'] = 0;
function check( $name, $ok, $detail = '' ) {
	$GLOBALS[ $ok ? 'wc_cop_pass' : 'wc_cop_fail' ]++;
	echo ( $ok ? 'OK   ' : 'FAIL ' ) . str_pad( $name, 58 ) . ( $ok ? '' : ' -> ' . $detail ) . "\n";
}

echo "store shape: $shape\n";
echo 'options:     ' . wp_json_encode( $options ) . "\n\n";

// --- the entries the merchant needs ---------------------------------------------------------
check( 'group entry "local_pickup" is offered', isset( $options['local_pickup'] ), 'missing' );
check( 'group entry "pickup_location" is offered', isset( $options['pickup_location'] ), 'missing' );
check( 'group entry "flat_rate" is offered', isset( $options['flat_rate'] ), 'missing' );

// --- the title collision ---------------------------------------------------------------------
if ( isset( $options['local_pickup'], $options['pickup_location'] ) ) {
	check(
		'the two "Local pickup" entries are told apart',
		$options['local_pickup'] !== $options['pickup_location'],
		'both render as "' . $options['local_pickup'] . '"'
	);
	check(
		'they really do share a group title',
		$group_of['local_pickup'] === $group_of['pickup_location'],
		$group_of['local_pickup'] . ' vs ' . $group_of['pickup_location']
	);
}

// --- zone instances ----------------------------------------------------------------------------
check( 'the created flat rate is offered as an instance', isset( $options[ $rates['flatRate'] ] ), $rates['flatRate'] . ' missing' );

// Whether a zone offers local_pickup is the dimension that matters here, not the shape's name:
// both "grandfathered" and "pickup-disabled" have one, and only "block-first" does not.
if ( isset( $rates['localPickup'] ) ) {
	check( 'the zone local pickup is offered as an instance', isset( $options[ $rates['localPickup'] ] ), $rates['localPickup'] . ' missing' );
} else {
	// "Block-first" means no zone *offers* local pickup - not that the instances are gone. The
	// harness switches the store's own off rather than deleting them, and the option list is
	// built with WC_Shipping_Zone::get_shipping_methods() without $enabled_only, exactly as
	// core's Cash on Delivery builds its own, so disabled instances still appear. The invariant
	// worth asserting is therefore that none is enabled.
	$zones = array( new WC_Shipping_Zone( 0 ) );
	foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
		$zones[] = new WC_Shipping_Zone( $zone_data['id'] );
	}

	$enabled_zone_pickups = array();
	foreach ( $zones as $zone ) {
		foreach ( $zone->get_shipping_methods( true ) as $instance_id => $method ) {
			if ( 'local_pickup' === $method->id ) {
				$enabled_zone_pickups[] = 'local_pickup:' . $instance_id;
			}
		}
	}

	check( 'no zone local pickup is enabled', empty( $enabled_zone_pickups ), implode( ', ', $enabled_zone_pickups ) );
	check( 'local_pickup is still registered as a method', isset( $options['local_pickup'] ), 'the class is registered even when no zone uses it' );

	$listed = array_values( array_filter( array_keys( $options ), function ( $key ) { return 0 === strpos( $key, 'local_pickup:' ); } ) );
	echo 'note: disabled instances still offered: ' . ( $listed ? implode( ', ', $listed ) : 'none' ) . "\n";
}

// --- the asymmetry worth knowing about ----------------------------------------------------------
$location_instances = array_filter( array_keys( $options ), function ( $key ) { return 0 === strpos( $key, 'pickup_location:' ); } );
check(
	'individual pickup locations are not selectable here',
	empty( $location_instances ),
	'unexpectedly offered: ' . implode( ', ', $location_instances )
);

echo "\nSETTINGS OPTIONS: {$GLOBALS['wc_cop_pass']} passed, {$GLOBALS['wc_cop_fail']} failed\n";
exit( $GLOBALS['wc_cop_fail'] ? 1 : 0 );
