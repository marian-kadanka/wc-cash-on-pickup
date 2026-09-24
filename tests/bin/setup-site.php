<?php
/**
 * Prepares the development site for the end-to-end tests and records what it changed.
 *
 * Run from the WordPress root:  wp eval-file wp-content/plugins/wc-cash-on-pickup/tests/bin/setup-site.php
 *
 * Restore afterwards with bin/restore-site.php - it puts every option back to the value
 * captured here, so the store is left exactly as it was found.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Only ever run inside WordPress, via `wp eval-file`. Never over HTTP.
}

/**
 * Where the backup of the store's settings is kept.
 *
 * Deliberately outside the plugin directory: the plugin lives in the web root, and this file
 * holds the store's own option values. Mirrors tests/lib/artifacts.js.
 *
 * @param array $config Parsed tests/config.json.
 * @return string
 */
function wc_cop_tests_backup_file( $config ) {
	$base = ! empty( $config['artifactsDir'] ) ? $config['artifactsDir'] : sys_get_temp_dir() . '/wc-cop-tests';
	if ( ! is_dir( $base ) ) {
		mkdir( $base, 0700, true );
	}
	return $base . '/site-backup.json';
}

$dir    = dirname( __DIR__ );
$config = json_decode( file_get_contents( $dir . '/config.json' ), true );

$backup_file = wc_cop_tests_backup_file( $config );

if ( file_exists( $backup_file ) ) {
	echo "A backup already exists at $backup_file - restore it before setting up again.\n";
	exit( 1 );
}

$options = array(
	'woocommerce_checkout_page_id',
	'woocommerce_cop_settings',
	'woocommerce_pickup_location_settings',
	'pickup_location_pickup_locations',
	'woocommerce_enable_guest_checkout',
	'woocommerce_enable_signup_and_login_from_checkout',
);

$backup = array();
foreach ( $options as $option ) {
	$backup[ $option ] = get_option( $option );
}
file_put_contents( $backup_file, wp_json_encode( $backup, JSON_PRETTY_PRINT ) );
chmod( $backup_file, 0600 );
echo "backed up current values to $backup_file\n";

// The Checkout block must be the store's checkout page, otherwise WooCommerce does not
// register its "pickup_location" shipping method at all.
update_option( 'woocommerce_checkout_page_id', (int) $config['blockCheckoutPageId'] );

// Give the block checkout a pickup location to offer.
update_option( 'woocommerce_pickup_location_settings', array( 'enabled' => 'yes', 'title' => 'Pickup', 'tax_status' => 'taxable', 'cost' => '' ) );
update_option( 'pickup_location_pickup_locations', array(
	array(
		'name'    => 'COP test store',
		'address' => array(
			'address_1' => $config['address']['address_1'],
			'city'      => $config['address']['city'],
			'state'     => '',
			'postcode'  => $config['address']['postcode'],
			'country'   => $config['address']['country'],
		),
		'details' => '',
		'enabled' => true,
	),
) );

// Order placement runs as a guest so the tests never create user accounts.
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' );

echo "checkout page:  " . get_option( 'woocommerce_checkout_page_id' ) . "\n";
echo "block default:  " . var_export( \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::is_checkout_block_default(), true ) . "\n";
echo "guest checkout: " . get_option( 'woocommerce_enable_guest_checkout' ) . "\n";
echo "ready.\n";
