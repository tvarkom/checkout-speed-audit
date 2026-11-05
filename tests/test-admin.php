<?php
/**
 * WooCommerce → Checkout Speed Audit: the notice on the page and the form handler (includes/admin.php).
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

	public function test_the_page_shows_no_plan_or_purchase() {
		$this->sign_in_shop_manager();
		tvarkom_csa_update_settings( array( 'api_key' => 'tvk_test' ) );
		$this->respond(
			'GET',
			'/v1/client',
			200,
			array_merge(
				$this->client_status(),
				array(
					'monthly_limit'       => 4, // An older gateway's answer.
					'audits_last_30_days' => 1,
					'upgrade_url'         => 'https://buy.example', // Ignored: there is nothing to buy.
				)
			)
		);

		$page = $this->render();

		$this->assertStringContainsString( 'One report per week', $page );
		$this->assertDoesNotMatchRegularExpression( '/\bPro\b|Buy |subscription|License key|Plan:|audits used|per 30 days|hello@|buy\.example/', wp_strip_all_tags( $page ) . $page );
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

	public function test_save_stores_the_email_and_sends_nothing() {
		$this->sign_in_shop_manager();
		$this->assertSame(
			'saved',
			$this->submit(
				array(
					'csa_email' => 'reports@example.org',
					'csa_do'    => 'save',
				)
			)
		);
		$this->assertSame( 'reports@example.org', tvarkom_csa_settings()['email'] );
		$this->assertSame( array(), $this->request_lines() );
	}

	public function test_a_click_while_an_audit_runs_sends_nothing() {
		$this->sign_in_shop_manager();
		tvarkom_csa_update_settings( array( 'api_key' => 'tvk_test' ) );
		tvarkom_csa_audit_lock();

		$this->assertSame( 'audit_running', $this->submit( array( 'csa_do' => 'full' ) ) );
		$this->assertSame( array(), $this->request_lines() );
		$this->assertTrue( $this->lock_is_held(), 'the running audit keeps its lock' );
	}

	public function test_an_audit_runs_now_and_releases_the_lock() {
		$this->sign_in_shop_manager();
		tvarkom_csa_update_settings( array( 'api_key' => 'tvk_test' ) );
		$this->respond( 'GET', '/v1/client', 200, $this->client_status() );
		$this->respond( 'POST', '/v1/audits', 202, array( 'status' => 'queued' ) );

		$this->assertSame( 'queued_full', $this->submit( array( 'csa_do' => 'full' ) ) );
		$this->assertFalse( $this->lock_is_held() );
	}

	public function test_full_scan_sends_the_store_data() {
		$this->sign_in_shop_manager();
		tvarkom_csa_update_settings( array( 'api_key' => 'tvk_test' ) );
		$this->respond( 'GET', '/v1/client', 200, $this->client_status() );
		$this->respond( 'POST', '/v1/audits', 202, array( 'status' => 'queued' ) );

		$this->assertSame( 'queued_full', $this->submit( array( 'csa_do' => 'full' ) ) );
		$this->assertArrayHasKey( 'detailed', $this->requests[1]['body'] );
		$this->assertArrayHasKey( 'metrics', $this->requests[1]['body'] );
	}

	public function test_the_page_offers_both_scans_and_shows_no_results() {
		$this->sign_in_shop_manager();
		tvarkom_csa_update_settings( array( 'api_key' => 'tvk_test' ) );
		$this->respond( 'GET', '/v1/client', 200, $this->client_status() );

		$page = $this->render();

		$this->assertStringNotContainsString( 'value="partial"', $page );
		$this->assertStringContainsString( 'value="full"', $page );
		// What is sent, then the agreement, then the button: the admin reads both before clicking.
		$data    = strpos( $page, 'What is sent to tvarkom' );
		$consent = strpos( $page, 'you agree to send this data to tvarkom' );
		$button  = strpos( $page, 'value="full"' );
		$this->assertTrue( false !== $data && false !== $consent && $data < $consent && $consent < $button, 'data, consent, button in that order' );
		$this->assertStringContainsString( 'legal/terms-of-use.txt', $page );
		$this->assertStringContainsString( 'legal/privacy-policy.txt', $page );
		$this->assertDoesNotMatchRegularExpression( '/Store diagnostics|Preview data|Profile my checkout|Largest autoloaded options/', $page );
		// The API key is internal: never on the page, not even masked.
		$this->assertStringNotContainsString( 'API key', $page );
		$this->assertStringNotContainsString( 'tvk_', $page );
	}

	public function test_inside_the_week_the_scan_buttons_are_disabled() {
		$this->sign_in_shop_manager();
		tvarkom_csa_update_settings( array( 'api_key' => 'tvk_test' ) );
		$next = time() + 2 * DAY_IN_SECONDS;
		$this->respond( 'GET', '/v1/client', 200, array_merge( $this->client_status(), array( 'next_scan_at' => gmdate( 'c', $next ) ) ) );

		$page = $this->render();

		$this->assertMatchesRegularExpression( '/value="full"[^>]*disabled/', $page );
		$this->assertStringContainsString( 'one report per week', $page );
		$this->assertStringContainsString( wp_date( get_option( 'date_format' ), $next ), $page );
		$this->assertDoesNotMatchRegularExpression( '/value="save"[^>]*disabled/', $page );
	}

	public function test_a_stale_partial_scan_click_only_saves() {
		// A page loaded before 0.9.4 still has the Partial Scan button: it never agreed to send store data.
		$this->sign_in_shop_manager();
		tvarkom_csa_update_settings( array( 'api_key' => 'tvk_test' ) );

		$this->assertSame( 'saved', $this->submit( array( 'csa_do' => 'partial' ) ) );
		$this->assertSame( array(), $this->request_lines() );
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
		$this->respond( 'GET', '/v1/client', 200, $this->client_status() );
		$this->respond( 'POST', '/v1/audits', 202, array( 'status' => 'queued' ) );

		$this->assertSame( 'queued_full', $this->submit( array( 'csa_do' => 'full' ) ) );
		$this->assertSame( array( 'POST /v1/register', 'POST /v1/register/verify', 'GET /v1/client', 'POST /v1/audits' ), $this->request_lines() );
		$this->assertSame( 'Bearer tvk_new', $this->requests[3]['headers']['Authorization'] );
	}

	public function test_a_failed_registration_releases_the_lock() {
		$this->sign_in_shop_manager();
		$this->respond( 'POST', '/v1/register', 400, array( 'error' => 'invalid_website' ) );

		$this->assertSame( 'invalid_website', $this->submit( array( 'csa_do' => 'full' ) ) );
		$this->assertFalse( $this->lock_is_held() );
	}
}
