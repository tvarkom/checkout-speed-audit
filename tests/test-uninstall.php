<?php
/**
 * Uninstalling removes everything the plugin stored (uninstall.php).
 *
 * @package CheckoutSpeedAudit
 */

/**
 * Uninstall.
 */
class Tvarkom_CSA_Uninstall_Test extends Tvarkom_CSA_Test_Case {

	public function test_uninstall_removes_the_plugin_data() {
		tvarkom_csa_update_settings( array( 'api_key' => 'tvk_test' ) );
		update_option( 'tvarkom_csa_local_profile', array( 'total_ms' => 1 ) );
		update_option( TVARKOM_CSA_MONITOR_SEEN, 41 );
		tvarkom_csa_audit_lock();
		set_transient( TVARKOM_CSA_TOKEN, 'tok_1', MINUTE_IN_SECONDS );
		set_transient( 'tvarkom_csa_status', array( 'plan' => 'free' ), MINUTE_IN_SECONDS );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'tvarkom-checkout-speed-audit-for-woocommerce/checkout-speed-audit.php' );
		}
		require dirname( __DIR__ ) . '/uninstall.php';

		$this->assertFalse( get_option( TVARKOM_CSA_OPTION ) );
		$this->assertFalse( get_option( 'tvarkom_csa_local_profile' ) );
		$this->assertFalse( get_option( TVARKOM_CSA_MONITOR_SEEN ) );
		$this->assertFalse( $this->lock_is_held() );
		$this->assertFalse( get_transient( TVARKOM_CSA_TOKEN ) );
		$this->assertFalse( get_transient( 'tvarkom_csa_status' ) );
	}
}
