<?php
/**
 * Prepares the development site for the end-to-end tests and records what it changed.
 *
 * Run from the WordPress root:  wp eval-file wp-content/plugins/wc-cash-on-pickup/tests/bin/setup-site.php
 *
 * Nothing here is tied to a particular site. The checkout pages are discovered from
 * WooCommerce's own page settings and only created when the store has none, so the suite
 * runs anywhere without editing config.json. What it finds is written to site-state.json
 * for the JavaScript tests to read.
 *
 * Restore afterwards with bin/restore-site.php - it puts every option back to the value
 * captured here and deletes any page this script created.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Only ever run inside WordPress, via `wp eval-file`. Never over HTTP.
}

/**
 * Where a test run keeps its files.
 *
 * Deliberately outside the plugin directory: the plugin lives in the web root, and these
 * files hold the store's own option values. Mirrors tests/lib/artifacts.js.
 *
 * @param array $config Parsed tests/config.json.
 * @return string
 */
function wc_cop_tests_artifacts_dir( $config ) {
	$base = ! empty( $config['artifactsDir'] ) ? $config['artifactsDir'] : sys_get_temp_dir() . '/wc-cop-tests';
	if ( ! is_dir( $base ) ) {
		mkdir( $base, 0700, true );
	}
	return $base;
}

/**
 * The store settings captured before this script changed them.
 *
 * @param array $config Parsed tests/config.json.
 * @return string
 */
function wc_cop_tests_backup_file( $config ) {
	return wc_cop_tests_artifacts_dir( $config ) . '/site-backup.json';
}

/**
 * The page ids and urls the tests should drive, as discovered on this site.
 *
 * @param array $config Parsed tests/config.json.
 * @return string
 */
function wc_cop_tests_state_file( $config ) {
	return wc_cop_tests_artifacts_dir( $config ) . '/site-state.json';
}

/**
 * True when a page's content contains the given marker.
 *
 * @param int    $page_id Page to inspect.
 * @param string $needle  Literal string to look for.
 * @return bool
 */
function wc_cop_tests_page_contains( $page_id, $needle ) {
	if ( ! $page_id ) {
		return false;
	}
	$content = get_post_field( 'post_content', $page_id );
	return is_string( $content ) && false !== strpos( $content, $needle );
}

/**
 * The oldest published page whose content contains the given marker.
 *
 * @param string $needle Literal string to look for.
 * @param int    $skip   Page id to ignore, so the two checkouts can never resolve to one page.
 * @return int Page id, or 0.
 */
function wc_cop_tests_find_page_containing( $needle, $skip = 0 ) {
	global $wpdb;

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'page' AND post_status = 'publish'
			   AND post_content LIKE %s AND ID <> %d
			 ORDER BY ID ASC LIMIT 1",
			'%' . $wpdb->esc_like( $needle ) . '%',
			$skip
		)
	);
}

/**
 * A page an earlier run of this suite created, if it is still around.
 *
 * @param string $kind "classic" or "block".
 * @return int Page id, or 0.
 */
function wc_cop_tests_find_marked_page( $kind ) {
	$pages = get_posts(
		array(
			'post_type'        => 'page',
			'post_status'      => 'publish',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'meta_key'         => '_wc_cop_test_page', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'       => $kind,               // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'suppress_filters' => true,
		)
	);

	return $pages ? (int) $pages[0] : 0;
}

/**
 * Creates a checkout page for the tests and marks it so restore-site.php can remove it.
 *
 * @param string $title   Page title.
 * @param string $content Page content.
 * @param string $kind    "classic" or "block".
 * @return int Page id.
 */
function wc_cop_tests_create_page( $title, $content, $kind ) {
	$page_id = wp_insert_post(
		array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'publish',
			'post_type'    => 'page',
		)
	);

	if ( is_wp_error( $page_id ) || ! $page_id ) {
		echo "Could not create the $kind checkout page.\n";
		exit( 1 );
	}

	update_post_meta( $page_id, '_wc_cop_test_page', $kind );

	return (int) $page_id;
}

/**
 * WooCommerce's own default content for a Checkout block page.
 *
 * Taken from WC_Install so the created page matches what WooCommerce would have made.
 *
 * @return string
 */
function wc_cop_tests_block_checkout_content() {
	if ( class_exists( 'WC_Install' ) && method_exists( 'WC_Install', 'get_checkout_block_content' ) ) {
		$method = new ReflectionMethod( 'WC_Install', 'get_checkout_block_content' );
		$method->setAccessible( true );
		return (string) $method->invoke( null );
	}

	return '<!-- wp:woocommerce/checkout /-->';
}

$dir    = dirname( __DIR__ );
$config = json_decode( file_get_contents( $dir . '/config.json' ), true );

$backup_file = wc_cop_tests_backup_file( $config );
$state_file  = wc_cop_tests_state_file( $config );

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

$configured = (int) $backup['woocommerce_checkout_page_id'];
$created    = array();

// The classic checkout: whatever page the store already uses for the shortcode. Only when
// the store has no such page at all does the suite make one.
$classic_id = wc_cop_tests_page_contains( $configured, '[woocommerce_checkout]' ) ? $configured : 0;
if ( ! $classic_id ) {
	$classic_id = wc_cop_tests_find_marked_page( 'classic' );
}
if ( ! $classic_id ) {
	$classic_id = wc_cop_tests_find_page_containing( '[woocommerce_checkout]' );
}
if ( ! $classic_id ) {
	$classic_id = wc_cop_tests_create_page(
		'COP classic checkout test',
		"<!-- wp:shortcode -->\n[woocommerce_checkout]\n<!-- /wp:shortcode -->",
		'classic'
	);
	$created[] = $classic_id;
}

// The block checkout, found the same way. It must be a different page from the classic one,
// because the two matrices need both kinds available at once.
$block_id = wc_cop_tests_find_marked_page( 'block' );
if ( ! $block_id && wc_cop_tests_page_contains( $configured, 'wp:woocommerce/checkout' ) ) {
	$block_id = $configured;
}
if ( ! $block_id ) {
	$block_id = wc_cop_tests_find_page_containing( '<!-- wp:woocommerce/checkout', $classic_id );
}
if ( ! $block_id || $block_id === $classic_id ) {
	$block_id  = wc_cop_tests_create_page( 'COP block checkout test', wc_cop_tests_block_checkout_content(), 'block' );
	$created[] = $block_id;
}

// The Checkout block must be the store's checkout page, otherwise WooCommerce does not
// register its "pickup_location" shipping method at all.
update_option( 'woocommerce_checkout_page_id', $block_id );

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

$state = array(
	'classicCheckoutPageId' => $classic_id,
	'classicCheckoutPath'   => wp_make_link_relative( get_permalink( $classic_id ) ),
	'blockCheckoutPageId'   => $block_id,
	'blockCheckoutPath'     => wp_make_link_relative( get_permalink( $block_id ) ),
	'createdPages'          => $created,
);
file_put_contents( $state_file, wp_json_encode( $state, JSON_PRETTY_PRINT ) );
chmod( $state_file, 0600 );

echo 'classic checkout: ' . $state['classicCheckoutPath'] . ' (page ' . $classic_id . ')' . ( in_array( $classic_id, $created, true ) ? ' - created' : '' ) . "\n";
echo 'block checkout:   ' . $state['blockCheckoutPath'] . ' (page ' . $block_id . ')' . ( in_array( $block_id, $created, true ) ? ' - created' : '' ) . "\n";
echo 'block default:    ' . var_export( \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::is_checkout_block_default(), true ) . "\n";
echo 'guest checkout:   ' . get_option( 'woocommerce_enable_guest_checkout' ) . "\n";
echo "ready.\n";
