<?php
/**
 * The profiler starts only on the audit's own checkout request (includes/profile.php).
 *
 * @package CheckoutSpeedAudit
 */

/**
 * Requests that must not start the profiler. The request that does start it changes the
 * whole process (SAVEQUERIES, wrapped hooks), so it is covered by the Playground check in
 * DEVELOPMENT.md rather than here.
 */
class Tvarkom_CSA_Profile_Token_Test extends Tvarkom_CSA_Test_Case {

	/**
	 * The server variables before the test.
	 *
	 * @var array
	 */
	private $server;

	/**
	 * Saves $_SERVER and sets a pending profile.
	 */
	public function set_up() {
		parent::set_up();
		$this->server = $_SERVER;
		set_transient(
			TVARKOM_CSA_PROFILE_TOKEN,
			array(
				'token'      => 'right-token',
				'product_id' => 0,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Restores $_SERVER.
	 */
	public function tear_down() {
		$_SERVER = $this->server;
		parent::tear_down();
	}

	/**
	 * Requests that don't start it.
	 *
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public function data_refused() {
		return array(
			'no header'    => array( 'GET', null ),
			'wrong token'  => array( 'GET', 'wrong-token' ),
			'empty header' => array( 'GET', '' ),
			'not a GET'    => array( 'POST', 'right-token' ),
		);
	}

	/**
	 * The pending token stays for the real request, and no profiler hook is added.
	 *
	 * @dataProvider data_refused
	 *
	 * @param string      $method Request method.
	 * @param string|null $header X-Tvarkom-Profile header.
	 */
	public function test_refused( $method, $header ) {
		$_SERVER['REQUEST_METHOD'] = $method;
		unset( $_SERVER['HTTP_X_TVARKOM_PROFILE'] );
		if ( null !== $header ) {
			$_SERVER['HTTP_X_TVARKOM_PROFILE'] = $header;
		}

		tvarkom_csa_profile_maybe_start();

		$this->assertSame( 'right-token', get_transient( TVARKOM_CSA_PROFILE_TOKEN )['token'] );
		$this->assertFalse( has_action( 'shutdown', 'tvarkom_csa_profile_finish' ) );
	}
}
