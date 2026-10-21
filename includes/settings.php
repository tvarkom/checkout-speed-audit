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
