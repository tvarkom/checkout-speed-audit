<?php
/**
 * WooCommerce → Speed Audit: the notice on the page and the form handler (includes/admin.php).
 *
 * @package CheckoutSpeedAudit
 */

/**
 * The admin page.
 */
class Tvarkom_CSA_Admin_Test extends Tvarkom_CSA_Test_Case {

	/**
	 * Clears the request.
	 */
	public function tear_down() {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * The rendered page.
	 *
	 * @return string
	 */
	private function render() {
		ob_start();
		tvarkom_csa_render_page();
		return (string) ob_get_clean();
	}

	/**
	 * The text of a notice, as rendered.
	 *
	 * @param string $code Key of tvarkom_csa_messages().
	 * @return string
	 */
	private function notice_text( $code ) {
		return esc_html( tvarkom_csa_messages()[ $code ][1] );
	}

	/**
	 * Submits the form as the signed-in user and returns the result code it redirects with.
	 *
	 * @param array $fields Form fields.
	 * @return string
	 */
	private function submit( array $fields ) {
		$_POST    = array_merge(
			array(
				'csa_email' => 'owner@example.org',
				'_wpnonce'  => wp_create_nonce( 'tvarkom_csa_form' ),
			),
			$fields
		);
		$_REQUEST = $_POST;
		return $this->result_of_redirect( 'tvarkom_csa_handle_form' );
	}

	public function test_the_page_shows_the_notice_for_the_result_code() {
		$this->sign_in_shop_manager();
		$_GET['csa_result'] = 'queued';

		$this->assertStringContainsString( $this->notice_text( 'queued' ), $this->render() );
	}

	public function test_a_pro_subscriber_is_never_offered_pro_again() {
		$this->sign_in_shop_manager();
		tvarkom_csa_update_settings( array( 'api_key' => 'tvk_test' ) );
		$this->respond(
			'GET',
			'/v1/client',
			200,
			array_merge(
				$this->client_status( true ),
				array(
					'license_expires_at' => '2027-10-28T12:00:00Z',
					'upgrade_url'        => null,
				)
			)
		);

		$page = $this->render();

		$this->assertStringContainsString( 'Plan: Pro, current subscription period until', $page );
		$this->assertStringContainsString( 'a Pro report is emailed to you every week', $page );
		$this->assertStringNotContainsString( 'Buy Pro', $page );
		$this->assertStringNotContainsString( 'audits left', $page );
	}

	public function test_a_free_store_is_offered_the_subscription() {
		$this->sign_in_shop_manager();
		tvarkom_csa_update_settings( array( 'api_key' => 'tvk_test' ) );
		$this->respond(
			'GET',
			'/v1/client',
			200,
			array_merge(
				$this->client_status( false ),
				array(
					'monthly_limit'       => 4,
					'audits_last_30_days' => 1,
					'upgrade_url'         => 'https://buy.stripe.com/test?client_reference_id=tvkx',
				)
			)
		);

		$page = $this->render();

		$this->assertStringContainsString( 'Buy Pro', $page );
		$this->assertStringContainsString( 'an annual subscription', $page );
		$this->assertStringNotContainsString( 'one-time', $page );
	}

	public function test_a_free_store_sees_no_pro_and_no_audit_count() {
		$this->sign_in_shop_manager();
		tvarkom_csa_update_settings( array( 'api_key' => 'tvk_test' ) );
		$_GET['csa_preview'] = '1';
		$_GET['_wpnonce']    = wp_create_nonce( 'tvarkom_csa_preview' );
		$this->respond(
			'GET',
			'/v1/client',
			200,
			array_merge(
				$this->client_status( false ),
				array(
					'monthly_limit'       => 4, // an older gateway's answer
					'audits_last_30_days' => 1,
					'upgrade_url'         => null,
				)
			)
		);

		$page = $this->render();
		unset( $_GET['csa_preview'], $_GET['_wpnonce'] );

		$this->assertStringContainsString( 'Plan: free.', $page );
		$this->assertStringContainsString( 'from the last 7 days', $page );
		$this->assertDoesNotMatchRegularExpression( '/\bPro\b|Buy |subscription|audits used|per 30 days|hello@/', wp_strip_all_tags( $page ) );
	}

	public function test_a_code_without_text_shows_the_server_notice() {
		$this->sign_in_shop_manager();
		$_GET['csa_result'] = 'upstream_error';

		$this->assertStringContainsString( $this->notice_text( 'server' ), $this->render() );
	}

	public function test_a_stored_code_without_text_shows_the_server_notice() {
		$this->sign_in_shop_manager();
		tvarkom_csa_update_settings( array( 'last' => array( 'code' => 'internal' ) ) );

		$this->assertStringContainsString( $this->notice_text( 'server' ), $this->render() );
	}

	public function test_no_result_shows_no_notice() {
		$this->sign_in_shop_manager();
		$this->assertStringNotContainsString( 'class="notice ', $this->render() );
	}

	public function test_the_page_is_empty_for_users_who_cannot_manage_woocommerce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( '', $this->render() );
	}

	public function test_the_form_refuses_users_who_cannot_manage_woocommerce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->expectException( WPDieException::class );
		$this->submit( array( 'csa_do' => 'save' ) );
	}

	public function test_the_form_refuses_a_missing_nonce() {
		$this->sign_in_shop_manager();
		$this->expectException( WPDieException::class );
		$this->submit( array( '_wpnonce' => 'nope' ) );
	}

	public function test_an_invalid_email_is_not_saved() {
		$this->sign_in_shop_manager();
		$this->assertSame( 'invalid_email', $this->submit( array( 'csa_email' => 'not-an-email' ) ) );
		$this->assertSame( get_option( 'admin_email' ), tvarkom_csa_settings()['email'] );
	}

	public function test_save_stores_the_email_and_consent_and_sends_nothing() {
		$this->sign_in_shop_manager();
		$this->assertSame(
			'saved',
			$this->submit(
				array(
					'csa_email'   => 'reports@example.org',
					'csa_consent' => '1',
					'csa_do'      => 'save',
				)
			)
		);
		$this->assertSame( 'reports@example.org', tvarkom_csa_settings()['email'] );
		$this->assertTrue( tvarkom_csa_settings()['consent'] );
		$this->assertSame( array(), $this->request_lines() );
	}

	public function test_a_click_while_an_audit_runs_sends_nothing() {
		$this->sign_in_shop_manager();
		tvarkom_csa_update_settings( array( 'api_key' => 'tvk_test' ) );
		tvarkom_csa_audit_lock();

		$this->assertSame( 'audit_running', $this->submit( array( 'csa_do' => 'audit' ) ) );
		$this->assertSame( array(), $this->request_lines() );
		$this->assertTrue( $this->lock_is_held(), 'the running audit keeps its lock' );
	}

	public function test_an_audit_runs_now_and_releases_the_lock() {
		$this->sign_in_shop_manager();
		tvarkom_csa_update_settings( array( 'api_key' => 'tvk_test' ) );
		$this->respond( 'GET', '/v1/client', 200, $this->client_status( false ) );
		$this->respond( 'POST', '/v1/audits', 202, array( 'status' => 'queued' ) );

		$this->assertSame( 'queued', $this->submit( array( 'csa_do' => 'audit' ) ) );
		$this->assertFalse( $this->lock_is_held() );
	}

	public function test_the_first_audit_registers_the_store() {
		$this->sign_in_shop_manager();
		$this->respond(
			'POST',
			'/v1/register',
			201,
			array(
				'registration_id' => 'reg_1',
				'token'           => 'tok_1',
			)
		);
		$this->respond( 'POST', '/v1/register/verify', 200, array( 'api_key' => 'tvk_new' ) );
		$this->respond( 'GET', '/v1/client', 200, $this->client_status( false ) );
		$this->respond( 'POST', '/v1/audits', 202, array( 'status' => 'queued' ) );

		$this->assertSame( 'queued', $this->submit( array( 'csa_do' => 'audit' ) ) );
		$this->assertSame( array( 'POST /v1/register', 'POST /v1/register/verify', 'GET /v1/client', 'POST /v1/audits' ), $this->request_lines() );
		$this->assertSame( 'Bearer tvk_new', $this->requests[3]['headers']['Authorization'] );
	}

	public function test_a_failed_registration_releases_the_lock() {
		$this->sign_in_shop_manager();
		$this->respond( 'POST', '/v1/register', 400, array( 'error' => 'invalid_website' ) );

		$this->assertSame( 'invalid_website', $this->submit( array( 'csa_do' => 'audit' ) ) );
		$this->assertFalse( $this->lock_is_held() );
	}

	public function test_a_license_key_is_checked_before_the_audit() {
		$this->sign_in_shop_manager();
		$this->assertSame(
			'license_invalid',
			$this->submit(
				array(
					'csa_license' => 'nope',
					'csa_do'      => 'audit',
				)
			)
		);
		$this->assertSame( array(), $this->request_lines() );
	}
}
