<?php
/**
 * Plugin Name:       Checkout Speed Audit
 * Description:       A free store health report from tvarkom, emailed as a PDF: server and checkout speed, caching, your database, PHP setup, background tasks and a checkout profile. New: findings explained with your store's business context (orders, sales ranges, catalog, payments and shipping), and checks of your HTTPS certificate and email setup (SPF, DMARC).
 * Version:           0.11.1
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            tvarkom
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

define( 'TVARKOM_CSA_VERSION', '0.11.1' );
define( 'TVARKOM_CSA_OPTION', 'tvarkom_csa' );
define( 'TVARKOM_CSA_TOKEN', 'tvarkom_csa_verify_token' );
define( 'TVARKOM_CSA_ACTION', 'tvarkom_csa_run_audit' );

// Overridable in wp-config.php, e.g. for testing against a local gateway.
if ( ! defined( 'TVARKOM_CSA_API_URL' ) ) {
	define( 'TVARKOM_CSA_API_URL', 'https://api.tvarkom.com' );
}

// The audit service's terms and privacy policy ship with the plugin (legal/, linked through
// tvarkom_csa_legal_url()), so the version the admin agrees to is the one installed.
define( 'TVARKOM_CSA_FILE', __FILE__ );

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/api.php';
require_once __DIR__ . '/includes/rest.php';
require_once __DIR__ . '/includes/business.php';
require_once __DIR__ . '/includes/funnel.php';
require_once __DIR__ . '/includes/metrics.php';
require_once __DIR__ . '/includes/detailed.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/class-tvarkom-csa-profiler.php';
require_once __DIR__ . '/includes/profile.php';
require_once __DIR__ . '/includes/profile-report.php';
require_once __DIR__ . '/includes/profile-request.php';
require_once __DIR__ . '/includes/admin.php';

// Every hook the plugin registers when it loads. The profiler registers its own hooks, and
// only on the audit job's own checkout request (includes/profile.php).
tvarkom_csa_profile_maybe_start();

// The admin page (includes/admin.php).
add_action( 'admin_menu', 'tvarkom_csa_admin_menu', 99 );
add_action( 'admin_post_tvarkom_csa_form', 'tvarkom_csa_handle_form' );
add_action( 'admin_enqueue_scripts', 'tvarkom_csa_admin_scripts' );
add_action( 'admin_init', 'tvarkom_csa_privacy_policy_content' );

// Funnel counters (includes/funnel.php): the table on activation, or on the first admin page
// after an update; daily totals of add-to-cart, checkout loads and orders.
register_activation_hook( __FILE__, 'tvarkom_csa_funnel_install' );
add_action( 'admin_init', 'tvarkom_csa_funnel_install' );
add_action( 'woocommerce_add_to_cart', 'tvarkom_csa_funnel_add_to_cart' );
add_action( 'template_redirect', 'tvarkom_csa_funnel_checkout_view' );
add_action( 'woocommerce_checkout_order_processed', 'tvarkom_csa_funnel_order' );
add_action( 'woocommerce_store_api_checkout_order_processed', 'tvarkom_csa_funnel_order' );

// Domain check for registration (includes/rest.php).
add_action( 'rest_api_init', 'tvarkom_csa_register_routes' );

// Audits run when the admin clicks Send me the Report (admin.php). Version 0.6.0 and earlier queued
// them with Action Scheduler; a job such a version queued before an upgrade is dropped
// (includes/audit.php).
add_action( TVARKOM_CSA_ACTION, 'tvarkom_csa_drop_queued_audits' );
