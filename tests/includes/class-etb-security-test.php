<?php
/**
 * Tests for ETB_Security
 */
class ETB_Security_Test extends WP_UnitTestCase {

	private $original_remote_addr;
	private $original_xff;

	public function set_up(): void {
		parent::set_up();

		$this->original_remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
		$this->original_xff         = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;

		$_SERVER['REMOTE_ADDR'] = '8.8.8.8';
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP'] );
		$_POST = array();

		$this->clear_rate_limit_transients();
		delete_transient( 'etb_honeypot_field_' . md5( ETB_Security::get_client_ip() ) );
		delete_transient( 'etb_rl_promo_fail_' . md5( ETB_Security::get_client_ip() ) );
	}

	public function tear_down(): void {
		delete_transient( 'etb_honeypot_field_' . md5( ETB_Security::get_client_ip() ) );
		delete_transient( 'etb_rl_promo_fail_' . md5( ETB_Security::get_client_ip() ) );
		$this->clear_rate_limit_transients();

		$_POST = array();
		if ( null === $this->original_remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->original_remote_addr;
		}
		if ( null === $this->original_xff ) {
			unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		} else {
			$_SERVER['HTTP_X_FORWARDED_FOR'] = $this->original_xff;
		}

		parent::tear_down();
	}

	private function clear_rate_limit_transients() {
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_etb_rl_%'
			    OR option_name LIKE '_transient_timeout_etb_rl_%'"
		);
	}

	public function test_get_client_ip_returns_public_remote_addr() {
		$this->assertSame( '8.8.8.8', ETB_Security::get_client_ip() );
	}

	public function test_get_client_ip_ignores_forwarded_header_from_untrusted_proxy() {
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

		$this->assertSame( '8.8.8.8', ETB_Security::get_client_ip() );
	}

	public function test_get_client_ip_uses_forwarded_header_from_trusted_proxy() {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.4.4, 10.0.0.5';

		add_filter( 'etb_trusted_proxies', function () {
			return array( '10.0.0.5' );
		} );

		$this->assertSame( '8.8.4.4', ETB_Security::get_client_ip() );
	}

	public function test_get_client_ip_preserves_private_remote_addr() {
		$_SERVER['REMOTE_ADDR'] = '192.168.1.50';

		$this->assertSame( '192.168.1.50', ETB_Security::get_client_ip() );
	}

	public function test_get_client_ip_falls_back_to_loopback_for_invalid_value() {
		$_SERVER['REMOTE_ADDR'] = 'not-an-ip';

		$this->assertSame( '127.0.0.1', ETB_Security::get_client_ip() );
	}

	public function test_rate_limit_allows_requests_under_limit() {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->assertTrue( ETB_Security::check_rate_limit( 'unit_test_rate', 5, 60 ) );
		}
	}

	public function test_rate_limit_blocks_over_limit() {
		for ( $i = 1; $i <= 5; $i++ ) {
			ETB_Security::check_rate_limit( 'unit_test_rate', 5, 60 );
		}

		$this->assertFalse( ETB_Security::check_rate_limit( 'unit_test_rate', 5, 60 ) );
	}

	public function test_rate_limit_isolated_per_action() {
		for ( $i = 1; $i <= 5; $i++ ) {
			ETB_Security::check_rate_limit( 'unit_test_rate_a', 5, 60 );
		}

		$this->assertTrue( ETB_Security::check_rate_limit( 'unit_test_rate_b', 5, 60 ) );
	}

	public function test_honeypot_generation_and_verification() {
		$field_name = ETB_Security::generate_honeypot_field();

		$this->assertNotEmpty( $field_name );
		$this->assertStringStartsWith( 'etb_hp_', $field_name );

		$this->assertTrue( ETB_Security::verify_honeypot(), 'Empty honeypot should pass.' );

		$_POST[ $field_name ] = 'spam';
		$this->assertFalse( ETB_Security::verify_honeypot(), 'Filled honeypot should fail.' );
	}

	public function test_timestamp_token_accepts_aged_valid_token() {
		$time  = time() - 10;
		$token = wp_hash( $time . '|' . ETB_Security::TIME_SALT, 'nonce' );

		$this->assertTrue( ETB_Security::verify_timestamp_token( $time, $token, 3 ) );
	}

	public function test_timestamp_token_rejects_too_fast_submission() {
		$token_data = ETB_Security::generate_timestamp_token();

		$this->assertFalse( ETB_Security::verify_timestamp_token( $token_data['time'], $token_data['token'], 3 ) );
	}

	public function test_timestamp_token_rejects_invalid_signature() {
		$this->assertFalse( ETB_Security::verify_timestamp_token( time() - 10, 'invalid-signature', 3 ) );
	}

	public function test_timestamp_token_tolerates_missing_fields() {
		$this->assertTrue( ETB_Security::verify_timestamp_token( '', '', 3 ) );
	}

	public function test_payment_signature() {
		$booking_id = 123;
		$amount     = 150.50;
		$signature  = ETB_Security::create_payment_signature( $booking_id, $amount );

		$this->assertTrue( ETB_Security::verify_payment_signature( $booking_id, $amount, $signature ) );
		$this->assertFalse( ETB_Security::verify_payment_signature( $booking_id, 100.00, $signature ) );
		$this->assertFalse( ETB_Security::verify_payment_signature( 124, $amount, $signature ) );
	}

	public function test_promo_bruteforce_blocking() {
		$this->assertFalse( ETB_Security::is_promo_bruteforce_blocked() );

		for ( $i = 0; $i < 4; $i++ ) {
			ETB_Security::record_failed_promo_attempt();
		}
		$this->assertFalse( ETB_Security::is_promo_bruteforce_blocked(), 'Should still be allowed before the 5th failure.' );

		ETB_Security::record_failed_promo_attempt();
		$this->assertTrue( ETB_Security::is_promo_bruteforce_blocked(), 'Should be blocked at 5 failures.' );
	}
}
