<?php
/**
 * Prepares the development site for the end-to-end tests and records what it changed.
 *
 * Run from the WordPress root:  wp eval-file wp-content/plugins/wc-cash-on-pickup/tests/bin/setup-site.php
 *
 *   wp eval-file ... setup-site.php block-first
 *
 * Nothing here is tied to a particular site. The checkout pages are discovered from
 * WooCommerce's own page settings, and the shipping methods the tests need are created here
 * rather than inherited, so the suite runs anywhere without editing config.json. What it sets
 * up is written to site-state.json for the JavaScript tests to read.
 *
 * The optional argument picks the store shape:
 *
 *   grandfathered  (default)  a store that predates the block checkout: some zone still has an
 *                             enabled zone-based local_pickup, alongside the block's own
 *                             pickup_location.
 *   block-first               a store created after the block checkout became the default. No
 *                             zone has local_pickup - WooCommerce even hides it from the "Add
 *                             shipping method" picker in this state
 *                             (html-admin-page-shipping-zone-methods.php, guarded by
 *                             ShippingController::is_legacy_local_pickup_active()) - so
 *                             pickup_location is the only way to collect an order.
 *
 * Restore afterwards with bin/restore-site.php - it puts every option back, deletes any page or
 * shipping method instance this script created, and re-enables anything it disabled.
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

/**
 * The zone WooCommerce would use for the address the tests check out with.
 *
 * Shipping methods are added to this zone rather than to a zone of our own, so zone matching
 * keeps working exactly as the store has it configured.
 *
 * @param array $config Parsed tests/config.json.
 * @return WC_Shipping_Zone
 */
function wc_cop_tests_zone_for_test_address( $config ) {
	$package = array(
		'destination' => array(
			'country'  => $config['address']['country'],
			'state'    => '',
			'postcode' => $config['address']['postcode'],
		),
	);

	return WC_Shipping_Zones::get_zone_matching_package( $package );
}

/**
 * Adds a shipping method instance to a zone and configures it.
 *
 * Instance ids are a global auto-increment and are burned permanently by create/delete, so no
 * caller may ever assume a value - the id this returns is the only truth.
 *
 * @param WC_Shipping_Zone $zone     Zone to add to.
 * @param string           $method   Method id, e.g. "flat_rate".
 * @param array            $settings Instance settings to merge in.
 * @return int Instance id.
 */
function wc_cop_tests_add_zone_method( $zone, $method, $settings = array() ) {
	$instance_id = $zone->add_shipping_method( $method );

	if ( ! $instance_id ) {
		echo "Could not add a $method instance to zone " . $zone->get_id() . ".\n";
		exit( 1 );
	}

	if ( $settings ) {
		$option = 'woocommerce_' . $method . '_' . $instance_id . '_settings';
		update_option( $option, array_merge( (array) get_option( $option, array() ), $settings ) );
	}

	return (int) $instance_id;
}

/**
 * Every zone, including "rest of the world", as WC_Shipping_Zone objects.
 *
 * @return WC_Shipping_Zone[]
 */
function wc_cop_tests_all_zones() {
	$zones = array( new WC_Shipping_Zone( 0 ) );
	foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
		$zones[] = new WC_Shipping_Zone( $zone_data['id'] );
	}
	return $zones;
}

/**
 * Turns a shipping method instance on or off.
 *
 * There is no API for this - WooCommerce flips the column directly too, in
 * WC_AJAX::shipping_zone_methods_save_settings(). Kept in one place so the reason is written down
 * once.
 *
 * @param int  $instance_id Instance to change.
 * @param bool $enabled     Desired state.
 * @return void
 */
function wc_cop_tests_set_instance_enabled( $instance_id, $enabled ) {
	global $wpdb;

	$wpdb->update(
		$wpdb->prefix . 'woocommerce_shipping_zone_methods',
		array( 'is_enabled' => $enabled ? 1 : 0 ),
		array( 'instance_id' => (int) $instance_id )
	);

	WC_Cache_Helper::get_transient_version( 'shipping', true );
}

$dir    = dirname( __DIR__ );
$config = json_decode( file_get_contents( $dir . '/config.json' ), true );

$shape = isset( $args[0] ) ? $args[0] : 'grandfathered';
if ( ! in_array( $shape, array( 'grandfathered', 'block-first' ), true ) ) {
	echo "Unknown store shape \"$shape\". Use \"grandfathered\" or \"block-first\".\n";
	exit( 1 );
}

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

// Two pickup locations, so pickup_location:0 and pickup_location:1 both exist. The instance id
// of a pickup_location rate is the index of the enabled location, not a zone instance id.
update_option( 'woocommerce_pickup_location_settings', array( 'enabled' => 'yes', 'title' => 'Pickup', 'tax_status' => 'taxable', 'cost' => '' ) );
update_option(
	'pickup_location_pickup_locations',
	array(
		array(
			'name'    => 'COP test shop',
			'address' => array(
				'address_1' => $config['address']['address_1'],
				'city'      => $config['address']['city'],
				'state'     => '',
				'postcode'  => $config['address']['postcode'],
				'country'   => $config['address']['country'],
			),
			'details' => 'Mon-Fri 9-17',
			'enabled' => true,
		),
		array(
			'name'    => 'COP test warehouse',
			'address' => array(
				'address_1' => 'Depot Road 5',
				'city'      => 'Kosice',
				'state'     => '',
				'postcode'  => '04001',
				'country'   => $config['address']['country'],
			),
			'details' => 'Sat 8-12',
			'enabled' => true,
		),
	)
);

// ---- shipping methods --------------------------------------------------------------------
// Created here rather than inherited from the site, so the rate ids are known and both store
// shapes can be produced on demand. Everything created is recorded and removed on restore.
$zone               = wc_cop_tests_zone_for_test_address( $config );
$created_instances  = array();
$disabled_instances = array();

$flat_a = wc_cop_tests_add_zone_method( $zone, 'flat_rate', array( 'title' => 'COP test flat rate', 'cost' => '5' ) );
$flat_b = wc_cop_tests_add_zone_method( $zone, 'flat_rate', array( 'title' => 'COP test courier', 'cost' => '9' ) );
foreach ( array( $flat_a, $flat_b ) as $instance_id ) {
	$created_instances[] = array( 'zone' => $zone->get_id(), 'instance' => $instance_id, 'method' => 'flat_rate' );
}

$rates = array(
	'flatRate'            => 'flat_rate:' . $flat_a,
	'flatRateOther'       => 'flat_rate:' . $flat_b,
	'pickupLocation'      => 'pickup_location:0',
	'pickupLocationOther' => 'pickup_location:1',
);

// Whatever zone local_pickup the store already has is switched off for the duration of the run,
// in both shapes. Otherwise the Pickup list would contain the site's own instances as well and
// the tests could only assert loosely. Disabled rather than deleted - these belong to the
// maintainer, and restore turns them back on.
foreach ( wc_cop_tests_all_zones() as $any_zone ) {
	foreach ( $any_zone->get_shipping_methods( false ) as $instance_id => $method ) {
		if ( 'local_pickup' === $method->id && $method->is_enabled() ) {
			wc_cop_tests_set_instance_enabled( $instance_id, false );
			$disabled_instances[] = array( 'zone' => $any_zone->get_id(), 'instance' => (int) $instance_id );
		}
	}
}

if ( 'grandfathered' === $shape ) {
	// One zone-based local_pickup of our own, as a store that predates the block checkout has.
	$local_pickup         = wc_cop_tests_add_zone_method( $zone, 'local_pickup', array( 'title' => 'COP test local pickup', 'cost' => '' ) );
	$created_instances[]  = array( 'zone' => $zone->get_id(), 'instance' => $local_pickup, 'method' => 'local_pickup' );
	$rates['localPickup'] = 'local_pickup:' . $local_pickup;
}
// Block-first: none is created, so no zone offers local_pickup at all and WooCommerce stops
// treating the store as grandfathered (ShippingController::is_legacy_local_pickup_active).

// Order placement runs as a guest so the tests never create user accounts.
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' );

WC_Cache_Helper::get_transient_version( 'shipping', true );

$state = array(
	'storeShape'                => $shape,
	'classicCheckoutPageId'     => $classic_id,
	'classicCheckoutPath'       => wp_make_link_relative( get_permalink( $classic_id ) ),
	'blockCheckoutPageId'       => $block_id,
	'blockCheckoutPath'         => wp_make_link_relative( get_permalink( $block_id ) ),
	'rates'                     => $rates,
	'createdPages'              => $created,
	'createdShippingInstances'  => $created_instances,
	'disabledShippingInstances' => $disabled_instances,
);
file_put_contents( $state_file, wp_json_encode( $state, JSON_PRETTY_PRINT ) );
chmod( $state_file, 0600 );

$legacy_active = \Automattic\WooCommerce\Blocks\Shipping\ShippingController::is_legacy_local_pickup_active();

echo 'store shape:      ' . $shape . "\n";
echo 'classic checkout: ' . $state['classicCheckoutPath'] . ' (page ' . $classic_id . ')' . ( in_array( $classic_id, $created, true ) ? ' - created' : '' ) . "\n";
echo 'block checkout:   ' . $state['blockCheckoutPath'] . ' (page ' . $block_id . ')' . ( in_array( $block_id, $created, true ) ? ' - created' : '' ) . "\n";
echo 'block default:    ' . var_export( \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::is_checkout_block_default(), true ) . "\n";
echo 'zone local pickup:' . ( $legacy_active ? ' active (grandfathered)' : ' none (block-first)' ) . "\n";
echo 'shipping zone:    ' . $zone->get_id() . ' "' . $zone->get_zone_name() . '"' . "\n";
echo 'rates:            ' . wp_json_encode( $rates ) . "\n";
if ( $disabled_instances ) {
	echo 'disabled:         ' . implode( ', ', array_map( function ( $i ) { return 'local_pickup:' . $i['instance']; }, $disabled_instances ) ) . "\n";
}
echo 'guest checkout:   ' . get_option( 'woocommerce_enable_guest_checkout' ) . "\n";

// pickup_location registration depends on the checkout page, not on zones, so it must be
// present in both shapes. If it ever is not, the block matrix would test nothing at all.
WC()->shipping()->load_shipping_methods();
$registered    = array_keys( WC()->shipping()->get_shipping_methods() );
$pickup_methods = apply_filters( 'woocommerce_local_pickup_methods', array( 'legacy_local_pickup', 'local_pickup' ) );

echo 'registered:       ' . implode( ', ', $registered ) . "\n";
echo 'counts as pickup: ' . implode( ', ', $pickup_methods ) . "\n";

if ( ! in_array( 'pickup_location', $registered, true ) || ! in_array( 'pickup_location', $pickup_methods, true ) ) {
	echo "FAILED: pickup_location is not registered or does not count as local pickup.\n";
	exit( 1 );
}

// A shape the tests cannot actually exercise must not look like a successful setup.
if ( 'block-first' === $shape && $legacy_active ) {
	echo "FAILED: zone local_pickup is still active, so this is not a block-first store.\n";
	exit( 1 );
}
if ( 'grandfathered' === $shape && ! $legacy_active ) {
	echo "FAILED: no zone offers local_pickup, so this is not a grandfathered store.\n";
	exit( 1 );
}

echo "ready.\n";
