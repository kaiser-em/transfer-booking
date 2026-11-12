<?php
/**
 * Tests for ETB_Pricing_Engine
 */
class ETB_Pricing_Engine_Test extends WP_UnitTestCase {

	private $circuit_id;
	private $option_id = 'option_123';

	public function set_up(): void {
		parent::set_up();

		$this->circuit_id = $this->factory->post->create( array( 'post_type' => 'tour_circuit' ) );

		update_post_meta( $this->circuit_id, '_circuit_options_data', array(
			$this->option_id => array(
				'duration_hours'   => 2.5,
				'additional_price' => 50.0,
				'city_name'        => 'Nice',
			),
		) );

		delete_transient( 'etb_circuit_option_' . md5( $this->option_id ) );
	}

	public function tear_down(): void {
		delete_transient( 'etb_circuit_option_' . md5( $this->option_id ) );
		wp_delete_post( $this->circuit_id, true );

		parent::tear_down();
	}

	public function test_find_circuit_option_data_with_circuit_id() {
		$result = ETB_Pricing_Engine::find_circuit_option_data( $this->option_id, $this->circuit_id );

		$this->assertIsArray( $result );
		$this->assertSame( $this->circuit_id, $result['circuit_id'] );
		$this->assertSame( 'Nice', $result['city_name'] );
	}

	public function test_find_circuit_option_data_without_circuit_id_uses_sql_and_cache() {
		$result = ETB_Pricing_Engine::find_circuit_option_data( $this->option_id );

		$this->assertIsArray( $result );
		$this->assertSame( $this->circuit_id, $result['circuit_id'] );
		$this->assertSame( 'Nice', $result['city_name'] );

		// The result must now be cached in a transient.
		$this->assertNotFalse( get_transient( 'etb_circuit_option_' . md5( $this->option_id ) ) );
	}

	public function test_find_circuit_option_data_returns_null_for_unknown_option() {
		$this->assertNull( ETB_Pricing_Engine::find_circuit_option_data( 'unknown_option' ) );
	}

	public function test_find_circuit_option_data_returns_null_for_empty_option() {
		$this->assertNull( ETB_Pricing_Engine::find_circuit_option_data( '' ) );
	}

	public function test_calculate_vehicle_price() {
		$vehicle_id = $this->factory->post->create( array( 'post_type' => 'tour_vehicle' ) );
		update_post_meta( $vehicle_id, '_etb_hourly_rate', 50.0 );
		update_post_meta( $vehicle_id, '_etb_base_price', 100.0 );
		update_post_meta( $vehicle_id, '_etb_pack_10h', 400.0 );
		update_post_meta( $vehicle_id, '_etb_sup_hour_rate', 30.0 );

		$this->assertEquals( 75.0, ETB_Pricing_Engine::calculate_vehicle_price( $vehicle_id, 1.5 ) );
		$this->assertEquals( 400.0, ETB_Pricing_Engine::calculate_vehicle_price( $vehicle_id, 10 ) );
		$this->assertEquals( 430.0, ETB_Pricing_Engine::calculate_vehicle_price( $vehicle_id, 11 ) );
	}

	public function test_calculate_vehicle_price_falls_back_to_base_price() {
		$vehicle_id = $this->factory->post->create( array( 'post_type' => 'tour_vehicle' ) );
		update_post_meta( $vehicle_id, '_etb_base_price', 40.0 );

		$this->assertEquals( 80.0, ETB_Pricing_Engine::calculate_vehicle_price( $vehicle_id, 2 ) );
	}

	public function test_calculate_total_includes_vehicle_and_circuit_price() {
		$vehicle_id = $this->factory->post->create( array( 'post_type' => 'tour_vehicle' ) );
		update_post_meta( $vehicle_id, '_etb_hourly_rate', 50.0 );

		$engine = new ETB_Pricing_Engine();
		$result = $engine->calculate_total( array(
			'option_id'  => $this->option_id,
			'circuit_id' => $this->circuit_id,
			'vehicles'   => array( $vehicle_id => 1 ),
			'pickup_id'  => 0,
			'extras'     => array(),
			'promo'      => '',
		) );

		// 50 / hour * 2.5 hours = 125, plus 50 circuit supplement.
		$this->assertEquals( 125.0, $result['vehicles_total'] );
		$this->assertEquals( 50.0, $result['circuit_additional_price'] );
		$this->assertEquals( 175.0, $result['grand_total'] );
	}

	public function test_calculate_total_applies_fixed_promo_discount() {
		$vehicle_id = $this->factory->post->create( array( 'post_type' => 'tour_vehicle' ) );
		update_post_meta( $vehicle_id, '_etb_hourly_rate', 50.0 );

		$promo_id = $this->factory->post->create( array(
			'post_type'   => 'tour_promo',
			'post_title'  => 'SUMMER20',
			'post_status' => 'publish',
		) );
		update_post_meta( $promo_id, '_etb_promo_active', '1' );
		update_post_meta( $promo_id, '_etb_discount_type', 'fixed' );
		update_post_meta( $promo_id, '_etb_discount_value', 20 );

		$engine = new ETB_Pricing_Engine();
		$result = $engine->calculate_total( array(
			'option_id'  => $this->option_id,
			'circuit_id' => $this->circuit_id,
			'vehicles'   => array( $vehicle_id => 1 ),
			'pickup_id'  => 0,
			'extras'     => array(),
			'promo'      => 'SUMMER20',
		) );

		$this->assertEquals( 20.0, $result['discount_amount'] );
		$this->assertEquals( 'SUMMER20', $result['promo_code'] );
		$this->assertEquals( 155.0, $result['grand_total'] );
	}
}
