<?php
/**
 * One audit at a time (includes/audit.php).
 *
 * @package CheckoutSpeedAudit
 */

/**
 * The audit lock and the queued-audit hook that runs under it.
 */
class Tvarkom_CSA_Audit_Lock_Test extends Tvarkom_CSA_Test_Case {

	/**
	 * Sets the lock row's time.
	 *
	 * @param int $time Unix time.
	 */
	private function set_lock_time( $time ) {
		global $wpdb;
		$wpdb->update( $wpdb->options, array( 'option_value' => (string) $time ), array( 'option_name' => TVARKOM_CSA_AUDIT_LOCK ) );
	}

	public function test_only_one_request_holds_the_lock() {
		$this->assertTrue( tvarkom_csa_audit_lock() );
		$this->assertFalse( tvarkom_csa_audit_lock(), 'second request' );

		tvarkom_csa_audit_unlock();
		$this->assertFalse( $this->lock_is_held() );
		$this->assertTrue( tvarkom_csa_audit_lock(), 'after unlock' );
	}

	public function test_lock_row_is_not_autoloaded() {
		global $wpdb;
		tvarkom_csa_audit_lock();
		$this->assertSame( 'no', $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM `$wpdb->options` WHERE option_name = %s", TVARKOM_CSA_AUDIT_LOCK ) ) );
	}

	public function test_a_lock_under_five_minutes_old_is_kept() {
		tvarkom_csa_audit_lock();
		$this->set_lock_time( time() - 5 * MINUTE_IN_SECONDS + 10 );
		$this->assertFalse( tvarkom_csa_audit_lock() );
	}

	public function test_a_stale_lock_is_taken_over() {
		global $wpdb;
		tvarkom_csa_audit_lock();
		$this->set_lock_time( time() - 5 * MINUTE_IN_SECONDS - 10 );

		$this->assertTrue( tvarkom_csa_audit_lock() );
		$since = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `$wpdb->options` WHERE option_name = %s", TVARKOM_CSA_AUDIT_LOCK ) );
		$this->assertGreaterThanOrEqual( time() - 5, $since, 'taken over with the current time' );
		$this->assertFalse( tvarkom_csa_audit_lock(), 'and held again' );
	}

	public function test_a_queued_audit_is_dropped_while_an_audit_runs() {
		tvarkom_csa_update_settings( array( 'api_key' => 'tvk_test' ) );
		tvarkom_csa_audit_lock();

		do_action( TVARKOM_CSA_ACTION );

		$this->assertSame( array(), $this->request_lines() );
		$this->assertTrue( $this->lock_is_held(), 'the running audit keeps its lock' );
	}

	public function test_a_queued_audit_runs_and_releases_the_lock() {
		tvarkom_csa_update_settings( array( 'api_key' => 'tvk_test' ) );
		$this->respond( 'GET', '/v1/client', 200, $this->client_status( false ) );
		$this->respond( 'POST', '/v1/audits', 202, array( 'status' => 'queued' ) );

		do_action( TVARKOM_CSA_ACTION );

		$this->assertSame( array( 'GET /v1/client', 'POST /v1/audits' ), $this->request_lines() );
		$this->assertSame( 'queued', tvarkom_csa_settings()['last']['code'] );
		$this->assertFalse( $this->lock_is_held() );
	}
}
