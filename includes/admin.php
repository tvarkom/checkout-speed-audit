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
 *
 * @return void
 */
function tvarkom_csa_admin_menu() {
	add_submenu_page(
		'woocommerce',
		__( 'Checkout Speed Audit', 'tvarkom-checkout-speed-audit-for-woocommerce' ),
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
	$try_later = __( 'Try again later, or email support@tvarkom.com.', 'tvarkom-checkout-speed-audit-for-woocommerce' );
	return array(
		'saved'                  => array( 'success', __( 'Settings saved.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'pending'                => array( 'info', __( 'Your audit is being prepared in the background. Reload this page in a minute to see if it was sent.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'queued'                 => array( 'success', __( 'Partial Scan requested. The report will arrive by email in a few minutes.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'queued_full'            => array( 'success', __( 'Full Scan requested. The report will arrive by email in a few minutes.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		/* translators: %s: date a new scan can run. */
		'too_soon'               => array( 'info', __( 'You can request one report per week. Your next scan is available on %s.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'in_progress'            => array( 'info', __( 'A report for this store is being generated right now, so this click didn\'t start another one. It will arrive by email in a few minutes.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'audit_running'          => array( 'info', __( 'An audit for this store is already running, so this click didn\'t send another one. Reload this page in a minute to see its result.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'invalid_email'          => array( 'error', __( 'Enter a valid email address you read, e.g. you@company.com.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'invalid_website'        => array( 'error', __( 'This store can\'t be audited: its address must be a public https domain, not localhost or an IP address.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'invalid_verify_url'     => array( 'error', __( 'This store can\'t be registered: its REST API is on a different domain than the store.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'verification_failed'    => array( 'error', __( 'We couldn\'t confirm this domain. Tvarkom must be able to reach /wp-json/tvarkom/v1/verify on your store; check that the REST API isn\'t blocked by a security plugin or firewall.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'registration_not_found' => array( 'error', __( 'The registration expired. Click Partial Scan or Full Scan again.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'origin_taken'           => array( 'error', __( 'This domain is already set up with Tvarkom another way. Email support@tvarkom.com.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'invalid_key'            => array( 'error', __( 'Your key was replaced or revoked. Run a scan to register this store again.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'key_expired'            => array( 'error', __( 'Your API key has expired. Email support@tvarkom.com to renew it.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'client_disabled'        => array( 'error', __( 'Audits are paused for this store. Email support@tvarkom.com.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'website_not_allowed'    => array( 'error', __( 'This store\'s address changed since it was registered. Run a scan to register it again.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'limit_reached'          => array( 'error', __( 'This store has reached its audit limit for now.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) . ' ' . $try_later ),
		'rate_limited'           => array( 'error', __( 'Too many audits or registrations for this store or email today.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) . ' ' . $try_later ),
		'not_woocommerce'        => array( 'error', __( 'Our scanner couldn\'t detect WooCommerce on your public homepage.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'busy'                   => array( 'error', __( 'The scanner is busy right now. Try again in a few minutes.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'network'                => array( 'error', __( 'Couldn\'t reach Tvarkom. Check that your server can make outgoing HTTPS requests and try again.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		'server'                 => array( 'error', __( 'Something went wrong on our side.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) . ' ' . $try_later ),
	);
}

/**
 * A notice's text: for a re-sent report, with the date it was generated and the date a new one
 * can be (from the last result).
 *
 * @param string               $code The result code.
 * @param string               $text Its message.
 * @param array<string, mixed> $last The stored last result.
 * @return string
 */
function tvarkom_csa_notice_text( $code, $text, array $last ) {
	$day = function ( $key ) use ( $last ) {
		return ! empty( $last[ $key ] ) ? wp_date( get_option( 'date_format' ), (int) $last[ $key ] ) : '?';
	};
	return 'too_soon' === $code ? sprintf( $text, $day( 'next_eligible_at' ) ) : $text;
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
 * The privacy points shown on the plugin page and suggested for the site's privacy policy.
 * Keep them in line with legal/privacy-policy.txt.
 *
 * @return string[]
 */
function tvarkom_csa_privacy_points() {
	return array(
		__( 'A copy of each report email goes to Tvarkom\'s report mailbox (checkoutspeedaudit@tvarkom.com) for support and records, and is deleted after 12 months. Failed audits are deleted 15 days after they fail.', 'tvarkom-checkout-speed-audit-for-woocommerce' ),
		__( 'Each audit request (store, page addresses and time) is kept for 24 months. The email and IP address in it are erased after 90 days.', 'tvarkom-checkout-speed-audit-for-woocommerce' ),
		__( 'The report email is used only to send reports and answer support questions, never for marketing. It is deleted after 12 months without activity.', 'tvarkom-checkout-speed-audit-for-woocommerce' ),
		__( 'Reports are emailed to the report email, with that copy to Tvarkom. Nobody else receives them.', 'tvarkom-checkout-speed-audit-for-woocommerce' ),
		__( 'Customer data, order contents and revenue are never sent.', 'tvarkom-checkout-speed-audit-for-woocommerce' ),
	);
}

/**
 * The URL of one of the legal texts that ship with the plugin (legal/). The readme links their
 * copies on WordPress.org.
 *
 * @param string $file File name in legal/: terms-of-use.txt or privacy-policy.txt.
 * @return string
 */
function tvarkom_csa_legal_url( $file ) {
	return plugins_url( 'legal/' . $file, TVARKOM_CSA_FILE );
}

/**
 * Renders the terms and privacy notice shown before an audit is run.
 *
 * @return void
 */
function tvarkom_csa_render_legal() {
	$link = '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>';
	?>
	<p class="description">
		<?php
		printf(
			/* translators: 1: link to the Terms of Use, 2: link to the Privacy Policy. */
			esc_html__( 'By running an audit you agree to Tvarkom\'s %1$s. Tvarkom\'s %2$s explains what it keeps and for how long.', 'tvarkom-checkout-speed-audit-for-woocommerce' ),
			sprintf( $link, esc_url( tvarkom_csa_legal_url( 'terms-of-use.txt' ) ), esc_html__( 'Terms of Use', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			sprintf( $link, esc_url( tvarkom_csa_legal_url( 'privacy-policy.txt' ) ), esc_html__( 'Privacy Policy', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		);
		?>
	</p>
	<details>
		<summary><?php esc_html_e( 'Privacy summary', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></summary>
		<ul class="ul-disc">
			<?php foreach ( tvarkom_csa_privacy_points() as $point ) : ?>
				<li><?php echo esc_html( $point ); ?></li>
			<?php endforeach; ?>
			<li><?php esc_html_e( 'Privacy requests: privacy@tvarkom.com. Help with the plugin or a report: support@tvarkom.com.', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></li>
		</ul>
	</details>
	<?php
}

/**
 * Suggests text for the site's privacy policy (Settings → Privacy → Policy Guide).
 *
 * @return void
 */
function tvarkom_csa_privacy_policy_content() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}
	$content  = '<p>' . esc_html__( 'This site uses the Checkout Speed Audit plugin, by Tvarkom. When an administrator runs a scan, the store\'s address, its cart and checkout page addresses and the report email are sent to Tvarkom (MB Tvarkom Software, Lithuania). A Full Scan also sends aggregate store data, such as software versions, database sizes, background task counts and an order-count range, and a profile of one checkout page load. No data about the store\'s customers is sent.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) . '</p>';
	$content .= '<ul>';
	foreach ( tvarkom_csa_privacy_points() as $point ) {
		$content .= '<li>' . esc_html( $point ) . '</li>';
	}
	$content .= '</ul>';
	$content .= '<p>' . sprintf(
		/* translators: %s: link to Tvarkom's Privacy Policy. */
		esc_html__( 'Details: %s.', 'tvarkom-checkout-speed-audit-for-woocommerce' ),
		'<a href="' . esc_url( tvarkom_csa_legal_url( 'privacy-policy.txt' ) ) . '">' . esc_html__( 'Tvarkom Privacy Policy', 'tvarkom-checkout-speed-audit-for-woocommerce' ) . '</a>'
	) . '</p>';
	wp_add_privacy_policy_content( 'Checkout Speed Audit', wp_kses_post( $content ) );
}

/**
 * The note under the buttons: what each scan sends. Results are emailed, never shown here.
 *
 * @return void
 */
function tvarkom_csa_render_scan_note() {
	?>
	<p class="description"><?php esc_html_e( 'Both scans send your store address, its cart and checkout page addresses and the report email to Tvarkom (tvarkom.com), and email you the report as a PDF. Nothing is sent until you click a scan button.', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></p>
	<p class="description"><?php esc_html_e( 'Full Scan also sends store data: PHP, WordPress and WooCommerce versions; database, options table and autoloaded options sizes, with the names and sizes of the largest autoloaded options and of database tables left by removed plugins; the number of active plugins and the object cache type; background task and WP-Cron counts, and the names of failed and late tasks, with error messages stripped of emails, links, IP addresses and long numbers; PHP settings such as the memory limit; your monthly order volume as a range (e.g. 50–200); and a profile of one checkout page load on your server: plugin, theme and hook names with their timings, database query shapes with every value replaced by "?", the host names of outside services called, and object cache hit and miss counts.', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></p>
	<p class="description"><?php esc_html_e( 'Never customer data, order details, revenue, option contents or task arguments.', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></p>
	<?php
}

/**
 * Renders the page.
 *
 * @return void
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
	$status = '' !== $settings['api_key'] ? tvarkom_csa_status( '', 5 ) : null;
	// One scan a week: until the next one is possible, say when (next to the last result) and
	// disable the scan buttons.
	$next_scan = tvarkom_csa_next_scan_at( $status );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Checkout Speed Audit', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></h1>

		<?php if ( isset( $messages[ $notice ] ) ) : ?>
			<div class="notice notice-<?php echo esc_attr( $messages[ $notice ][0] ); ?>"><p><?php echo esc_html( tvarkom_csa_notice_text( $notice, $messages[ $notice ][1], $settings['last'] ) ); ?></p></div>
		<?php endif; ?>
		<?php if ( $next_scan > 0 && 'too_soon' !== $notice ) : ?>
			<div class="notice notice-info"><p><?php echo esc_html( tvarkom_csa_notice_text( 'too_soon', $messages['too_soon'][1], array( 'next_eligible_at' => $next_scan ) ) ); ?></p></div>
		<?php endif; ?>

		<p><?php esc_html_e( 'Get a free store health report from Tvarkom, scored from 0 to 100 and emailed to you as a PDF: server and checkout speed, caching and page weight and, with a Full Scan, your database, PHP setup, background tasks, a checkout profile and a fix plan.', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></p>
		<p><?php esc_html_e( 'The scan runs on Tvarkom\'s servers and loads your public pages like a visitor would. Nothing on your store is changed. You can request one report per week, with either scan.', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></p>

		<form id="tvarkom-csa-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="tvarkom_csa_form">
			<?php wp_nonce_field( 'tvarkom_csa_form' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Store', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></th>
					<td><code><?php echo esc_html( home_url( '/' ) ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><label for="csa-email"><?php esc_html_e( 'Report email', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></label></th>
					<td>
						<input type="email" id="csa-email" name="csa_email" class="regular-text" required value="<?php echo esc_attr( $settings['email'] ); ?>">
						<p class="description"><?php esc_html_e( 'Reports go here. Change it if the site\'s admin email isn\'t one you read.', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></p>
					</td>
				</tr>
			</table>
			<?php tvarkom_csa_render_legal(); ?>
			<p class="submit">
				<button type="submit" name="csa_do" value="partial" class="button" <?php disabled( $next_scan > 0 ); ?>><?php esc_html_e( 'Partial Scan', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></button>
				<button type="submit" name="csa_do" value="full" class="button button-primary" <?php disabled( $next_scan > 0 ); ?>><?php esc_html_e( 'Full Scan', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></button>
				<button type="submit" name="csa_do" value="save" class="button"><?php esc_html_e( 'Save settings', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></button>
			</p>
			<?php tvarkom_csa_render_scan_note(); ?>
		</form>

	</div>
	<?php
}

/**
 * Saves the settings and, for the audit button, registers the store if needed and
 * requests the audit.
 *
 * @return void
 */
function tvarkom_csa_handle_form() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'tvarkom-checkout-speed-audit-for-woocommerce' ), 403 );
	}
	check_admin_referer( 'tvarkom_csa_form' );

	$email = isset( $_POST['csa_email'] ) ? sanitize_email( wp_unslash( $_POST['csa_email'] ) ) : '';
	$do    = isset( $_POST['csa_do'] ) ? sanitize_key( wp_unslash( $_POST['csa_do'] ) ) : 'save';

	if ( ! is_email( $email ) ) {
		tvarkom_csa_redirect( 'invalid_email' );
	}
	tvarkom_csa_update_settings( array( 'email' => $email ) );
	if ( ! in_array( $do, array( 'partial', 'full' ), true ) ) {
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
	// audit, and the admin gets the real result at once.
	wc_set_time_limit( 120 );
	tvarkom_csa_run_audit( 'full' === $do );
	tvarkom_csa_audit_unlock();
	$last = tvarkom_csa_settings()['last'];
	tvarkom_csa_redirect( isset( $last['code'] ) ? (string) $last['code'] : 'server' );
}

/**
 * Back to the plugin page with a result code.
 *
 * @param string                    $code Key of tvarkom_csa_messages().
 * @param array<string, int|string> $args Extra query arguments.
 * @return never
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
 * @return void
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
		if (!event.submitter || (event.submitter.value !== "partial" && event.submitter.value !== "full")) { return; }
		if (running) { event.preventDefault(); return; }
		running = true;
		event.submitter.textContent = ' . wp_json_encode( __( 'Running the scan… this can take up to a minute', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ) . ';
		event.submitter.setAttribute("aria-busy", "true");
	});
})();'
	);
}
