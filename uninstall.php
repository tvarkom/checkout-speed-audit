<?php
/**
 * Removes the plugin's data. The free API key stays valid on Tvarkom's side; a
 * reinstall registers the store again and replaces it.
 *
 * @package CheckoutSpeedAudit
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'tvarkom_csa' );
delete_option( 'tvarkom_csa_local_profile' );
delete_option( 'tvarkom_csa_audit_lock' );
delete_transient( 'tvarkom_csa_verify_token' );
delete_transient( 'tvarkom_csa_status' );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'tvarkom_csa_run_audit' );
}
