<?php
/**
 * Tvarkom API gateway client: registration (free API key for this domain) and audits.
 *
 * @package CheckoutSpeedAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * POSTs JSON to the gateway.
 *
 * @param string $path    Path, e.g. "/v1/audits".
 * @param array  $body    Request body.
 * @param string $api_key Bearer key, or "" for none.
 * @return array{status: int, body: array}|WP_Error
 */
function tvarkom_csa_post( $path, array $body, $api_key = '' ) {
	$headers = array( 'Content-Type' => 'application/json' );
	if ( '' !== $api_key ) {
		$headers['Authorization'] = 'Bearer ' . $api_key;
	}
	$response = wp_remote_post(
		TVARKOM_CSA_API_URL . $path,
		array(
			// Registration waits for the gateway to fetch this site; audits wait for a quick platform check.
			'timeout' => 45,
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
		)
	);
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
	return array(
		'status' => (int) wp_remote_retrieve_response_code( $response ),
		'body'   => is_array( $decoded ) ? $decoded : array(),
	);
}

/**
 * Error code from a gateway response, or $fallback.
 *
 * @param array  $response Response from tvarkom_csa_post().
 * @param string $fallback Code when the body has none.
 * @return string
 */
function tvarkom_csa_error_code( array $response, $fallback = 'server' ) {
	return isset( $response['body']['error'] ) && is_string( $response['body']['error'] ) ? sanitize_key( $response['body']['error'] ) : $fallback;
}

/**
 * Registers this store with the gateway and stores its API key. The gateway hands
 * out a token, then fetches it back from this site's REST API to prove we own the
 * domain.
 *
 * @param string $email Report email.
 * @return string "" on success, otherwise an error code.
 */
function tvarkom_csa_register( $email ) {
	$start = tvarkom_csa_post(
		'/v1/register',
		array(
			'site_url'   => home_url( '/' ),
			'email'      => $email,
			'verify_url' => rest_url( 'tvarkom/v1/verify' ),
		)
	);
	if ( is_wp_error( $start ) ) {
		return 'network';
	}
	if ( 201 !== $start['status'] || empty( $start['body']['registration_id'] ) || empty( $start['body']['token'] ) ) {
		return tvarkom_csa_error_code( $start );
	}

	set_transient( TVARKOM_CSA_TOKEN, (string) $start['body']['token'], 10 * MINUTE_IN_SECONDS );
	$verify = tvarkom_csa_post( '/v1/register/verify', array( 'registration_id' => (string) $start['body']['registration_id'] ) );
	delete_transient( TVARKOM_CSA_TOKEN );

	if ( is_wp_error( $verify ) ) {
		return 'network';
	}
	if ( 200 !== $verify['status'] || empty( $verify['body']['api_key'] ) ) {
		return tvarkom_csa_error_code( $verify );
	}
	tvarkom_csa_update_settings(
		array(
			'api_key' => (string) $verify['body']['api_key'],
			'plan'    => isset( $verify['body']['plan'] ) ? sanitize_key( $verify['body']['plan'] ) : 'free',
		)
	);
	return '';
}

/**
 * The background job: collects the store metrics (only with consent) and requests the audit.
 */
function tvarkom_csa_run_audit() {
	$settings = tvarkom_csa_settings();
	if ( '' === $settings['api_key'] ) {
		tvarkom_csa_update_settings( array( 'last' => array( 'code' => 'invalid_key', 'time' => time() ) ) );
		return;
	}

	$body = array(
		'website' => home_url( '/' ),
		'email'   => $settings['email'],
		'pages'   => array(
			'cart'     => wc_get_cart_url(),
			'checkout' => wc_get_checkout_url(),
		),
	);
	if ( $settings['consent'] ) {
		$body['metrics'] = tvarkom_csa_collect_metrics();
	}

	$response = tvarkom_csa_post( '/v1/audits', $body, $settings['api_key'] );
	if ( is_wp_error( $response ) ) {
		$code = 'network';
	} elseif ( 202 === $response['status'] ) {
		$code = 'queued';
	} else {
		$code = tvarkom_csa_error_code( $response );
	}

	$changes = array( 'last' => array( 'code' => $code, 'time' => time() ) );
	if ( 'invalid_key' === $code || 'website_not_allowed' === $code ) {
		// Key revoked, replaced, or for the domain this site had before a move: the next audit registers again.
		$changes['api_key'] = '';
	}
	tvarkom_csa_update_settings( $changes );
}
