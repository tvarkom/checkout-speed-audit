<?php
/**
 * One audit: what is collected and sent to the gateway, and the outcome stored for the admin
 * page. Runs when the admin clicks Partial Scan or Full Scan (admin.php). Store data is sent
 * only with a Full Scan.
 *
 * @package CheckoutSpeedAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TVARKOM_CSA_AUDIT_LOCK', 'tvarkom_csa_audit_lock' );

/**
 * Requests the audit: a Partial Scan sends the store's addresses and the report email only; a
 * Full Scan also collects and sends the store metrics and the detailed diagnostics, with a
 * checkout profile. The gateway treats an audit with the detailed data as a Full Scan.
 *
 * A free store scans once a week (founder decision of 3 Nov 2026): inside the week nothing is
 * sent, and the result says when the next scan is possible. The gateway enforces it too.
 *
 * @param bool $full_scan The admin clicked Full Scan.
 * @return string The result code (the admin page's codes).
 */
function tvarkom_csa_run_audit( $full_scan = false ) {
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
	$next = tvarkom_csa_next_scan_at( tvarkom_csa_status() );
	if ( $next > 0 ) {
		tvarkom_csa_update_settings(
			array(
				'last' => array(
					'code'             => 'too_soon',
					'time'             => time(),
					'next_eligible_at' => $next,
				),
			)
		);
		return 'too_soon';
	}
	if ( $full_scan ) {
		$body['metrics']  = tvarkom_csa_collect_metrics();
		$body['detailed'] = tvarkom_csa_collect_detailed();
	}

	$response = tvarkom_csa_post( '/v1/audits', $body, $settings['api_key'] );
	$last     = array();
	if ( is_wp_error( $response ) ) {
		$code = 'network';
	} elseif ( 202 === $response['status'] ) {
		$code = $full_scan ? 'queued_full' : 'queued';
	} else {
		$code = tvarkom_csa_error_code( $response );
		if ( 'too_soon' === $code && isset( $response['body']['next_eligible_at'] ) ) {
			$last = array( 'next_eligible_at' => (int) $response['body']['next_eligible_at'] );
		}
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
 * When a free store may scan again (Unix seconds), from the gateway's plan status, or 0 when it
 * may scan now (or the status didn't say).
 *
 * @param array<string, mixed>|string|null $status From tvarkom_csa_status().
 * @return int
 */
function tvarkom_csa_next_scan_at( $status ) {
	if ( ! is_array( $status ) || empty( $status['next_scan_at'] ) || ! is_string( $status['next_scan_at'] ) ) {
		return 0;
	}
	$next = strtotime( $status['next_scan_at'] );
	return $next && $next > time() ? $next : 0;
}

/**
 * Takes the lock that lets one audit run at a time on this store: the admin's clicks and the
 * and the queued audit of an older version alike. A click can outlast the browser's wait behind a proxy (the admin
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
 * An audit queued by version 0.6.0 (Action Scheduler), run under the same lock as a click, as a
 * Partial Scan: 0.9.0 sends store data only when the admin clicks Full Scan. If an audit is
 * running already, the queued one is dropped: it would only repeat that audit.
 *
 * @return void
 */
function tvarkom_csa_run_queued_audit() {
	if ( ! tvarkom_csa_audit_lock() ) {
		return;
	}
	tvarkom_csa_run_audit( false );
	tvarkom_csa_audit_unlock();
}
