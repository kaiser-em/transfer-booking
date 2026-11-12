<?php
/**
 * PHPUnit bootstrap for the WordPress test suite.
 */

define( 'ETB_TESTS', true );

$etb_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $etb_tests_dir ) {
	$etb_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}
$etb_tests_dir = rtrim( $etb_tests_dir, '/\\' );

require_once $etb_tests_dir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', function () {
	require dirname( __DIR__ ) . '/elite-transfer-booking.php';
} );

require $etb_tests_dir . '/includes/bootstrap.php';
