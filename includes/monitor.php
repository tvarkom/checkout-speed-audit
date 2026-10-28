<?php
/**
 * REST route: POST /wp-json/tvarkom/v1/monitor, Tvarkom's weekly Pro report.
 *
 * About once a week, for a store on Pro, the Tvarkom gateway asks this endpoint for a new full
 * Pro diagnostic. The plugin then runs the same audit as the Run Pro audit button
 * (tvarkom_csa_run_audit()): it collects the same store data (only with consent) and sends it
 * to the gateway with the store's API key, naming the gateway's attempt. The answer to the
 * gateway is only that audit's result code: no store data leaves through this endpoint.
 *
 * Who may call it is checked in the callback, not by a WordPress login: the request must be
 * signed with HMAC-SHA256 over its exact body, keyed by the SHA-256 of this store's API key,
 * which only the gateway (it stores that hash) and this plugin have. The body names the store,
 * the attempt and the time; the plugin refuses another store's request, one older or newer than
 * five minutes, and any attempt id not greater than the last it answered (a replay).
 *
 * @package CheckoutSpeedAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TVARKOM_CSA_MONITOR_SEEN', 'tvarkom_csa_monitor_seen' );

/**
 * Signed requests older or newer than this are refused.
 */
define( 'TVARKOM_CSA_MONITOR_SKEW', 5 * MINUTE_IN_SECONDS );

/**
 * Registers the route (from tvarkom_csa_register_routes()).
 *
 * @return void
 */
function tvarkom_csa_register_monitor_route() {
	register_rest_route(
		'tvarkom/v1',
		'/monitor',
		array(
			'methods'             => 'POST',
			// The signature is the authorization: checked first thing in the callback.
			'permission_callback' => '__return_true',
			'callback'            => 'tvarkom_csa_handle_monitor',
		)
	);
}

/**
 * Answers the gateway's signed request for a weekly Pro diagnostic.
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response
 */
function tvarkom_csa_handle_monitor( WP_REST_Request $request ) {
	$api_key = tvarkom_csa_settings()['api_key'];
	$body    = (string) $request->get_body();
	if ( '' === $api_key ) {
		return tvarkom_csa_monitor_answer( 403, 'not_registered' );
	}
	if ( strlen( $body ) > 1024 || ! tvarkom_csa_monitor_signed( $body, (string) $request->get_header( 'x_tvarkom_signature' ), $api_key ) ) {
		return tvarkom_csa_monitor_answer( 401, 'invalid_signature' );
	}
	$data = json_decode( $body, true );
	if ( ! is_array( $data ) || 1 !== ( $data['v'] ?? null ) || ! is_string( $data['store'] ?? null ) || ! is_int( $data['attempt'] ?? null ) || $data['attempt'] <= 0 || ! is_int( $data['ts'] ?? null ) ) {
		return tvarkom_csa_monitor_answer( 400, 'invalid_request' );
	}
	if ( ! tvarkom_csa_same_store( $data['store'], home_url( '/' ) ) ) {
		return tvarkom_csa_monitor_answer( 403, 'wrong_store' );
	}
	if ( abs( time() - $data['ts'] ) > TVARKOM_CSA_MONITOR_SKEW ) {
		return tvarkom_csa_monitor_answer( 401, 'stale' );
	}
	// One audit at a time, as for a click; the replay check and its update happen under the lock.
	if ( ! tvarkom_csa_audit_lock() ) {
		return tvarkom_csa_monitor_answer( 409, 'audit_running' );
	}
	if ( $data['attempt'] <= (int) get_option( TVARKOM_CSA_MONITOR_SEEN, 0 ) ) {
		tvarkom_csa_audit_unlock();
		return tvarkom_csa_monitor_answer( 409, 'replay' );
	}
	update_option( TVARKOM_CSA_MONITOR_SEEN, $data['attempt'], false );

	// The gateway waits for the answer; finish the audit even if it stops waiting.
	ignore_user_abort( true );
	$code = tvarkom_csa_run_audit( $data['attempt'] );
	tvarkom_csa_audit_unlock();
	return tvarkom_csa_monitor_answer( 200, $code );
}

/**
 * Whether $signature ("v1=<hex>") is the HMAC-SHA256 of $body under the SHA-256 of $api_key.
 *
 * @param string $body      The exact request body.
 * @param string $signature The X-Tvarkom-Signature header.
 * @param string $api_key   This store's API key.
 * @return bool
 */
function tvarkom_csa_monitor_signed( $body, $signature, $api_key ) {
	if ( ! preg_match( '/^v1=[0-9a-f]{64}$/', $signature ) ) {
		return false;
	}
	$expected = 'v1=' . hash_hmac( 'sha256', $body, hash( 'sha256', $api_key, true ) );
	return hash_equals( $expected, $signature );
}

/**
 * Whether two addresses are the same store: the same host, www. ignored.
 *
 * @param string $a An address, e.g. https://shop.example.
 * @param string $b Another.
 * @return bool
 */
function tvarkom_csa_same_store( $a, $b ) {
	$host_a = wp_parse_url( $a, PHP_URL_HOST );
	$host_b = wp_parse_url( $b, PHP_URL_HOST );
	return is_string( $host_a ) && is_string( $host_b ) &&
		preg_replace( '/^www\./', '', strtolower( $host_a ) ) === preg_replace( '/^www\./', '', strtolower( $host_b ) );
}

/**
 * The answer to the gateway: a result code only.
 *
 * @param int    $status HTTP status.
 * @param string $code   Result code.
 * @return WP_REST_Response
 */
function tvarkom_csa_monitor_answer( $status, $code ) {
	$response = new WP_REST_Response( array( 'code' => $code ), $status );
	$response->header( 'Cache-Control', 'no-store' );
	return $response;
}
