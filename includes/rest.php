<?php
/**
 * REST routes: GET /wp-json/tvarkom/v1/verify, the domain check for registration, and
 * POST /wp-json/tvarkom/v1/monitor, the weekly Pro report (monitor.php).
 *
 * @package CheckoutSpeedAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Domain check for registration: the gateway fetches this URL and expects the token it
 * handed to this site a moment earlier. Public on purpose; it only ever returns a
 * short-lived, single-use token that is useless to anyone else.
 *
 * @return void
 */
function tvarkom_csa_register_routes() {
	register_rest_route(
		'tvarkom/v1',
		'/verify',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => function () {
				$token = get_transient( TVARKOM_CSA_TOKEN );
				if ( ! is_string( $token ) || '' === $token ) {
					return new WP_Error( 'tvarkom_csa_no_registration', 'No registration in progress.', array( 'status' => 404 ) );
				}
				return array( 'token' => $token );
			},
		)
	);
	tvarkom_csa_register_monitor_route();
}
