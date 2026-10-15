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
		__( 'Checkout Speed Audit', 'checkout-speed-audit' ),
		__( 'Speed Audit', 'checkout-speed-audit' ),
		'manage_woocommerce',
		'tvarkom-csa',
		'tvarkom_csa_render_page'
	);
}
add_action( 'admin_menu', 'tvarkom_csa_admin_menu', 99 );

/**
 * Notice for each result code from the gateway or this plugin.
 *
 * @return array<string, array{0: string, 1: string}> Code => [notice type, message].
 */
function tvarkom_csa_messages() {
	$try_later = __( 'Try again later, or email hello@tvarkom.com.', 'checkout-speed-audit' );
	return array(
		'saved'                  => array( 'success', __( 'Settings saved.', 'checkout-speed-audit' ) ),
		'pending'                => array( 'info', __( 'Your audit is being prepared in the background. Reload this page in a minute to see if it was sent.', 'checkout-speed-audit' ) ),
		'queued'                 => array( 'success', __( 'Audit requested. The report will arrive by email in a few minutes.', 'checkout-speed-audit' ) ),
		'queued_detailed'        => array( 'success', __( 'Detailed Pro audit requested. The report will arrive by email in a few minutes.', 'checkout-speed-audit' ) ),
		'license_saved'          => array( 'success', __( 'License key saved.', 'checkout-speed-audit' ) ),
		'license_invalid'        => array( 'error', __( 'That license key isn\'t valid. Paste it exactly as it appears in your email, starting with tvk_.', 'checkout-speed-audit' ) ),
		'license_other_store'    => array( 'error', __( 'That license key belongs to a different store address.', 'checkout-speed-audit' ) ),
		'invalid_email'          => array( 'error', __( 'Enter a valid email address you read, e.g. you@company.com.', 'checkout-speed-audit' ) ),
		'invalid_website'        => array( 'error', __( 'This store can\'t be audited: its address must be a public https domain, not localhost or an IP address.', 'checkout-speed-audit' ) ),
		'invalid_verify_url'     => array( 'error', __( 'This store can\'t be registered: its REST API is on a different domain than the store.', 'checkout-speed-audit' ) ),
		'verification_failed'    => array( 'error', __( 'We couldn\'t confirm this domain. Tvarkom must be able to reach /wp-json/tvarkom/v1/verify on your store; check that the REST API isn\'t blocked by a security plugin or firewall.', 'checkout-speed-audit' ) ),
		'registration_not_found' => array( 'error', __( 'The registration expired. Click Run free audit again.', 'checkout-speed-audit' ) ),
		'origin_taken'           => array( 'error', __( 'This domain is already set up with Tvarkom another way. Email hello@tvarkom.com.', 'checkout-speed-audit' ) ),
		'invalid_key'            => array( 'error', __( 'Your key was replaced or revoked. If you bought Pro, paste the license key from your email below; otherwise click Run free audit to register this store again (a Pro plan is kept).', 'checkout-speed-audit' ) ),
		'key_expired'            => array( 'error', __( 'Your API key has expired. Email hello@tvarkom.com to renew it.', 'checkout-speed-audit' ) ),
		'client_disabled'        => array( 'error', __( 'Audits are paused for this store. Email hello@tvarkom.com.', 'checkout-speed-audit' ) ),
		'website_not_allowed'    => array( 'error', __( 'This store\'s address changed since it was registered. Click Run free audit to register it again.', 'checkout-speed-audit' ) ),
		'limit_reached'          => array( 'error', __( 'You\'ve used the audits your plan allows for the last 30 days: 4 on the free plan, 1 detailed audit on Pro. The plan line below shows when the next one is available.', 'checkout-speed-audit' ) ),
		'rate_limited'           => array( 'error', __( 'Too many audits or registrations for this store or email today.', 'checkout-speed-audit' ) . ' ' . $try_later ),
		'not_woocommerce'        => array( 'error', __( 'Our scanner couldn\'t detect WooCommerce on your public homepage.', 'checkout-speed-audit' ) ),
		'busy'                   => array( 'error', __( 'The scanner is busy right now. Try again in a few minutes.', 'checkout-speed-audit' ) ),
		'network'                => array( 'error', __( 'Couldn\'t reach Tvarkom. Check that your server can make outgoing HTTPS requests and try again.', 'checkout-speed-audit' ) ),
		'server'                 => array( 'error', __( 'Something went wrong on our side.', 'checkout-speed-audit' ) . ' ' . $try_later ),
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
 * Plan line under the API key: plan, usage, Pro expiry and the upgrade link.
 *
 * @param array|string $status From tvarkom_csa_status().
 */
function tvarkom_csa_render_plan( $status ) {
	if ( ! is_array( $status ) ) {
		echo '<p class="description">' . esc_html__( 'Plan details are unavailable right now.', 'checkout-speed-audit' ) . '</p>';
		return;
	}
	$used  = (int) $status['audits_last_30_days'];
	$limit = isset( $status['monthly_limit'] ) ? (int) $status['monthly_limit'] : 0;
	$parts = array();
	if ( 'pro' === $status['plan'] ) {
		/* translators: %s: date. */
		$parts[] = sprintf( __( 'Plan: Pro, valid until %s.', 'checkout-speed-audit' ), tvarkom_csa_date( $status['license_expires_at'] ) );
		$parts[] = ! empty( $status['detailed_available'] )
			? __( 'Your detailed Pro audit for this 30-day period is available.', 'checkout-speed-audit' )
			/* translators: %s: date. */
			: sprintf( __( 'Your next detailed Pro audit is available from %s.', 'checkout-speed-audit' ), tvarkom_csa_date( $status['next_detailed_at'] ) );
	} elseif ( ! empty( $status['license_expired'] ) ) {
		/* translators: %s: date. */
		$parts[] = sprintf( __( 'Your Pro license ended on %s. The store is on the free plan.', 'checkout-speed-audit' ), tvarkom_csa_date( $status['license_expires_at'] ) );
	} else {
		$parts[] = __( 'Plan: free.', 'checkout-speed-audit' );
	}
	if ( $limit > 0 ) {
		/* translators: 1: audits used, 2: audits allowed. */
		$parts[] = sprintf( __( '%1$d of %2$d audits used in the last 30 days.', 'checkout-speed-audit' ), $used, $limit );
	}
	echo '<p class="description">' . esc_html( implode( ' ', $parts ) ) . '</p>';

	if ( ! empty( $status['upgrade_url'] ) ) {
		$label = 'pro' === $status['plan'] ? __( 'Renew Pro license', 'checkout-speed-audit' ) : __( 'Get Pro license', 'checkout-speed-audit' );
		printf(
			'<p><a class="button" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a> <span class="description">%3$s</span></p>',
			esc_url( $status['upgrade_url'] ),
			esc_html( $label ),
			esc_html__( 'Pro: one detailed audit every 30 days, adding a checkout profile (which plugins, database queries and outside services take the server\'s time on your checkout page), the largest autoloaded options, leftover database tables, PHP settings, and failed and late background tasks. Your key is emailed after payment.', 'checkout-speed-audit' )
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
	$preview = isset( $_GET['csa_preview'], $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'tvarkom_csa_preview' );
	$status  = $preview && '' !== $settings['api_key'] ? tvarkom_csa_status() : null;
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Checkout Speed Audit', 'checkout-speed-audit' ); ?></h1>

		<?php if ( isset( $messages[ $notice ] ) ) : ?>
			<div class="notice notice-<?php echo esc_attr( $messages[ $notice ][0] ); ?>"><p><?php echo esc_html( $messages[ $notice ][1] ); ?></p></div>
		<?php endif; ?>

		<p><?php esc_html_e( 'Get a free store health report from Tvarkom, scored from 0 to 100: server and checkout speed, caching, page weight and, if you allow it below, your database, PHP setup and background tasks.', 'checkout-speed-audit' ); ?></p>
		<p><?php esc_html_e( 'The scan runs on Tvarkom\'s servers and loads your public pages like a visitor would. Nothing on your store is changed. Free plan: 4 audits per 30 days.', 'checkout-speed-audit' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="tvarkom_csa_form">
			<?php wp_nonce_field( 'tvarkom_csa_form' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Store', 'checkout-speed-audit' ); ?></th>
					<td><code><?php echo esc_html( home_url( '/' ) ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'API key', 'checkout-speed-audit' ); ?></th>
					<td>
						<?php if ( '' !== $settings['api_key'] ) : ?>
							<code><?php echo esc_html( tvarkom_csa_mask_key( $settings['api_key'] ) ); ?></code>
							<?php tvarkom_csa_render_plan( tvarkom_csa_status() ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Not registered yet. Your first audit registers this store and issues a free key for this domain.', 'checkout-speed-audit' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="csa-license"><?php esc_html_e( 'License key', 'checkout-speed-audit' ); ?></label></th>
					<td>
						<input type="password" id="csa-license" name="csa_license" class="regular-text" autocomplete="off" placeholder="tvk_…" value="">
						<p class="description"><?php esc_html_e( 'Bought Pro? Paste the key from your email here. It replaces the current key.', 'checkout-speed-audit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="csa-email"><?php esc_html_e( 'Report email', 'checkout-speed-audit' ); ?></label></th>
					<td>
						<input type="email" id="csa-email" name="csa_email" class="regular-text" required value="<?php echo esc_attr( $settings['email'] ); ?>">
						<p class="description"><?php esc_html_e( 'Reports go here. Change it if the site\'s admin email isn\'t one you read.', 'checkout-speed-audit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Store data', 'checkout-speed-audit' ); ?></th>
					<td>
						<label><input type="checkbox" name="csa_consent" value="1" <?php checked( $settings['consent'] ); ?>> <?php esc_html_e( 'Include store data in my audit', 'checkout-speed-audit' ); ?></label>
						<p class="description">
							<?php esc_html_e( 'Sends counts and settings only: PHP, WordPress and WooCommerce versions, database and options table sizes, autoloaded options size, number of active plugins, object cache type, background task and cron counts, and your monthly order volume as a range (e.g. 50–200). Never customer data, order details or revenue.', 'checkout-speed-audit' ); ?>
							<?php esc_html_e( 'On a Pro detailed audit it also sends the names and sizes of the 20 largest autoloaded options (not their contents), the names and sizes of database tables no active plugin seems to use, PHP memory, time-limit and OPcache settings, failed background tasks by name with their last error (emails, links, IP addresses and long numbers removed) and the names of late scheduled tasks.', 'checkout-speed-audit' ); ?>
							<?php esc_html_e( 'For the checkout profile, the plugin loads your checkout page once from your own server, with one product in a temporary cart that is never saved; nothing is ordered. It sends plugin, theme and hook names with their timings, database query shapes with every value replaced by "?", the host names of outside services called, and object cache hit and miss counts. Analytics or marketing plugins that track checkout visits on the server may count this load as one visit.', 'checkout-speed-audit' ); ?>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=tvarkom-csa&csa_preview=1' ), 'tvarkom_csa_preview' ) ); ?>"><?php esc_html_e( 'Preview data', 'checkout-speed-audit' ); ?></a>
						</p>
					</td>
				</tr>
			</table>
			<p class="description"><?php esc_html_e( 'When you run an audit, your store address, its cart and checkout URLs, the report email and (if checked) the store data above are sent to Tvarkom (tvarkom.com). Nothing is sent until you click the button.', 'checkout-speed-audit' ); ?></p>
			<p class="submit">
				<button type="submit" name="csa_do" value="audit" class="button button-primary"><?php esc_html_e( 'Run free audit', 'checkout-speed-audit' ); ?></button>
				<button type="submit" name="csa_do" value="save" class="button"><?php esc_html_e( 'Save settings', 'checkout-speed-audit' ); ?></button>
			</p>
		</form>

		<?php if ( $preview ) : ?>
			<h2><?php esc_html_e( 'Store data that would be sent', 'checkout-speed-audit' ); ?></h2>
			<pre style="background:#fff;border:1px solid #c3c4c7;padding:12px;max-width:720px;overflow:auto;"><?php echo esc_html( wp_json_encode( tvarkom_csa_collect_metrics(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
			<?php if ( is_array( $status ) && 'pro' === $status['plan'] ) : ?>
				<h2><?php esc_html_e( 'Added on your next detailed Pro audit', 'checkout-speed-audit' ); ?></h2>
				<pre style="background:#fff;border:1px solid #c3c4c7;padding:12px;max-width:720px;overflow:auto;"><?php echo esc_html( wp_json_encode( tvarkom_csa_collect_detailed( false ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
				<p class="description"><?php esc_html_e( 'The checkout profile is measured when the audit runs, so it isn\'t shown here.', 'checkout-speed-audit' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Saves the settings and, for "Run free audit", registers the store if needed and
 * schedules the audit in the background.
 */
function tvarkom_csa_handle_form() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'checkout-speed-audit' ), 403 );
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

	if ( '' === tvarkom_csa_settings()['api_key'] ) {
		$error = tvarkom_csa_register( $email );
		if ( '' !== $error ) {
			tvarkom_csa_redirect( $error );
		}
	}

	as_enqueue_async_action( TVARKOM_CSA_ACTION, array(), 'checkout-speed-audit', true );
	tvarkom_csa_update_settings( array( 'last' => array( 'code' => 'pending', 'time' => time() ) ) );
	tvarkom_csa_redirect( 'pending' );
}
add_action( 'admin_post_tvarkom_csa_form', 'tvarkom_csa_handle_form' );

/**
 * Back to the plugin page with a result code.
 *
 * @param string $code Key of tvarkom_csa_messages().
 */
function tvarkom_csa_redirect( $code ) {
	wp_safe_redirect( add_query_arg( 'csa_result', $code, admin_url( 'admin.php?page=tvarkom-csa' ) ) );
	exit;
}
