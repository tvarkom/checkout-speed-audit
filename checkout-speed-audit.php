<?php
/**
 * Plugin Name:       Tvarkom Checkout Speed Audit for WooCommerce
 * Description:       Store diagnostics and a checkout profiler in your WordPress admin, plus a free store health report from Tvarkom: server and checkout speed, caching, database and background tasks.
 * Version:           0.8.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Tvarkom
 * Author URI:        https://tvarkom.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tvarkom-checkout-speed-audit-for-woocommerce
 *
 * @package CheckoutSpeedAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TVARKOM_CSA_VERSION', '0.8.0' );
define( 'TVARKOM_CSA_OPTION', 'tvarkom_csa' );
define( 'TVARKOM_CSA_TOKEN', 'tvarkom_csa_verify_token' );
define( 'TVARKOM_CSA_ACTION', 'tvarkom_csa_run_audit' );

// Overridable in wp-config.php, e.g. for testing against a local gateway.
if ( ! defined( 'TVARKOM_CSA_API_URL' ) ) {
	define( 'TVARKOM_CSA_API_URL', 'https://api.tvarkom.com' );
}

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/api.php';
require_once __DIR__ . '/includes/rest.php';
require_once __DIR__ . '/includes/monitor.php';
require_once __DIR__ . '/includes/metrics.php';
require_once __DIR__ . '/includes/detailed.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/class-tvarkom-csa-profiler.php';
require_once __DIR__ . '/includes/profile.php';
require_once __DIR__ . '/includes/profile-report.php';
require_once __DIR__ . '/includes/profile-request.php';
require_once __DIR__ . '/includes/diagnostics.php';
require_once __DIR__ . '/includes/admin.php';

// Every hook the plugin registers when it loads. The profiler registers its own hooks, and
// only on the audit job's own checkout request (includes/profile.php).
tvarkom_csa_profile_maybe_start();

// The admin page (includes/admin.php).
add_action( 'admin_menu', 'tvarkom_csa_admin_menu', 99 );
add_action( 'admin_post_tvarkom_csa_form', 'tvarkom_csa_handle_form' );
add_action( 'admin_enqueue_scripts', 'tvarkom_csa_admin_scripts' );

// Store diagnostics: the local checkout profile button (includes/diagnostics.php).
add_action( 'admin_post_tvarkom_csa_profile', 'tvarkom_csa_handle_profile' );

// Domain check for registration, and the weekly Pro report (includes/rest.php, includes/monitor.php).
add_action( 'rest_api_init', 'tvarkom_csa_register_routes' );

// Audits run when the admin clicks the button (admin.php). Version 0.6.0 and earlier queued
// them with Action Scheduler; this keeps a job such a version queued before an upgrade working,
// under the same one-audit-at-a-time lock (includes/audit.php).
add_action( TVARKOM_CSA_ACTION, 'tvarkom_csa_run_queued_audit' );
