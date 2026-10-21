<?php
/**
 * GET /wp-json/tvarkom/v1/verify (includes/rest.php).
 *
 * @package CheckoutSpeedAudit
 */

/**
 * The domain check the gateway fetches during registration.
 */
class Tvarkom_CSA_Rest_Test extends Tvarkom_CSA_Test_Case {

	/**
	 * Fires rest_api_init on a fresh server.
	 */
	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh REST server per test.
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Removes the server.
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- See set_up().
		parent::tear_down();
	}

	public function test_no_registration_in_progress_is_a_404() {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/tvarkom/v1/verify' ) );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'tvarkom_csa_no_registration', $response->get_data()['code'] );
	}

	public function test_returns_the_pending_token_to_anyone() {
		wp_set_current_user( 0 );
		set_transient( TVARKOM_CSA_TOKEN, 'tok_1', MINUTE_IN_SECONDS );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/tvarkom/v1/verify' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'token' => 'tok_1' ), $response->get_data() );
	}

	public function test_only_get_is_routed() {
		set_transient( TVARKOM_CSA_TOKEN, 'tok_1', MINUTE_IN_SECONDS );
		$response = rest_do_request( new WP_REST_Request( 'POST', '/tvarkom/v1/verify' ) );
		$this->assertSame( 404, $response->get_status() );
	}
}
