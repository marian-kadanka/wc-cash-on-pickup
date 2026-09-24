<?php
/**
 * Unit tests for the gateway's shipping method logic.
 *
 * Run with:  wp eval-file tests/unit/gateway-logic.php   (from the WordPress root)
 *
 * Exercises the private helpers through reflection, because they encode the rules that
 * decide when Cash on Pickup shows up and when it takes over the payment method list.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Only ever run inside WordPress, via `wp eval-file`. Never over HTTP.
}

$config            = json_decode( file_get_contents( dirname( __DIR__ ) . '/config.json' ), true );
$config_product_id = (int) $config['physicalProductId'];

$gateways = WC()->payment_gateways()->payment_gateways();
if ( ! isset( $gateways['cop'] ) ) {
	echo "FAIL: the cop gateway is not registered\n";
	exit( 1 );
}
$cop = $gateways['cop'];
$ref = new ReflectionClass( $cop );
$call = function ( $method, $args = array() ) use ( $ref, $cop ) {
	$m = $ref->getMethod( $method );
	$m->setAccessible( true );
	return $m->invokeArgs( $cop, $args );
};

$pass = 0;
$fail = 0;
$check = function ( $name, $got, $expected ) use ( &$pass, &$fail ) {
	$ok = $got === $expected;
	$ok ? $pass++ : $fail++;
	printf( "%-4s %-45s got %s\n", $ok ? 'OK' : 'FAIL', $name, var_export( $got, true ) );
};

// The block checkout's own local pickup method must be recognised alongside the classic one.
$local_pickup_ids = $call( 'get_local_pickup_method_ids' );
echo 'local pickup method ids: ' . implode( ', ', $local_pickup_ids ) . "\n\n";
$check( 'pickup_location is a local pickup method', in_array( 'pickup_location', $local_pickup_ids, true ), true );

foreach ( array(
	'local_pickup:3'      => true,
	'pickup_location:1'   => true,
	'legacy_local_pickup' => true,
	'local_pickup_plus'   => true,  // Local Pickup Plus
	'flat_rate:2'         => false,
	'free_shipping:1'     => false,
) as $rate_id => $expected ) {
	$check( "is_local_pickup_method( $rate_id )", $call( 'is_local_pickup_method', array( $rate_id ) ), $expected );
}

foreach ( array(
	'blocks pickup only'   => array( array( 0 => 'pickup_location:1' ), true ),
	'classic pickup only'  => array( array( 0 => 'local_pickup:3' ), true ),
	'pickup + flat rate'   => array( array( 0 => 'local_pickup:3', 1 => 'flat_rate:2' ), false ),
	'flat rate only'       => array( array( 0 => 'flat_rate:2' ), false ),
	'Local Pickup Plus'    => array( array( 0 => 'local_pickup:3', 'undefined' => 'undefined' ), true ),
	'nothing chosen'       => array( array(), false ),
) as $name => $case ) {
	$check( "only_local_pickups_selected( $name )", $call( 'only_local_pickups_selected', array( $case[0] ) ), $case[1] );
}

// The instructions in the customer email must not depend on the "default order status"
// setting: that would let a later settings change retroactively strip them from orders that
// were already placed. They are withheld only from orders that will never be collected.
echo "\nemail instructions by order status:\n";

$order = wc_create_order();
$order->set_payment_method( $cop );
$order->add_product( wc_get_product( $config_product_id ), 1 );
$order->calculate_totals();
$order->save();

$renders_instructions = function ( $status ) use ( $order, $cop ) {
	$order->set_status( $status );
	$order->save();
	ob_start();
	$cop->email_instructions( $order, false );
	$out = ob_get_clean();
	return false !== strpos( $out, 'INSTRUCTIONS-MARKER' );
};

$cop->instructions = 'INSTRUCTIONS-MARKER pay in cash at the shop.';

foreach ( array(
	'pending'    => true,
	'on-hold'    => true,
	'processing' => true,
	'completed'  => true,  // completed does not mean collected
	'cancelled'  => false,
	'refunded'   => false,
	'failed'     => false,
) as $status => $expected ) {
	$check( "instructions shown for $status", $renders_instructions( $status ), $expected );
}

// Never shown to the admin, whatever the status.
$order->set_status( 'on-hold' );
$order->save();
ob_start();
$cop->email_instructions( $order, true );
$check( 'withheld from the admin email', false !== strpos( ob_get_clean(), 'INSTRUCTIONS-MARKER' ), false );

// ...nor for an order paid by another gateway.
$order->set_payment_method( 'cod' );
$order->save();
ob_start();
$cop->email_instructions( $order, false );
$check( 'withheld for another payment method', false !== strpos( ob_get_clean(), 'INSTRUCTIONS-MARKER' ), false );

$order->delete( true );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
