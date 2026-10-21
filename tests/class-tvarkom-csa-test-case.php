<?php
/**
 * Base test case: a fake Tvarkom gateway, and no other outgoing requests.
 *
 * @package CheckoutSpeedAudit
 */

/**
 * Every request to TVARKOM_CSA_API_URL is recorded and answered from respond(); any other
 * request (the store's own checkout, WooCommerce's services) fails as if offline.
 */
abstract class Tvarkom_CSA_Test_Case extends WP_UnitTestCase {

	/**
	 * Requests the gateway received: method, path, headers and decoded JSON body.
	 *
	 * @var array<int, array{method: string, path: string, headers: array, body: mixed}>
	 */
	protected $requests = array();

	/**
	 * Answers still to give, by "METHOD /path", in order.
	 *
	 * @var array<string, array<int, array{0: int, 1: mixed}|WP_Error|callable>>
	 */
	private $responses = array();

	/**
	 * Installs the fake gateway.
	 */
	public function set_up() {
		parent::set_up();
		$this->requests  = array();
		$this->responses = array();
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 1, 3 );
	}

	/**
	 * Queues the gateway's answer to the next "$method $path".
	 *
	 * @param string                $method   GET or POST.
	 * @param string                $path     Path, e.g. "/v1/audits".
	 * @param int|WP_Error|callable $status   HTTP status, a transport error, or a callable that
	 *                                        gets the request and returns array( status, body ).
	 * @param array|string|null     $body     JSON body (array) or raw body (string).
	 */
	protected function respond( $method, $path, $status, $body = null ) {
		$this->responses[ $method . ' ' . $path ][] = is_int( $status ) ? array( $status, $body ) : $status;
	}

	/**
	 * The fake gateway (filter pre_http_request).
	 *
	 * @param false|array|WP_Error $pre  Short-circuit value.
	 * @param array                $args Request arguments.
	 * @param string               $url  Request URL.
	 * @return array|WP_Error
	 */
	public function fake_http( $pre, $args, $url ) {
		if ( 0 !== strpos( $url, TVARKOM_CSA_API_URL . '/' ) ) {
			return new WP_Error( 'http_request_failed', 'Tests are offline: ' . $url );
		}
		$request          = array(
			'method'  => $args['method'],
			'path'    => (string) wp_parse_url( $url, PHP_URL_PATH ),
			'headers' => $args['headers'],
			'body'    => isset( $args['body'] ) && is_string( $args['body'] ) ? json_decode( $args['body'], true ) : null,
		);
		$this->requests[] = $request;

		$key = $request['method'] . ' ' . $request['path'];
		if ( empty( $this->responses[ $key ] ) ) {
			$this->fail( 'Unexpected gateway request: ' . $key );
		}
		$answer = array_shift( $this->responses[ $key ] );
		if ( is_callable( $answer ) ) {
			$answer = $answer( $request );
		}
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}
		list( $status, $body ) = $answer;
		return array(
			'headers'  => array(),
			'body'     => is_array( $body ) ? wp_json_encode( $body ) : (string) $body,
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * The gateway requests as "METHOD /path".
	 *
	 * @return string[]
	 */
	protected function request_lines() {
		return array_map(
			function ( $request ) {
				return $request['method'] . ' ' . $request['path'];
			},
			$this->requests
		);
	}

	/**
	 * Calls $callback, which must end in a redirect, and returns the redirect's csa_result.
	 *
	 * @param callable $callback Code that redirects (tvarkom_csa_redirect() exits otherwise).
	 * @return string
	 */
	protected function result_of_redirect( callable $callback ) {
		$stop = function ( $location ) {
			throw new Tvarkom_CSA_Redirect( $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught below, never printed.
		};
		add_filter( 'wp_redirect', $stop );
		try {
			$callback();
		} catch ( Tvarkom_CSA_Redirect $redirect ) {
			parse_str( (string) wp_parse_url( $redirect->location, PHP_URL_QUERY ), $query );
			return isset( $query['csa_result'] ) ? (string) $query['csa_result'] : '';
		} finally {
			remove_filter( 'wp_redirect', $stop );
		}
		$this->fail( 'No redirect.' );
	}

	/**
	 * Signs in a WooCommerce shop manager.
	 */
	protected function sign_in_shop_manager() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
	}

	/**
	 * Whether the audit lock row exists.
	 *
	 * @return bool
	 */
	protected function lock_is_held() {
		global $wpdb;
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `$wpdb->options` WHERE option_name = %s", TVARKOM_CSA_AUDIT_LOCK ) );
	}

	/**
	 * Gateway status for a store with the given plan.
	 *
	 * @param bool $pro Pro audits left.
	 * @return array
	 */
	protected function client_status( $pro ) {
		return array(
			'plan'               => $pro ? 'pro' : 'free',
			'origin'             => home_url(),
			'detailed_available' => $pro,
		);
	}
}
