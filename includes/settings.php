<?php
/**
 * Stored settings: API key, plan, report email, consent and the last result (option tvarkom_csa).
 *
 * @package CheckoutSpeedAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stored settings: API key, plan, report email and the last result. Versions before 0.9.0
 * also stored "consent" (the store data checkbox); 0.9.0 asks with each click instead (Partial
 * Scan or Full Scan) and ignores it.
 *
 * @return array{api_key: string, plan: string, email: string, last: array<string, mixed>}
 */
function tvarkom_csa_settings() {
	$saved = get_option( TVARKOM_CSA_OPTION, array() );
	return wp_parse_args(
		is_array( $saved ) ? $saved : array(),
		array(
			'api_key' => '',
			'plan'    => '',
			'email'   => get_option( 'admin_email' ),
			'last'    => array(),
		)
	);
}

/**
 * Merges $changes into the stored settings. Not autoloaded: it's only read on the plugin's own page and job.
 *
 * @param array<string, mixed> $changes Settings to change.
 * @return void
 */
function tvarkom_csa_update_settings( array $changes ) {
	update_option( TVARKOM_CSA_OPTION, array_merge( tvarkom_csa_settings(), $changes ), false );
}
