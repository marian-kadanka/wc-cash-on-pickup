<?php
/**
 * Puts every option bin/setup-site.php touched back to the value it captured, and deletes
 * any checkout page it had to create.
 *
 * Run from the WordPress root:  wp eval-file wp-content/plugins/wc-cash-on-pickup/tests/bin/restore-site.php
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

/**
 * The page ids bin/setup-site.php discovered or created for this run.
 *
 * @param array $config Parsed tests/config.json.
 * @return string
 */
function wc_cop_tests_state_file( $config ) {
	$base = ! empty( $config['artifactsDir'] ) ? $config['artifactsDir'] : sys_get_temp_dir() . '/wc-cop-tests';
	return $base . '/site-state.json';
}

$dir         = dirname( __DIR__ );
$config      = json_decode( file_get_contents( $dir . '/config.json' ), true );
$backup_file = wc_cop_tests_backup_file( $config );
$state_file  = wc_cop_tests_state_file( $config );

// A backup written by an older version of these tests lived inside the plugin; still honour it.
$legacy_file = $dir . '/.site-backup.json';
if ( ! file_exists( $backup_file ) && file_exists( $legacy_file ) ) {
	$backup_file = $legacy_file;
}

if ( ! file_exists( $backup_file ) ) {
	echo "No site backup found - nothing to restore.\n";
	exit( 1 );
}

$backup = json_decode( file_get_contents( $backup_file ), true );
foreach ( $backup as $option => $value ) {
	update_option( $option, $value );
	echo str_pad( $option, 52 ) . ' <- ' . ( is_scalar( $value ) ? $value : wp_json_encode( $value ) ) . "\n";
}

unlink( $backup_file );

// Pages the suite had to create because the store had none are not part of the store.
if ( file_exists( $state_file ) ) {
	$state = json_decode( file_get_contents( $state_file ), true );
	foreach ( ( isset( $state['createdPages'] ) ? $state['createdPages'] : array() ) as $page_id ) {
		if ( get_post_meta( $page_id, '_wc_cop_test_page', true ) ) {
			wp_delete_post( $page_id, true );
			echo str_pad( 'deleted page ' . $page_id, 52 ) . " (created by setup-site.php)\n";
		}
	}
	unlink( $state_file );
}

echo "restored, backup file removed.\n";
