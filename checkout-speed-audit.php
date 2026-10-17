<?php
/**
 * Plugin Name:       Checkout Speed Audit for WooCommerce
 * Description:       Free store health audit by Tvarkom: server and checkout speed, caching, database and background tasks, emailed to you as a report.
 * Version:           0.5.3
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Tvarkom
 * Author URI:        https://tvarkom.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       checkout-speed-audit-for-woocommerce
 *
 * @package CheckoutSpeedAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TVARKOM_CSA_VERSION', '0.5.3' );
define( 'TVARKOM_CSA_OPTION', 'tvarkom_csa' );
define( 'TVARKOM_CSA_TOKEN', 'tvarkom_csa_verify_token' );
define( 'TVARKOM_CSA_ACTION', 'tvarkom_csa_run_audit' );

// Overridable in wp-config.php, e.g. for testing against a local gateway.
if ( ! defined( 'TVARKOM_CSA_API_URL' ) ) {
	define( 'TVARKOM_CSA_API_URL', 'https://api.tvarkom.com' );
}

require_once __DIR__ . '/includes/api.php';
require_once __DIR__ . '/includes/metrics.php';
require_once __DIR__ . '/includes/detailed.php';
require_once __DIR__ . '/includes/profile.php';
require_once __DIR__ . '/includes/admin.php';

// Only acts on the audit job's own checkout request (includes/profile.php).
tvarkom_csa_profile_maybe_start();

/**
 * Stored settings: API key, plan, report email, consent and the last result.
 *
 * @return array{api_key: string, plan: string, email: string, consent: bool, last: array}
 */
function tvarkom_csa_settings() {
	$saved = get_option( TVARKOM_CSA_OPTION, array() );
	return wp_parse_args(
		is_array( $saved ) ? $saved : array(),
		array(
			'api_key' => '',
			'plan'    => '',
			'email'   => get_option( 'admin_email' ),
			'consent' => false,
			'last'    => array(),
		)
	);
}

/**
 * Merges $changes into the stored settings. Not autoloaded: it's only read on the plugin's own page and job.
 *
 * @param array $changes Settings to change.
 */
function tvarkom_csa_update_settings( array $changes ) {
	update_option( TVARKOM_CSA_OPTION, array_merge( tvarkom_csa_settings(), $changes ), false );
}

/**
 * Domain check for registration: the gateway fetches this URL and expects the token it
 * handed to this site a moment earlier. Public on purpose; it only ever returns a
 * short-lived, single-use token that is useless to anyone else.
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
}
add_action( 'rest_api_init', 'tvarkom_csa_register_routes' );

// The audit request runs in the background (Action Scheduler, bundled with WooCommerce).
add_action( TVARKOM_CSA_ACTION, 'tvarkom_csa_run_audit' );
