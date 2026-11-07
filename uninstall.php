<?php
/**
 * Removes the plugin's data. The free API key stays valid on tvarkom's side; a
 * reinstall registers the store again and replaces it.
 *
 * @package CheckoutSpeedAudit
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'tvarkom_csa' );
delete_option( 'tvarkom_csa_local_profile' ); // Profile my checkout's last result, before 0.9.0.
delete_option( 'tvarkom_csa_audit_lock' );
delete_option( 'tvarkom_csa_funnel_since' );
delete_option( 'tvarkom_csa_onboarding' ); // The first-audit notice (includes/onboarding.php).
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- removes the plugin's own counter table on uninstall.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'tvarkom_csa_funnel' ) );
delete_option( 'tvarkom_csa_monitor_seen' ); // The weekly Pro report's last attempt, before 0.9.0.
delete_transient( 'tvarkom_csa_verify_token' );
delete_transient( 'tvarkom_csa_status' );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'tvarkom_csa_run_audit' );
}
