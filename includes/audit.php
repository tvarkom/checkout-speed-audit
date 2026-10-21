<?php
/**
 * One audit: what is collected (only with consent) and sent to the gateway, and the outcome
 * stored for the admin page. Runs when the admin clicks the button (admin.php).
 *
 * @package CheckoutSpeedAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The background job: collects the store metrics (only with consent) and requests the audit.
 */
function tvarkom_csa_run_audit() {
	$settings = tvarkom_csa_settings();
	if ( '' === $settings['api_key'] ) {
		tvarkom_csa_update_settings(
			array(
				'last' => array(
					'code' => 'invalid_key',
					'time' => time(),
				),
			)
		);
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
		tvarkom_csa_update_settings(
			array(
				'last' => array(
					'code' => 'consent_required',
					'time' => time(),
				),
			)
		);
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

	$changes = array(
		'last' => array(
			'code' => $code,
			'time' => time(),
		),
	);
	if ( 'invalid_key' === $code || 'website_not_allowed' === $code ) {
		// Key revoked, replaced, or for the domain this site had before a move: the next audit registers again.
		$changes['api_key'] = '';
	}
	tvarkom_csa_update_settings( $changes );
}
