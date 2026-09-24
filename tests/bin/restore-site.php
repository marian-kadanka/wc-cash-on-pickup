<?php
/**
 * Puts every option bin/setup-site.php touched back to the value it captured.
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

$dir         = dirname( __DIR__ );
$config      = json_decode( file_get_contents( $dir . '/config.json' ), true );
$backup_file = wc_cop_tests_backup_file( $config );

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
echo "restored, backup file removed.\n";
