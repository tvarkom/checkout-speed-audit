<?php
/**
 * One audit: what is collected (only with consent) and sent to the gateway, and the outcome
 * stored for the admin page. Runs when the admin clicks the button (admin.php), and for the
 * weekly Pro report when the gateway asks (monitor.php).
 *
 * @package CheckoutSpeedAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TVARKOM_CSA_AUDIT_LOCK', 'tvarkom_csa_audit_lock' );

/**
 * Collects the store metrics (only with consent) and requests the audit.
 *
 * A weekly Pro report ($monitor_attempt, from monitor.php) sends the same data as a Pro audit
 * the admin runs, naming the gateway's attempt; the gateway decides it's a Pro diagnostic. Its
 * result goes back to the gateway only: the admin page's last result is left alone.
 *
 * @param int $monitor_attempt The gateway's weekly attempt, or 0 for the admin's audit.
 * @return string The result code (the admin page's codes).
 */
function tvarkom_csa_run_audit( $monitor_attempt = 0 ) {
	$settings = tvarkom_csa_settings();
	if ( $monitor_attempt > 0 ) {
		$body = array(
			'website'         => home_url( '/' ),
			'email'           => $settings['email'],
			'pages'           => array(
				'cart'     => wc_get_cart_url(),
				'checkout' => wc_get_checkout_url(),
			),
			'monitor_attempt' => $monitor_attempt,
		);
		if ( $settings['consent'] ) {
			$body['metrics']  = tvarkom_csa_collect_metrics();
			$body['detailed'] = tvarkom_csa_collect_detailed();
		}
		$response = tvarkom_csa_post( '/v1/audits', $body, $settings['api_key'] );
		if ( is_wp_error( $response ) ) {
			return 'network';
		}
		return 202 === $response['status'] ? 'queued_detailed' : tvarkom_csa_error_code( $response );
	}
	if ( '' === $settings['api_key'] ) {
		tvarkom_csa_update_settings(
			array(
				'last' => array(
					'code' => 'invalid_key',
					'time' => time(),
				),
			)
		);
		return 'invalid_key';
	}

	$body = array(
		'website' => home_url( '/' ),
		'email'   => $settings['email'],
		'pages'   => array(
			'cart'     => wc_get_cart_url(),
			'checkout' => wc_get_checkout_url(),
		),
	);
	// On Pro, every audit is a Pro audit, and a new one needs the store data.
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
		return 'consent_required';
	}
	if ( $settings['consent'] ) {
		$body['metrics'] = tvarkom_csa_collect_metrics();
		if ( $is_pro ) {
			$body['detailed'] = tvarkom_csa_collect_detailed();
		}
	}

	$response = tvarkom_csa_post( '/v1/audits', $body, $settings['api_key'] );
	$last     = array();
	if ( is_wp_error( $response ) ) {
		$code = 'network';
	} elseif ( 202 === $response['status'] ) {
		$detailed = ! empty( $response['body']['detailed'] );
		$status   = isset( $response['body']['status'] ) ? (string) $response['body']['status'] : 'queued';
		if ( 'cached' === $status ) {
			// A report from the last 7 days was emailed again: when it was made, and when a new one can be.
			$code = $detailed ? 'cached_pro' : 'cached';
			$last = array(
				'collected_at'     => isset( $response['body']['collected_at'] ) ? (int) $response['body']['collected_at'] : 0,
				'next_eligible_at' => isset( $response['body']['next_eligible_at'] ) ? (int) $response['body']['next_eligible_at'] : 0,
			);
		} elseif ( 'in_progress' === $status ) {
			$code = 'in_progress';
		} else {
			$code = $detailed ? 'queued_detailed' : 'queued';
		}
	} else {
		$code = tvarkom_csa_error_code( $response );
	}

	$changes = array(
		'last' => array_merge(
			array(
				'code' => $code,
				'time' => time(),
			),
			$last
		),
	);
	if ( 'invalid_key' === $code || 'website_not_allowed' === $code ) {
		// Key revoked, replaced, or for the domain this site had before a move: the next audit registers again.
		$changes['api_key'] = '';
	}
	tvarkom_csa_update_settings( $changes );
	return $code;
}

/**
 * Takes the lock that lets one audit run at a time on this store: the admin's clicks and the
 * weekly Pro report alike. A click can outlast the browser's wait behind a proxy (the admin
 * sees an error although the audit was sent), so a second click must not send a second audit
 * while the first is still running.
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
