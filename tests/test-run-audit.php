<?php
/**
 * One audit: what is sent, with and without consent, and the stored outcome (includes/audit.php).
 *
 * @package CheckoutSpeedAudit
 */

/**
 * The audit request and its result codes.
 */
class Tvarkom_CSA_Run_Audit_Test extends Tvarkom_CSA_Test_Case {

	/**
	 * A registered store on the given plan, with or without consent.
	 *
	 * @param bool $pro     Pro audits left.
	 * @param bool $consent Store data allowed.
	 */
	private function store( $pro, $consent ) {
		tvarkom_csa_update_settings(
			array(
				'api_key' => 'tvk_test',
				'email'   => 'owner@example.org',
				'consent' => $consent,
			)
		);
		$this->respond( 'GET', '/v1/client', 200, $this->client_status( $pro ) );
	}

	/**
	 * The body of the POST /v1/audits request.
	 *
	 * @return array
	 */
	private function audit_body() {
		foreach ( $this->requests as $request ) {
			if ( 'POST /v1/audits' === $request['method'] . ' ' . $request['path'] ) {
				return $request['body'];
			}
		}
		$this->fail( 'No audit was requested.' );
	}

	public function test_without_a_key_nothing_is_sent() {
		tvarkom_csa_run_audit();

		$this->assertSame( array(), $this->request_lines() );
		$this->assertSame( 'invalid_key', tvarkom_csa_settings()['last']['code'] );
	}

	public function test_free_audit_without_consent_sends_only_the_store_addresses() {
		$this->store( false, false );
		$this->respond( 'POST', '/v1/audits', 202, array( 'status' => 'queued' ) );

		tvarkom_csa_run_audit();

		$this->assertSame(
			array(
				'website' => home_url( '/' ),
				'email'   => 'owner@example.org',
				'pages'   => array(
					'cart'     => wc_get_cart_url(),
					'checkout' => wc_get_checkout_url(),
				),
			),
			$this->audit_body()
		);
		$this->assertSame( 'Bearer tvk_test', $this->requests[1]['headers']['Authorization'] );
		$this->assertSame( 'queued', tvarkom_csa_settings()['last']['code'] );
	}

	public function test_free_audit_with_consent_adds_the_metrics_only() {
		$this->store( false, true );
		$this->respond( 'POST', '/v1/audits', 202, array( 'status' => 'queued' ) );

		tvarkom_csa_run_audit();

		$body = $this->audit_body();
		$this->assertArrayHasKey( 'metrics', $body );
		$this->assertArrayNotHasKey( 'detailed', $body );
	}

	public function test_pro_audit_without_consent_is_refused_before_it_is_sent() {
		$this->store( true, false );

		tvarkom_csa_run_audit();

		$this->assertSame( array( 'GET /v1/client' ), $this->request_lines(), 'no Pro audit used' );
		$this->assertSame( 'consent_required', tvarkom_csa_settings()['last']['code'] );
	}

	public function test_pro_audit_with_consent_sends_the_diagnostics_and_a_checkout_profile() {
		$this->store( true, true );
		$this->respond( 'POST', '/v1/audits', 202, array( 'detailed' => true ) );

		tvarkom_csa_run_audit();

		$body = $this->audit_body();
		$this->assertArrayHasKey( 'metrics', $body );
		$this->assertSame( 2, $body['detailed']['schema'] );
		// The store's own checkout is offline in the tests: the profile says why there is none.
		$this->assertSame(
			array(
				'status'      => 'unavailable',
				'reason'      => 'request_failed',
				'http_status' => null,
			),
			$body['detailed']['checkout_profile']
		);
		$this->assertFalse( get_transient( TVARKOM_CSA_PROFILE_TOKEN ), 'the single-use token is gone' );
		$this->assertSame( 'queued_detailed', tvarkom_csa_settings()['last']['code'] );
	}

	/**
	 * Gateway answers and what the plugin makes of them.
	 *
	 * @return array<string, array{0: int|WP_Error, 1: mixed, 2: string, 3: bool}>
	 */
	public function data_gateway_answers() {
		return array(
			'network error'        => array( new WP_Error( 'http_request_failed', 'down' ), null, 'network', true ),
			'invalid key'          => array( 401, array( 'error' => 'invalid_key' ), 'invalid_key', false ),
			'store address moved'  => array( 403, array( 'error' => 'website_not_allowed' ), 'website_not_allowed', false ),
			'free limit reached'   => array( 429, array( 'error' => 'limit_reached' ), 'limit_reached', true ),
			'not WooCommerce'      => array( 422, array( 'error' => 'not_woocommerce' ), 'not_woocommerce', true ),
			'error without a code' => array( 500, 'oops', 'server', true ),
		);
	}

	/**
	 * Stores the result code; drops the key only when the gateway no longer accepts it.
	 *
	 * @dataProvider data_gateway_answers
	 *
	 * @param int|WP_Error $status   Gateway status or transport error.
	 * @param mixed        $body     Gateway body.
	 * @param string       $code     Stored code.
	 * @param bool         $key_kept Whether the API key stays.
	 */
	public function test_gateway_answers( $status, $body, $code, $key_kept ) {
		$this->store( false, false );
		$this->respond( 'POST', '/v1/audits', $status, $body );

		tvarkom_csa_run_audit();

		$settings = tvarkom_csa_settings();
		$this->assertSame( $code, $settings['last']['code'] );
		$this->assertSame( $key_kept ? 'tvk_test' : '', $settings['api_key'] );
	}

	public function test_a_reused_pro_report_says_when_it_was_generated() {
		$this->store( true, true );
		$this->respond(
			'POST',
			'/v1/audits',
			202,
			array(
				'status'           => 'cached',
				'detailed'         => true,
				'report_id'        => 9,
				'collected_at'     => 1793000000,
				'next_eligible_at' => 1793600000,
			)
		);

		$this->assertSame( 'cached_pro', tvarkom_csa_run_audit() );

		$last = tvarkom_csa_settings()['last'];
		$this->assertSame( 'cached_pro', $last['code'] );
		$this->assertSame( 1793000000, $last['collected_at'] );
		$this->assertSame( 1793600000, $last['next_eligible_at'] );
		$messages = tvarkom_csa_messages();
		$text     = tvarkom_csa_notice_text( 'cached_pro', $messages['cached_pro'][1], $last );
		$this->assertStringContainsString( 'no new check was run', $text );
		$this->assertStringContainsString( wp_date( get_option( 'date_format' ), 1793000000 ), $text );
		$this->assertStringContainsString( wp_date( get_option( 'date_format' ), 1793600000 ), $text );
	}

	public function test_a_report_in_progress_is_not_a_new_audit() {
		$this->store( true, true );
		$this->respond(
			'POST',
			'/v1/audits',
			202,
			array(
				'status'   => 'in_progress',
				'detailed' => false,
			)
		);

		$this->assertSame( 'in_progress', tvarkom_csa_run_audit() );
		$this->assertSame( 'in_progress', tvarkom_csa_settings()['last']['code'] );
	}
}
