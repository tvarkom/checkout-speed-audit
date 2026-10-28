<?php
/**
 * POST /wp-json/tvarkom/v1/monitor, the weekly Pro report (includes/monitor.php).
 *
 * @package CheckoutSpeedAudit
 */

/**
 * The gateway's signed request: who may call, replays, and what is sent and answered.
 */
class Tvarkom_CSA_Monitor_Test extends Tvarkom_CSA_Test_Case {

	/**
	 * Fires rest_api_init on a fresh server, for a registered store with consent.
	 */
	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh REST server per test.
		do_action( 'rest_api_init', $wp_rest_server );
		tvarkom_csa_update_settings(
			array(
				'api_key' => 'tvk_test',
				'email'   => 'reports@example.org',
				'consent' => true,
				'last'    => array( 'code' => 'queued' ),
			)
		);
		delete_option( TVARKOM_CSA_MONITOR_SEEN );
	}

	/**
	 * Removes the server and the lock.
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- See set_up().
		tvarkom_csa_audit_unlock();
		parent::tear_down();
	}

	/**
	 * Sends the gateway's request, signed with $key's hash unless $signature is given.
	 *
	 * @param array       $fields    Body fields over the defaults.
	 * @param string      $key       The key to sign with.
	 * @param string|null $signature A header value instead of the real signature.
	 * @return WP_REST_Response
	 */
	private function monitor( array $fields = array(), $key = 'tvk_test', $signature = null ) {
		$body    = wp_json_encode(
			array_merge(
				array(
					'v'       => 1,
					'store'   => untrailingslashit( home_url() ),
					'attempt' => 41,
					'ts'      => time(),
				),
				$fields
			)
		);
		$request = new WP_REST_Request( 'POST', '/tvarkom/v1/monitor' );
		$request->set_body( $body );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-Tvarkom-Signature', null === $signature ? 'v1=' . hash_hmac( 'sha256', $body, hash( 'sha256', $key, true ) ) : $signature );
		return rest_do_request( $request );
	}

	public function test_a_signed_request_sends_the_pro_audit_and_answers_its_code_only() {
		$this->respond(
			'POST',
			'/v1/audits',
			202,
			array(
				'status'   => 'queued',
				'detailed' => true,
			)
		);

		$response = $this->monitor();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'code' => 'queued_detailed' ), $response->get_data(), 'no store data in the answer' );
		$this->assertSame( array( 'POST /v1/audits' ), $this->request_lines(), 'the plan is the gateway\'s to decide' );
		$body = $this->requests[0]['body'];
		$this->assertSame( 41, $body['monitor_attempt'] );
		$this->assertSame( 'reports@example.org', $body['email'] );
		$this->assertSame( home_url( '/' ), $body['website'] );
		$this->assertArrayHasKey( 'metrics', $body );
		$this->assertArrayHasKey( 'detailed', $body, 'the same data as a Pro audit the admin runs' );
		$this->assertSame( wc_get_checkout_url(), $body['pages']['checkout'] );
		$this->assertSame( 'Bearer tvk_test', $this->requests[0]['headers']['Authorization'] );
		$this->assertSame( 'queued', tvarkom_csa_settings()['last']['code'], 'the admin page\'s last result is left alone' );
		$this->assertFalse( (bool) get_option( TVARKOM_CSA_AUDIT_LOCK ), 'the lock is released' );
	}

	public function test_without_consent_no_store_data_is_sent() {
		tvarkom_csa_update_settings( array( 'consent' => false ) );
		$this->respond( 'POST', '/v1/audits', 400, array( 'error' => 'consent_required' ) );

		$response = $this->monitor();

		$this->assertSame( array( 'code' => 'consent_required' ), $response->get_data() );
		$this->assertArrayNotHasKey( 'detailed', $this->requests[0]['body'] );
		$this->assertArrayNotHasKey( 'metrics', $this->requests[0]['body'] );
	}

	/**
	 * Requests that must be refused before anything is collected or sent.
	 *
	 * @return array
	 */
	public function data_refused() {
		return array(
			'another key'       => array( array(), 'tvk_other', null, 401, 'invalid_signature' ),
			'no signature'      => array( array(), 'tvk_test', '', 401, 'invalid_signature' ),
			'malformed'         => array( array(), 'tvk_test', 'v1=xyz', 401, 'invalid_signature' ),
			'another store'     => array( array( 'store' => 'https://other.example' ), 'tvk_test', null, 403, 'wrong_store' ),
			'stale'             => array( array( 'ts' => time() - 600 ), 'tvk_test', null, 401, 'stale' ),
			'from the future'   => array( array( 'ts' => time() + 600 ), 'tvk_test', null, 401, 'stale' ),
			'no attempt'        => array( array( 'attempt' => 0 ), 'tvk_test', null, 400, 'invalid_request' ),
			'attempt as string' => array( array( 'attempt' => '41' ), 'tvk_test', null, 400, 'invalid_request' ),
			'unknown version'   => array( array( 'v' => 2 ), 'tvk_test', null, 400, 'invalid_request' ),
		);
	}

	/**
	 * Nothing is sent for a refused request.
	 *
	 * @dataProvider data_refused
	 *
	 * @param array       $fields    Body fields.
	 * @param string      $key       Signing key.
	 * @param string|null $signature Header instead of the signature.
	 * @param int         $status    Expected HTTP status.
	 * @param string      $code      Expected code.
	 */
	public function test_refused( $fields, $key, $signature, $status, $code ) {
		$response = $this->monitor( $fields, $key, $signature );

		$this->assertSame( $status, $response->get_status() );
		$this->assertSame( array( 'code' => $code ), $response->get_data() );
		$this->assertSame( array(), $this->request_lines() );
	}

	public function test_an_unregistered_store_refuses() {
		tvarkom_csa_update_settings( array( 'api_key' => '' ) );

		$response = $this->monitor( array(), '' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( array(), $this->request_lines() );
	}

	public function test_a_replayed_or_older_attempt_is_refused() {
		$this->respond(
			'POST',
			'/v1/audits',
			202,
			array(
				'status'   => 'queued',
				'detailed' => true,
			)
		);
		$this->assertSame( 200, $this->monitor()->get_status() );

		foreach ( array( 41, 40 ) as $attempt ) {
			$response = $this->monitor( array( 'attempt' => $attempt ) );
			$this->assertSame( 409, $response->get_status() );
			$this->assertSame( array( 'code' => 'replay' ), $response->get_data() );
		}
		$this->assertCount( 1, $this->requests );
	}

	public function test_waits_for_an_audit_already_running() {
		$this->assertTrue( tvarkom_csa_audit_lock() );

		$response = $this->monitor();

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( array( 'code' => 'audit_running' ), $response->get_data() );
		$this->assertSame( array(), $this->request_lines() );
		$this->assertSame( 0, (int) get_option( TVARKOM_CSA_MONITOR_SEEN, 0 ), 'the attempt can still be answered' );
	}

	public function test_only_post_is_routed() {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/tvarkom/v1/monitor' ) );
		$this->assertSame( 404, $response->get_status() );
	}
}
