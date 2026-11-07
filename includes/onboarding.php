<?php
/**
 * First-audit onboarding: after activation, a dismissible notice that leads to the audit page,
 * and a link on the Plugins screen. Nothing here contacts tvarkom or requests an audit: the
 * notice only links to WooCommerce → Checkout Speed Audit, where the admin requests the report
 * with the existing form (includes/admin.php).
 *
 * @package CheckoutSpeedAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TVARKOM_CSA_ONBOARDING', 'tvarkom_csa_onboarding' ); // Set on activation: show the notice.

/**
 * Whether this store has never requested a report: no key from tvarkom and no result yet.
 *
 * @return bool
 */
function tvarkom_csa_first_audit_pending() {
	$settings = tvarkom_csa_settings();
	return '' === $settings['api_key'] && empty( $settings['last'] );
}

/**
 * On activation: show the notice, unless this store has already requested a report (a
 * reactivation). Plugin updates don't activate, so existing users never see it.
 *
 * @return void
 */
function tvarkom_csa_onboarding_activate() {
	if ( tvarkom_csa_first_audit_pending() ) {
		update_option( TVARKOM_CSA_ONBOARDING, 1, false );
	}
}

/**
 * Whether the notice shows on the current screen: only while it's pending and no report was
 * requested, to users who can request one, on the Dashboard, the Plugins screen and WooCommerce
 * screens, never on the audit page itself.
 *
 * @return bool
 */
function tvarkom_csa_onboarding_due() {
	if ( ! get_option( TVARKOM_CSA_ONBOARDING ) || ! current_user_can( 'manage_woocommerce' ) ) {
		return false;
	}
	if ( ! tvarkom_csa_first_audit_pending() ) {
		delete_option( TVARKOM_CSA_ONBOARDING );
		return false;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'woocommerce_page_tvarkom-csa' === $screen->id ) {
		return false;
	}
	return in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) || false !== strpos( $screen->id, 'woocommerce' );
}

/**
 * The notice.
 *
 * @return void
 */
function tvarkom_csa_onboarding_notice() {
	if ( ! tvarkom_csa_onboarding_due() ) {
		return;
	}
	?>
	<div class="notice notice-info is-dismissible tvarkom-csa-onboarding" data-nonce="<?php echo esc_attr( wp_create_nonce( 'tvarkom_csa_dismiss_onboarding' ) ); ?>">
		<h2><?php esc_html_e( 'Run your Checkout Speed Audit', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></h2>
		<p><?php esc_html_e( 'Find potential checkout performance issues and understand what may be affecting your customers.', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></p>
		<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=tvarkom-csa' ) ); ?>"><?php esc_html_e( 'Run Your Free Audit', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></a></p>
	</div>
	<?php
}

/**
 * Makes the notice's dismiss button (WordPress's own) permanent: it tells the server, which
 * forgets the notice for good.
 *
 * @return void
 */
function tvarkom_csa_onboarding_script() {
	if ( ! tvarkom_csa_onboarding_due() ) {
		return;
	}
	wp_register_script( 'tvarkom-csa-onboarding', false, array(), TVARKOM_CSA_VERSION, true );
	wp_enqueue_script( 'tvarkom-csa-onboarding' );
	wp_add_inline_script(
		'tvarkom-csa-onboarding',
		'document.addEventListener("click", function (event) {
	var notice = event.target.closest && event.target.closest(".tvarkom-csa-onboarding");
	if (!notice || !event.target.classList.contains("notice-dismiss")) { return; }
	var body = new URLSearchParams({ action: "tvarkom_csa_dismiss_onboarding", _ajax_nonce: notice.getAttribute("data-nonce") });
	fetch(' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ', { method: "POST", credentials: "same-origin", body: body });
});'
	);
}

/**
 * The dismiss request: forgets the notice.
 *
 * @return void
 */
function tvarkom_csa_dismiss_onboarding() {
	check_ajax_referer( 'tvarkom_csa_dismiss_onboarding' );
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( null, 403 );
	}
	delete_option( TVARKOM_CSA_ONBOARDING );
	wp_send_json_success();
}

/**
 * A link to the audit page on the Plugins screen, next to Deactivate.
 *
 * @param array<string, string> $links The plugin's action links.
 * @return array<string, string>
 */
function tvarkom_csa_plugin_action_links( $links ) {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return $links;
	}
	$label = tvarkom_csa_first_audit_pending()
		? __( 'Run Your Free Audit', 'tvarkom-checkout-speed-audit-for-woocommerce' )
		: __( 'Open', 'tvarkom-checkout-speed-audit-for-woocommerce' );
	return array_merge(
		array( 'tvarkom-csa' => '<a href="' . esc_url( admin_url( 'admin.php?page=tvarkom-csa' ) ) . '">' . esc_html( $label ) . '</a>' ),
		$links
	);
}
