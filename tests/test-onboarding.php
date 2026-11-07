<?php
/**
 * First-audit onboarding (includes/onboarding.php and the welcome on the admin page): the notice
 * after activation, its permanent dismissal, the Plugins screen link. None of it contacts tvarkom.
 *
 * @package CheckoutSpeedAudit
 */

/**
 * Onboarding.
 */
class Tvarkom_CSA_Onboarding_Test extends Tvarkom_CSA_Test_Case {

	/**
	 * Clears the request and the screen.
	 */
	public function tear_down() {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		set_current_screen( 'front' );
		remove_all_filters( 'wp_doing_ajax' );
		remove_all_filters( 'wp_die_ajax_handler' );
		parent::tear_down();
	}

	/**
	 * The notice as rendered on a screen.
	 *
	 * @param string $screen Screen id.
	 * @return string
	 */
	private function notice_on( $screen ) {
		set_current_screen( $screen );
		ob_start();
		tvarkom_csa_onboarding_notice();
		return (string) ob_get_clean();
	}

	/**
	 * The admin page, as rendered.
	 *
	 * @return string
	 */
	private function page() {
		ob_start();
		tvarkom_csa_render_page();
		return (string) ob_get_clean();
	}

	/**
	 * Calls the dismiss request as an AJAX call; true when it answered (success or error).
	 *
	 * @return void
	 */
	private function dismiss() {
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			function () {
				return function () {
					throw new WPDieException( 'ajax' );
				};
			}
		);
		try {
			ob_start();
			tvarkom_csa_dismiss_onboarding();
		} catch ( WPDieException $e ) {
			unset( $e ); // The answer was sent.
		} finally {
			ob_end_clean();
		}
	}

	public function test_activation_shows_the_notice_once_to_a_new_store() {
		tvarkom_csa_onboarding_activate();
		$this->sign_in_shop_manager();

		$html = $this->notice_on( 'dashboard' );
		$this->assertStringContainsString( 'Check your WooCommerce checkout health', $html );
		$this->assertStringContainsString( 'Find potential checkout performance issues and understand what may be affecting your customers.', $html );
		$this->assertStringContainsString( 'Run Your Free Audit', $html );
		$this->assertStringContainsString( esc_url( admin_url( 'admin.php?page=tvarkom-csa' ) ), $html );
		$this->assertStringContainsString( 'is-dismissible', $html );
		$this->assertStringContainsString( 'Run Your Free Audit', $this->notice_on( 'plugins' ) );
		$this->assertStringContainsString( 'Run Your Free Audit', $this->notice_on( 'woocommerce_page_wc-settings' ) );
		$this->assertSame( array(), $this->requests, 'the notice contacts nobody' );
	}

	public function test_the_notice_stays_off_where_it_would_interrupt() {
		tvarkom_csa_onboarding_activate();
		$this->sign_in_shop_manager();
		$this->assertSame( '', $this->notice_on( 'woocommerce_page_tvarkom-csa' ), 'not on the audit page itself' );
		$this->assertSame( '', $this->notice_on( 'edit-post' ), 'not on unrelated screens' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( '', $this->notice_on( 'dashboard' ), 'not for users who cannot request an audit' );
	}

	public function test_existing_stores_never_get_the_notice() {
		tvarkom_csa_update_settings( array( 'api_key' => 'tvk_test' ) );
		tvarkom_csa_onboarding_activate(); // A reactivation.
		$this->sign_in_shop_manager();
		$this->assertFalse( get_option( TVARKOM_CSA_ONBOARDING ) );
		$this->assertSame( '', $this->notice_on( 'dashboard' ) );
	}

	public function test_a_requested_report_ends_the_notice() {
		tvarkom_csa_onboarding_activate();
		tvarkom_csa_update_settings( array( 'last' => array( 'code' => 'queued_full' ) ) );
		$this->sign_in_shop_manager();
		$this->assertSame( '', $this->notice_on( 'dashboard' ) );
		$this->assertFalse( get_option( TVARKOM_CSA_ONBOARDING ) );
	}

	public function test_dismissing_is_permanent_and_checked() {
		tvarkom_csa_onboarding_activate();

		// A user who can't request audits can't dismiss it for the store.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_REQUEST['_ajax_nonce'] = wp_create_nonce( 'tvarkom_csa_dismiss_onboarding' );
		$this->dismiss();
		$this->assertSame( 1, (int) get_option( TVARKOM_CSA_ONBOARDING ) );

		// Without a valid nonce nothing changes.
		$this->sign_in_shop_manager();
		$_REQUEST['_ajax_nonce'] = 'nope';
		$this->dismiss();
		$this->assertSame( 1, (int) get_option( TVARKOM_CSA_ONBOARDING ) );

		$_REQUEST['_ajax_nonce'] = wp_create_nonce( 'tvarkom_csa_dismiss_onboarding' );
		$this->dismiss();
		$this->assertFalse( get_option( TVARKOM_CSA_ONBOARDING ) );
		$this->assertSame( '', $this->notice_on( 'dashboard' ) );
	}

	public function test_first_visit_shows_the_welcome_and_ends_the_notice() {
		tvarkom_csa_onboarding_activate();
		$this->sign_in_shop_manager();
		$html = $this->page();

		$this->assertStringContainsString( 'Check your WooCommerce checkout health', $html );
		$this->assertStringContainsString( 'href="#tcsa-settings-title">Run Your Free Audit', $html, 'the button leads to the form, it submits nothing' );
		$this->assertStringContainsString( 'Send me the Report', $html, 'the existing request button is unchanged' );
		$this->assertStringNotContainsString( 'estimated hours', $html, 'no claim the report no longer makes' );
		$this->assertFalse( get_option( TVARKOM_CSA_ONBOARDING ), 'visiting the page ends the notice' );
		$this->assertSame( array(), $this->requests, 'a first visit contacts nobody' );
	}

	public function test_after_a_request_the_status_replaces_the_welcome() {
		$this->sign_in_shop_manager();
		tvarkom_csa_update_settings( array( 'last' => array( 'code' => 'queued_full' ) ) );
		$html = $this->page();

		$this->assertStringNotContainsString( 'Run Your Free Audit', $html );
		$this->assertStringNotContainsString( 'Check your WooCommerce checkout health', $html );
		$this->assertStringContainsString( esc_html( tvarkom_csa_messages()['queued_full'][1] ), $html );
	}

	public function test_the_plugins_screen_links_to_the_audit() {
		$this->sign_in_shop_manager();
		$links = tvarkom_csa_plugin_action_links( array( 'deactivate' => '<a href="#">Deactivate</a>' ) );
		$this->assertSame( array( 'tvarkom-csa', 'deactivate' ), array_keys( $links ) );
		$this->assertStringContainsString( 'Run Your Free Audit', $links['tvarkom-csa'] );
		$this->assertStringContainsString( esc_url( admin_url( 'admin.php?page=tvarkom-csa' ) ), $links['tvarkom-csa'] );

		tvarkom_csa_update_settings( array( 'api_key' => 'tvk_test' ) );
		$this->assertStringContainsString( '>Open<', tvarkom_csa_plugin_action_links( array() )['tvarkom-csa'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( array(), tvarkom_csa_plugin_action_links( array() ) );
	}
}
