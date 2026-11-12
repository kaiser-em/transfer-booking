<?php
/**
 * Tests for ETB_Ajax security gates.
 *
 * Handlers terminate through wp_send_json_* / wp_die(), which the WordPress
 * test harness turns into a WPDieException. We capture that to inspect the
 * JSON payload emitted before termination.
 */
class ETB_Ajax_Test extends WP_UnitTestCase {

	private $ajax;
	private $booking_id;

	public function set_up(): void {
		parent::set_up();

		$_SERVER['REMOTE_ADDR'] = '8.8.8.8';
		$_POST = array();

		$this->ajax       = new ETB_Ajax();
		$this->booking_id = $this->factory->post->create( array(
			'post_type'  => 'tour_booking',
			'post_title' => 'Test Booking',
		) );

		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_etb_rl_%'
			    OR option_name LIKE '_transient_timeout_etb_rl_%'"
		);
	}

	/**
	 * @return array{output:string,died:bool}
	 */
	private function invoke( $method ) {
		$output = '';
		$died   = false;

		ob_start();
		try {
			$this->ajax->$method();
		} catch ( WPDieException $e ) {
			$died = true;
		} finally {
			$output = ob_get_clean();
		}

		return array( 'output' => $output, 'died' => $died );
	}

	public function test_quick_pricing_rejects_missing_nonce() {
		$_POST = array( 'action' => 'etb_quick_pricing' );

		$result = $this->invoke( 'handle_quick_pricing' );

		$this->assertTrue( $result['died'], 'Handler should terminate on invalid nonce.' );
		$this->assertStringNotContainsString( '"success":true', $result['output'] );
	}

	public function test_quick_pricing_rejects_incomplete_coordinates() {
		$_POST = array(
			'action'   => 'etb_quick_pricing',
			'nonce'    => wp_create_nonce( 'etb_booking_nonce' ),
			'from_lat' => '0',
			'from_lng' => '0',
			'to_lat'   => '0',
			'to_lng'   => '0',
		);

		$result = $this->invoke( 'handle_quick_pricing' );

		$this->assertTrue( $result['died'] );
		$this->assertStringContainsString( '"success":false', $result['output'] );
		$this->assertStringContainsString( 'GPS', $result['output'] );
	}

	public function test_submit_checkout_rejects_missing_nonce() {
		$_POST = array( 'action' => 'etb_submit_checkout' );

		$result = $this->invoke( 'handle_submit_checkout' );

		$this->assertTrue( $result['died'] );
		$this->assertStringNotContainsString( '"success":true', $result['output'] );
	}

	public function test_create_quote_rejects_missing_nonce() {
		$_POST = array( 'action' => 'etb_create_quote' );

		$result = $this->invoke( 'handle_create_quote' );

		$this->assertTrue( $result['died'] );
		$this->assertStringNotContainsString( '"success":true', $result['output'] );
	}

	public function test_resync_booking_rejects_missing_nonce() {
		$_POST = array(
			'action'     => 'etb_resync_booking',
			'booking_id' => $this->booking_id,
		);

		$result = $this->invoke( 'handle_resync_booking' );

		$this->assertTrue( $result['died'] );
		$this->assertStringNotContainsString( '"success":true', $result['output'] );
	}

	public function test_resync_booking_requires_capability() {
		$_POST = array(
			'action'     => 'etb_resync_booking',
			'nonce'      => wp_create_nonce( 'etb_booking_nonce' ),
			'booking_id' => $this->booking_id,
		);

		$result = $this->invoke( 'handle_resync_booking' );

		$this->assertTrue( $result['died'] );
		$this->assertStringContainsString( 'Autorisation refus', $result['output'] );
	}

	public function tear_down(): void {
		wp_delete_post( $this->booking_id, true );
		$_POST = array();

		parent::tear_down();
	}
}