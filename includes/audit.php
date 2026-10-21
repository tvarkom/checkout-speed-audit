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

define( 'TVARKOM_CSA_AUDIT_LOCK', 'tvarkom_csa_audit_lock' );

/**
 * The background job: collects the store metrics (only with consent) and requests the audit.
 *
 * @return void
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

/**
 * Takes the lock that lets one audit run at a time on this store. Each audit uses one of the
 * store's scans, a paid one on Pro, and a click can outlast the browser's wait behind a proxy
 * (the admin sees an error although the audit was sent), so a second click must not send a
 * second audit while the first is still running.
 *
 * The lock is an option row inserted atomically, as in WordPress core's
 * WP_Upgrader::create_lock(). A lock older than five minutes was left by a request that died
 * and is taken over.
 *
 * @return bool Whether this request now holds the lock.
 */
function tvarkom_csa_audit_lock() {
	global $wpdb;
	$now = (string) time();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The atomic insert is the lock; the options API can't refuse a row that exists.
	if ( $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO `$wpdb->options` ( `option_name`, `option_value`, `autoload` ) VALUES ( %s, %s, 'no' )", TVARKOM_CSA_AUDIT_LOCK, $now ) ) ) {
		return true;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the row itself: the options cache doesn't know about the insert above.
	$since = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `$wpdb->options` WHERE option_name = %s", TVARKOM_CSA_AUDIT_LOCK ) );
	if ( null === $since || (int) $since > time() - 5 * MINUTE_IN_SECONDS ) {
		return false;
	}
	// Take over a stale lock only if no other request took it over first.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-set on the lock row.
	return 1 === $wpdb->query( $wpdb->prepare( "UPDATE `$wpdb->options` SET option_value = %s WHERE option_name = %s AND option_value = %s", $now, TVARKOM_CSA_AUDIT_LOCK, $since ) );
}

/**
 * Releases the lock taken by tvarkom_csa_audit_lock().
 *
 * @return void
 */
function tvarkom_csa_audit_unlock() {
	delete_option( TVARKOM_CSA_AUDIT_LOCK );
}

/**
 * An audit queued by version 0.6.0 (Action Scheduler), run under the same lock as a click. If an
 * audit is running already, the queued one is dropped: it would only repeat that audit.
 *
 * @return void
 */
function tvarkom_csa_run_queued_audit() {
	if ( ! tvarkom_csa_audit_lock() ) {
		return;
	}
	tvarkom_csa_run_audit();
	tvarkom_csa_audit_unlock();
}
