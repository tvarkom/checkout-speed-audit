<?php
/**
 * Gateway client: plan status, license keys and registration (includes/api.php).
 *
 * @package CheckoutSpeedAudit
 */

/**
 * The gateway client.
 */
class Tvarkom_CSA_Api_Test extends Tvarkom_CSA_Test_Case {

	const LICENSE = 'tvk_abcdefghijklmnopqrstuvwxyz';

	public function test_status_of_an_unregistered_store_asks_nothing() {
		$this->assertSame( 'not_registered', tvarkom_csa_status() );
		$this->assertSame( array(), $this->request_lines() );
	}

	/**
	 * Status answers.
	 *
	 * @return array<string, array{0: int|WP_Error, 1: mixed, 2: string}>
	 */
	public function data_status_errors() {
		return array(
			'revoked key'     => array( 401, array( 'error' => 'invalid_key' ), 'invalid_key' ),
			'no JSON'         => array( 502, '<html>', 'server' ),
			'200 but no JSON' => array( 200, 'ok', 'server' ),
			'network error'   => array( new WP_Error( 'http_request_failed', 'down' ), null, 'network' ),
		);
	}

	/**
	 * The status call reduces failures to an error code.
	 *
	 * @dataProvider data_status_errors
	 *
	 * @param int|WP_Error $status Gateway status or transport error.
	 * @param mixed        $body   Gateway body.
	 * @param string       $code   Expected code.
	 */
	public function test_status_errors( $status, $body, $code ) {
		$this->respond( 'GET', '/v1/client', $status, $body );
		$this->assertSame( $code, tvarkom_csa_status( 'tvk_test' ) );
	}

	public function test_status_sends_the_key() {
		tvarkom_csa_update_settings( array( 'api_key' => 'tvk_stored' ) );
		$this->respond( 'GET', '/v1/client', 200, $this->client_status( true ) );

		$this->assertSame( $this->client_status( true ), tvarkom_csa_status() );
		$this->assertSame( 'Bearer tvk_stored', $this->requests[0]['headers']['Authorization'] );
	}

	public function test_a_malformed_license_is_refused_without_asking_the_gateway() {
		$this->assertSame( 'license_invalid', tvarkom_csa_save_license( 'tvk_short' ) );
		$this->assertSame( 'license_invalid', tvarkom_csa_save_license( 'abc_abcdefghijklmnopqrstuvwxyz' ) );
		$this->assertSame( array(), $this->request_lines() );
	}

	public function test_a_license_the_gateway_rejects_is_invalid() {
		$this->respond( 'GET', '/v1/client', 401, array( 'error' => 'invalid_key' ) );
		$this->assertSame( 'license_invalid', tvarkom_csa_save_license( self::LICENSE ) );
	}

	public function test_a_license_for_another_store_is_not_saved() {
		tvarkom_csa_update_settings( array( 'api_key' => 'tvk_current' ) );
		$status           = $this->client_status( true );
		$status['origin'] = 'https://another-store.example';
		$this->respond( 'GET', '/v1/client', 200, $status );

		$this->assertSame( 'license_other_store', tvarkom_csa_save_license( self::LICENSE ) );
		$this->assertSame( 'tvk_current', tvarkom_csa_settings()['api_key'] );
	}

	public function test_a_license_for_this_store_is_saved_with_its_plan() {
		$status           = $this->client_status( true );
		$status['origin'] = 'https://www.' . strtoupper( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ); // www. and case don't make it another store.
		$this->respond( 'GET', '/v1/client', 200, $status );

		$this->assertSame( 'license_saved', tvarkom_csa_save_license( self::LICENSE ) );
		$this->assertSame( self::LICENSE, tvarkom_csa_settings()['api_key'] );
		$this->assertSame( 'pro', tvarkom_csa_settings()['plan'] );
		$this->assertSame( 'Bearer ' . self::LICENSE, $this->requests[0]['headers']['Authorization'] );
	}

	public function test_registration_proves_the_domain_and_stores_the_key() {
		$this->respond(
			'POST',
			'/v1/register',
			201,
			array(
				'registration_id' => 'reg_1',
				'token'           => 'tok_1',
			)
		);
		$token_while_verifying = null;
		$this->respond(
			'POST',
			'/v1/register/verify',
			function () use ( &$token_while_verifying ) {
				// The gateway fetches the verify URL now; the token must be there.
				$token_while_verifying = get_transient( TVARKOM_CSA_TOKEN );
				return array(
					200,
					array(
						'api_key' => 'tvk_new',
						'plan'    => 'free',
					),
				);
			}
		);

		$this->assertSame( '', tvarkom_csa_register( 'owner@example.org' ) );

		$this->assertSame(
			array(
				'site_url'   => home_url( '/' ),
				'email'      => 'owner@example.org',
				'verify_url' => rest_url( 'tvarkom/v1/verify' ),
			),
			$this->requests[0]['body']
		);
		$this->assertArrayNotHasKey( 'Authorization', $this->requests[0]['headers'] );
		$this->assertSame( array( 'registration_id' => 'reg_1' ), $this->requests[1]['body'] );
		$this->assertSame( 'tok_1', $token_while_verifying );
		$this->assertFalse( get_transient( TVARKOM_CSA_TOKEN ), 'token removed after use' );
		$this->assertSame( 'tvk_new', tvarkom_csa_settings()['api_key'] );
		$this->assertSame( 'free', tvarkom_csa_settings()['plan'] );
	}

	public function test_a_failed_verification_stores_no_key() {
		$this->respond(
			'POST',
			'/v1/register',
			201,
			array(
				'registration_id' => 'reg_1',
				'token'           => 'tok_1',
			)
		);
		$this->respond( 'POST', '/v1/register/verify', 422, array( 'error' => 'verification_failed' ) );

		$this->assertSame( 'verification_failed', tvarkom_csa_register( 'owner@example.org' ) );
		$this->assertFalse( get_transient( TVARKOM_CSA_TOKEN ) );
		$this->assertSame( '', tvarkom_csa_settings()['api_key'] );
	}

	public function test_a_refused_registration_stops_before_verification() {
		$this->respond( 'POST', '/v1/register', 429, array( 'error' => 'rate_limited' ) );

		$this->assertSame( 'rate_limited', tvarkom_csa_register( 'owner@example.org' ) );
		$this->assertSame( array( 'POST /v1/register' ), $this->request_lines() );
	}
}
