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
 * The store's plan and usage from GET /v1/client. Never cached: right after a
 * purchase the page must show Pro, not the Buy Pro button.
 *
 * @param string $api_key Key to ask about (default: the stored one).
 * @param int    $timeout Seconds to wait for the gateway (short when only showing the page).
 * @return array|string Status array, or an error code ("invalid_key", "network", …).
 */
function tvarkom_csa_status( $api_key = '', $timeout = 15 ) {
	if ( '' === $api_key ) {
		$api_key = tvarkom_csa_settings()['api_key'];
		if ( '' === $api_key ) {
			return 'not_registered';
		}
	}
	$response = wp_remote_get(
		TVARKOM_CSA_API_URL . '/v1/client',
		array(
			'timeout' => $timeout,
			'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
		)
	);
	if ( is_wp_error( $response ) ) {
		return 'network';
	}
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_array( $body ) ) {
		return isset( $body['error'] ) && is_string( $body['error'] ) ? sanitize_key( $body['error'] ) : 'server';
	}
	return $body;
}

/**
 * Checks a pasted license key with the gateway and, if it belongs to this store, stores it.
 *
 * @param string $key License key.
 * @return string "license_saved", or an error code.
 */
function tvarkom_csa_save_license( $key ) {
	if ( ! preg_match( '/^tvk_[A-Za-z0-9_-]{20,}$/', $key ) ) {
		return 'license_invalid';
	}
	$status = tvarkom_csa_status( $key );
	if ( 'invalid_key' === $status ) {
		return 'license_invalid';
	}
	if ( ! is_array( $status ) ) {
		return $status;
	}
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	$own  = wp_parse_url( (string) $status['origin'], PHP_URL_HOST );
	if ( ! is_string( $host ) || ! is_string( $own ) || preg_replace( '/^www\./', '', strtolower( $host ) ) !== preg_replace( '/^www\./', '', strtolower( $own ) ) ) {
		return 'license_other_store';
	}
	tvarkom_csa_update_settings(
		array(
			'api_key' => $key,
			'plan'    => sanitize_key( (string) $status['plan'] ),
		)
	);
	return 'license_saved';
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
	// With Pro audits left, every audit is a Pro audit, and it needs the store data.
	$status = tvarkom_csa_status();
	$is_pro = is_array( $status ) && ! empty( $status['detailed_available'] );
	if ( $is_pro && ! $settings['consent'] ) {
		tvarkom_csa_update_settings( array( 'last' => array( 'code' => 'consent_required', 'time' => time() ) ) );
		return;
	}
	if ( $settings['consent'] ) {
		$body['metrics'] = tvarkom_csa_collect_metrics();
		if ( $is_pro ) {
			$body['detailed'] = tvarkom_csa_collect_detailed();
		}
	}

	$response = tvarkom_csa_post( '/v1/audits', $body, $settings['api_key'] );
	if ( is_wp_error( $response ) ) {
		$code = 'network';
	} elseif ( 202 === $response['status'] ) {
		$code = empty( $response['body']['detailed'] ) ? 'queued' : 'queued_detailed';
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
