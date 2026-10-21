<?php
/**
 * Admin page: WooCommerce → Speed Audit.
 *
 * @package CheckoutSpeedAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the page under the WooCommerce menu.
 */
function tvarkom_csa_admin_menu() {
	add_submenu_page(
		'woocommerce',
		__( 'Tvarkom Checkout Speed Audit', 'tvarkom-checkout-speed-audit-for-woocommerce' ),
		__( 'Speed Audit', 'tvarkom-checkout-speed-audit-for-woocommerce' ),
		'manage_woocommerce',
		'tvarkom-csa',
		'tvarkom_csa_render_page'
	);
}

/**
 * Notice for each result code from the gateway or this plugin.
 *
 * @return array<string, array{0: string, 1: string}> Code => [notice type, message].
 */
function tvarkom_csa_messages() {
	$try_later = __( 'Try again later, or email hello@tvarkom.com.', 'tvarkom-checkout-speed-audit-for-woocommerce' );
	return array(
		'saved'                  => array( 'success', __( 'Settings saved.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'pending'                => array( 'info', __( 'Your audit is being prepared in the background. Reload this page in a minute to see if it was sent.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'queued'                 => array( 'success', __( 'Audit requested. The report will arrive by email in a few minutes.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'queued_detailed'        => array( 'success', __( 'Pro audit requested. The detailed report will arrive by email in a few minutes.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'audit_running'          => array( 'info', __( 'An audit for this store is already running, so this click didn\'t send another one. Reload this page in a minute to see its result.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'profiled'               => array( 'success', __( 'Checkout profiled. The result is under Store diagnostics below.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'profile_failed'         => array( 'warning', __( 'The checkout profile couldn\'t run. The reason is under Store diagnostics below.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'consent_required'       => array( 'error', __( 'Pro audits need your store data. Tick "Include store data in my audit" below and run the audit again. No Pro audit was used.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'license_saved'          => array( 'success', __( 'License key saved.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'license_invalid'        => array( 'error', __( 'That license key isn\'t valid. Paste it exactly as it appears in your email, starting with tvk_.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'license_other_store'    => array( 'error', __( 'That license key belongs to a different store address.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'invalid_email'          => array( 'error', __( 'Enter a valid email address you read, e.g. you@company.com.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'invalid_website'        => array( 'error', __( 'This store can\'t be audited: its address must be a public https domain, not localhost or an IP address.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'invalid_verify_url'     => array( 'error', __( 'This store can\'t be registered: its REST API is on a different domain than the store.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'verification_failed'    => array( 'error', __( 'We couldn\'t confirm this domain. Tvarkom must be able to reach /wp-json/tvarkom/v1/verify on your store; check that the REST API isn\'t blocked by a security plugin or firewall.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'registration_not_found' => array( 'error', __( 'The registration expired. Click Run free audit again.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'origin_taken'           => array( 'error', __( 'This domain is already set up with Tvarkom another way. Email hello@tvarkom.com.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'invalid_key'            => array( 'error', __( 'Your key was replaced or revoked. If you bought Pro, paste the license key from your email below; otherwise click Run free audit to register this store again (a Pro plan is kept).', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'key_expired'            => array( 'error', __( 'Your API key has expired. Email hello@tvarkom.com to renew it.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'client_disabled'        => array( 'error', __( 'Audits are paused for this store. Email hello@tvarkom.com.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'website_not_allowed'    => array( 'error', __( 'This store\'s address changed since it was registered. Click Run free audit to register it again.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'limit_reached'          => array( 'error', __( 'You\'ve used the 4 audits the free plan allows in the last 30 days. Each one becomes available again 30 days after it ran.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'rate_limited'           => array( 'error', __( 'Too many audits or registrations for this store or email today.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) . ' ' . $try_later ),
		'not_woocommerce'        => array( 'error', __( 'Our scanner couldn\'t detect WooCommerce on your public homepage.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'busy'                   => array( 'error', __( 'The scanner is busy right now. Try again in a few minutes.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'network'                => array( 'error', __( 'Couldn\'t reach Tvarkom. Check that your server can make outgoing HTTPS requests and try again.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'server'                 => array( 'error', __( 'Something went wrong on our side.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) . ' ' . $try_later ),
	);
}

/**
 * "tvk_abcd…wxyz"
 *
 * @param string $key API key.
 * @return string
 */
function tvarkom_csa_mask_key( $key ) {
	return strlen( $key ) > 12 ? substr( $key, 0, 8 ) . '…' . substr( $key, -4 ) : $key;
}

/**
 * "15 October 2027" in the site's date format, from an RFC 3339 time.
 *
 * @param string $time RFC 3339 time.
 * @return string
 */
function tvarkom_csa_date( $time ) {
	$ts = strtotime( (string) $time );
	return $ts ? wp_date( get_option( 'date_format' ), $ts ) : '';
}

/**
 * Whether the store has Pro audits left, from tvarkom_csa_status().
 *
 * @param array|string $status Status.
 * @return bool
 */
function tvarkom_csa_is_pro( $status ) {
	return is_array( $status ) && 'pro' === $status['plan'];
}

/**
 * Plan line under the API key: plan, Pro audits left, free usage and the link to buy Pro.
 *
 * @param array|string $status From tvarkom_csa_status().
 */
function tvarkom_csa_render_plan( $status ) {
	if ( ! is_array( $status ) ) {
		echo '<p class="description">' . esc_html__( 'Plan details are unavailable right now.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) . '</p>';
		return;
	}
	$parts = array();
	if ( tvarkom_csa_is_pro( $status ) ) {
		$parts[] = sprintf(
			/* translators: 1: Pro audits left, 2: Pro audits bought, 3: date. */
			__( 'Plan: Pro, %1$d of %2$d detailed audits left, to use by %3$s. Every audit you run now is a detailed one.', 'tvarkom-checkout-speed-audit-for-woocommerce' ),
			(int) $status['pro_scans_left'],
			(int) $status['pro_scans_total'],
			tvarkom_csa_date( $status['license_expires_at'] )
		);
	} else {
		if ( isset( $status['license_ended'] ) && 'used' === $status['license_ended'] ) {
			$parts[] = __( 'You\'ve used all your Pro audits. The store is on the free plan.', 'tvarkom-checkout-speed-audit-for-woocommerce' );
		} elseif ( isset( $status['license_ended'] ) && 'expired' === $status['license_ended'] ) {
			/* translators: %s: date. */
			$parts[] = sprintf( __( 'Your Pro audits ended on %s. The store is on the free plan.', 'tvarkom-checkout-speed-audit-for-woocommerce' ), tvarkom_csa_date( $status['license_expires_at'] ) );
		} else {
			$parts[] = __( 'Plan: free.', 'tvarkom-checkout-speed-audit-for-woocommerce' );
		}
		$limit = isset( $status['monthly_limit'] ) ? (int) $status['monthly_limit'] : 0;
		if ( $limit > 0 ) {
			/* translators: 1: audits used, 2: audits allowed. */
			$parts[] = sprintf( __( '%1$d of %2$d free audits used in the last 30 days.', 'tvarkom-checkout-speed-audit-for-woocommerce' ), (int) $status['audits_last_30_days'], $limit );
		}
	}
	echo '<p class="description">' . esc_html( implode( ' ', $parts ) ) . '</p>';

	if ( ! empty( $status['upgrade_url'] ) ) {
		printf(
			'<p><a class="button" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a> <span class="description">%3$s</span></p>',
			esc_url( $status['upgrade_url'] ),
			esc_html__( 'Buy Pro', 'tvarkom-checkout-speed-audit-for-woocommerce' ),
			esc_html__( 'Pro: a one-time payment for 4 detailed reports to use within 12 months. Tvarkom analyzes your Store diagnostics and a checkout profile, and sends a report with a fix plan: what to fix first, step by step, with estimated hours.', 'tvarkom-checkout-speed-audit-for-woocommerce' )
		);
	}
}

/**
 * Renders the page.
 */
function tvarkom_csa_render_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$settings = tvarkom_csa_settings();
	$messages = tvarkom_csa_messages();

	// Read-only display: the result code set by tvarkom_csa_handle_form(), validated against the known codes.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$notice = isset( $_GET['csa_result'] ) ? sanitize_key( wp_unslash( $_GET['csa_result'] ) ) : '';
	if ( '' === $notice && ! empty( $settings['last']['code'] ) ) {
		$notice = $settings['last']['code'];
	}
	// A code this version has no text for (the gateway's "internal", "upstream_error", …)
	// still tells the admin the audit didn't go through.
	if ( '' !== $notice && ! isset( $messages[ $notice ] ) ) {
		$notice = 'server';
	}
	$preview = isset( $_GET['csa_preview'], $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'tvarkom_csa_preview' );
	$status  = '' !== $settings['api_key'] ? tvarkom_csa_status( '', 5 ) : null;
	$is_pro  = tvarkom_csa_is_pro( $status );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Tvarkom Checkout Speed Audit', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></h1>

		<?php if ( isset( $messages[ $notice ] ) ) : ?>
			<div class="notice notice-<?php echo esc_attr( $messages[ $notice ][0] ); ?>"><p><?php echo esc_html( $messages[ $notice ][1] ); ?></p></div>
		<?php endif; ?>

		<p><?php esc_html_e( 'Get a free store health report from Tvarkom, scored from 0 to 100: server and checkout speed, caching, page weight and, if you allow it below, your database, PHP setup and background tasks.', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></p>
		<p><?php esc_html_e( 'The scan runs on Tvarkom\'s servers and loads your public pages like a visitor would. Nothing on your store is changed. Free plan: 4 audits per 30 days.', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></p>

		<form id="tvarkom-csa-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="tvarkom_csa_form">
			<?php wp_nonce_field( 'tvarkom_csa_form' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Store', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></th>
					<td><code><?php echo esc_html( home_url( '/' ) ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'API key', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></th>
					<td>
						<?php if ( '' !== $settings['api_key'] ) : ?>
							<code><?php echo esc_html( tvarkom_csa_mask_key( $settings['api_key'] ) ); ?></code>
							<?php tvarkom_csa_render_plan( $status ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Not registered yet. Your first audit registers this store and issues a free key for this domain.', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="csa-license"><?php esc_html_e( 'License key', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></label></th>
					<td>
						<input type="password" id="csa-license" name="csa_license" class="regular-text" autocomplete="off" placeholder="tvk_…" value="">
						<p class="description"><?php esc_html_e( 'Only if you bought Pro before installing this plugin: paste the key from your email here. It replaces the current key. If the store was already registered, Pro appears above by itself.', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="csa-email"><?php esc_html_e( 'Report email', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></label></th>
					<td>
						<input type="email" id="csa-email" name="csa_email" class="regular-text" required value="<?php echo esc_attr( $settings['email'] ); ?>">
						<p class="description"><?php esc_html_e( 'Reports go here. Change it if the site\'s admin email isn\'t one you read.', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Store data', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></th>
					<td>
						<label><input type="checkbox" name="csa_consent" value="1" <?php checked( $settings['consent'] ); ?>> <?php esc_html_e( 'Include store data in my audit', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></label>
						<p class="description">
							<?php esc_html_e( 'Sends counts and settings only: PHP, WordPress and WooCommerce versions, database and options table sizes, autoloaded options size, number of active plugins, object cache type, background task and cron counts, and your monthly order volume as a range (e.g. 50–200). Never customer data, order details or revenue.', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?>
							<?php esc_html_e( 'On a Pro audit (required for Pro) it also sends the Store diagnostics shown below, with error messages stripped of emails, links, IP addresses and long numbers, and a fresh checkout profile: plugin, theme and hook names with their timings, database query shapes with every value replaced by "?", the host names of outside services called, and object cache hit and miss counts. Never option contents or task arguments.', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=tvarkom-csa&csa_preview=1' ), 'tvarkom_csa_preview' ) ); ?>"><?php esc_html_e( 'Preview data', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></a>
						</p>
					</td>
				</tr>
			</table>
			<p class="description"><?php esc_html_e( 'When you run an audit, your store address, its cart and checkout URLs, the report email and (if checked) the store data above are sent to Tvarkom (tvarkom.com). Nothing is sent until you click the button.', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></p>
			<p class="submit">
				<button type="submit" name="csa_do" value="audit" class="button button-primary"><?php echo esc_html( $is_pro ? __( 'Run Pro audit', 'tvarkom-checkout-speed-audit-for-woocommerce' ) : __( 'Run free audit', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ); ?></button>
				<button type="submit" name="csa_do" value="save" class="button"><?php esc_html_e( 'Save settings', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></button>
			</p>
		</form>

		<?php if ( $preview ) : ?>
			<h2><?php esc_html_e( 'Store data that would be sent', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></h2>
			<pre style="background:#fff;border:1px solid #c3c4c7;padding:12px;max-width:720px;overflow:auto;"><?php echo esc_html( wp_json_encode( tvarkom_csa_collect_metrics(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
			<h2><?php esc_html_e( 'Added on a Pro audit', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></h2>
			<pre style="background:#fff;border:1px solid #c3c4c7;padding:12px;max-width:720px;overflow:auto;"><?php echo esc_html( wp_json_encode( tvarkom_csa_collect_detailed( false ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
			<p class="description"><?php esc_html_e( 'Sent only on a Pro audit. The checkout profile is measured when the audit runs, so it isn\'t shown here.', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></p>
		<?php endif; ?>

		<?php tvarkom_csa_render_diagnostics(); ?>
	</div>
	<?php
}

/**
 * Saves the settings and, for the audit button, registers the store if needed and
 * requests the audit.
 */
function tvarkom_csa_handle_form() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'tvarkom-checkout-speed-audit-for-woocommerce' ), 403 );
	}
	check_admin_referer( 'tvarkom_csa_form' );

	$email   = isset( $_POST['csa_email'] ) ? sanitize_email( wp_unslash( $_POST['csa_email'] ) ) : '';
	$license = isset( $_POST['csa_license'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['csa_license'] ) ) ) : '';
	$consent = ! empty( $_POST['csa_consent'] );
	$do      = isset( $_POST['csa_do'] ) ? sanitize_key( wp_unslash( $_POST['csa_do'] ) ) : 'save';

	if ( ! is_email( $email ) ) {
		tvarkom_csa_redirect( 'invalid_email' );
	}
	tvarkom_csa_update_settings(
		array(
			'email'   => $email,
			'consent' => $consent,
		)
	);
	if ( '' !== $license ) {
		$result = tvarkom_csa_save_license( $license );
		if ( 'license_saved' !== $result || 'audit' !== $do ) {
			tvarkom_csa_redirect( $result );
		}
	}
	if ( 'audit' !== $do ) {
		tvarkom_csa_redirect( 'saved' );
	}

	// One audit at a time, registration included (tvarkom_csa_audit_lock()).
	if ( ! tvarkom_csa_audit_lock() ) {
		tvarkom_csa_redirect( 'audit_running' );
	}
	if ( '' === tvarkom_csa_settings()['api_key'] ) {
		$error = tvarkom_csa_register( $email );
		if ( '' !== $error ) {
			tvarkom_csa_audit_unlock();
			tvarkom_csa_redirect( $error );
		}
	}

	// Run the audit now rather than in the background: WordPress's background queue
	// (Action Scheduler, WP-Cron) is often what's broken or backed up on the stores we
	// audit, and the admin gets the real result at once. tvarkom_csa_run_audit() also
	// refuses a Pro audit without the store data, without using one.
	wc_set_time_limit( 120 );
	tvarkom_csa_run_audit();
	tvarkom_csa_audit_unlock();
	$last = tvarkom_csa_settings()['last'];
	tvarkom_csa_redirect( isset( $last['code'] ) ? (string) $last['code'] : 'server' );
}

/**
 * Back to the plugin page with a result code.
 *
 * @param string $code Key of tvarkom_csa_messages().
 * @param array  $args Extra query arguments.
 */
function tvarkom_csa_redirect( $code, array $args = array() ) {
	wp_safe_redirect( add_query_arg( array_merge( array( 'csa_result' => $code ), $args ), admin_url( 'admin.php?page=tvarkom-csa' ) ) );
	exit;
}

/**
 * On the plugin page: while an audit runs (it can take up to a minute), the button
 * says so and a second click is ignored.
 *
 * @param string $hook_suffix Current admin page.
 */
function tvarkom_csa_admin_scripts( $hook_suffix ) {
	if ( 'woocommerce_page_tvarkom-csa' !== $hook_suffix ) {
		return;
	}
	wp_register_script( 'tvarkom-csa-admin', false, array(), TVARKOM_CSA_VERSION, true );
	wp_enqueue_script( 'tvarkom-csa-admin' );
	wp_add_inline_script(
		'tvarkom-csa-admin',
		'(function () {
	var form = document.getElementById("tvarkom-csa-form");
	if (!form) { return; }
	var running = false;
	form.addEventListener("submit", function (event) {
		if (!event.submitter || event.submitter.value !== "audit") { return; }
		if (running) { event.preventDefault(); return; }
		running = true;
		event.submitter.textContent = ' . wp_json_encode( __( 'Running the audit… this can take up to a minute', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ) . ';
		event.submitter.setAttribute("aria-busy", "true");
	});
})();'
	);
}
