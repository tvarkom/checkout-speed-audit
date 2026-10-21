<?php
/**
 * Pro checkout profile, audit side: picks the product for the temporary cart, requests this
 * store's own checkout with the single-use token and reads the result (see profile.php).
 *
 * @package CheckoutSpeedAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A published, in-stock simple product for the temporary cart: physical first,
 * so shipping is part of the profile. 0 if there's none.
 *
 * @return int
 */
function tvarkom_csa_profile_product() {
	foreach ( array( false, null ) as $virtual ) {
		$args = array(
			'status'       => 'publish',
			'type'         => 'simple',
			'stock_status' => 'instock',
			'visibility'   => 'visible',
			'limit'        => 10,
			'orderby'      => 'date',
			'order'        => 'DESC',
		);
		if ( null !== $virtual ) {
			$args['virtual'] = $virtual;
		}
		foreach ( wc_get_products( $args ) as $product ) {
			if ( $product->is_purchasable() && $product->is_in_stock() ) {
				return $product->get_id();
			}
		}
	}
	return 0;
}

/**
 * Runs the profiled checkout request and returns its result (or why there is none).
 * Called by the audit job on a Pro detailed audit.
 *
 * @return array<string, mixed>
 */
function tvarkom_csa_profile_checkout() {
	$token = wp_generate_password( 40, false, false );
	set_transient(
		TVARKOM_CSA_PROFILE_TOKEN,
		array(
			'token'      => $token,
			'product_id' => tvarkom_csa_profile_product(),
		),
		2 * MINUTE_IN_SECONDS
	);
	delete_transient( TVARKOM_CSA_PROFILE_RESULT );

	$start    = microtime( true );
	$response = wp_remote_get(
		// The extra argument keeps page caches from answering instead of WordPress.
		add_query_arg( 'tvarkom_profile', substr( md5( $token ), 0, 12 ), wc_get_checkout_url() ),
		array(
			'timeout'     => 30,
			'redirection' => 0,
			'user-agent'  => 'Tvarkom checkout profiler (+https://tvarkom.com)',
			'headers'     => array(
				TVARKOM_CSA_PROFILE_HEADER => $token,
				'Cache-Control'            => 'no-cache',
			),
			// Same as WordPress's own loopback checks: the request goes to this server.
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's filter.
		)
	);
	$wall_ms = round( ( microtime( true ) - $start ) * 1000, 1 );
	delete_transient( TVARKOM_CSA_PROFILE_TOKEN );
	$result = get_transient( TVARKOM_CSA_PROFILE_RESULT );
	delete_transient( TVARKOM_CSA_PROFILE_RESULT );

	$status = is_wp_error( $response ) ? null : (int) wp_remote_retrieve_response_code( $response );
	if ( ! is_array( $result ) ) {
		if ( null === $status ) {
			$reason = 'request_failed';
		} elseif ( $status >= 300 && $status < 400 ) {
			$reason = 'redirected';
		} else {
			$reason = 'not_profiled'; // Blocked, or answered by a cache before WordPress ran.
		}
		return array(
			'status'      => 'unavailable',
			'reason'      => $reason,
			'http_status' => $status,
		);
	}
	$result['http_status'] = $status;
	$result['wall_ms']     = $wall_ms;
	return $result;
}
