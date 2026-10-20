<?php
/**
 * Store diagnostics on the plugin page, free for every store: the detailed data
 * the plugin reads (largest autoloaded options, leftover tables, PHP settings,
 * failed and late background tasks) and, on request, a checkout profile. All of
 * it is read and shown locally; nothing here is sent to Tvarkom. A Pro audit
 * sends the same data to Tvarkom's service, which turns it into the report and
 * fix plan.
 *
 * @package CheckoutSpeedAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TVARKOM_CSA_LOCAL_PROFILE', 'tvarkom_csa_local_profile' );

/**
 * One small table.
 *
 * @param string[]   $head  Column headings.
 * @param string[][] $rows  Cells (plain text).
 * @param string     $empty Text when there are no rows.
 */
function tvarkom_csa_table( array $head, array $rows, $empty ) {
	if ( empty( $rows ) ) {
		echo '<p>' . esc_html( $empty ) . '</p>';
		return;
	}
	echo '<table class="widefat striped" style="max-width:960px"><thead><tr>';
	foreach ( $head as $h ) {
		echo '<th>' . esc_html( $h ) . '</th>';
	}
	echo '</tr></thead><tbody>';
	foreach ( $rows as $row ) {
		echo '<tr>';
		foreach ( $row as $i => $cell ) {
			echo 0 === $i ? '<td><code>' . esc_html( $cell ) . '</code></td>' : '<td>' . esc_html( $cell ) . '</td>';
		}
		echo '</tr>';
	}
	echo '</tbody></table>';
}

/**
 * "240 ms", "1.49 s"
 *
 * @param float|null $ms Milliseconds.
 * @return string
 */
function tvarkom_csa_ms( $ms ) {
	if ( null === $ms ) {
		return '—';
	}
	return $ms < 1000 ? round( $ms ) . ' ms' : number_format_i18n( $ms / 1000, 2 ) . ' s';
}

/**
 * Renders the Store diagnostics section: a button, or the diagnostics once asked for
 * (they query the database, which can take a moment on large hosts).
 */
function tvarkom_csa_render_diagnostics() {
	$show = isset( $_GET['csa_diagnostics'], $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'tvarkom_csa_diagnostics' );
	if ( ! $show ) {
		?>
		<h2><?php esc_html_e( 'Store diagnostics', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Your largest autoloaded options, leftover tables, PHP settings, failed and late background tasks, and a profile of your checkout, read from your store. Nothing in this section is sent to Tvarkom.', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></p>
		<p><a class="button" href="<?php echo esc_url( tvarkom_csa_diagnostics_url() ); ?>"><?php esc_html_e( 'Show store diagnostics', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></a></p>
		<?php
		return;
	}
	$d   = tvarkom_csa_collect_detailed( false );
	$php = $d['php_configuration'];
	?>
	<h2><?php esc_html_e( 'Store diagnostics', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Read from your store each time you open this page. Nothing in this section is sent to Tvarkom.', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></p>

	<h3><?php esc_html_e( 'Largest autoloaded options', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></h3>
	<p class="description"><?php esc_html_e( 'WordPress loads these on every page. "Likely plugin" is a guess from the name.', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></p>
	<?php
	tvarkom_csa_table(
		array( __( 'Option', 'tvarkom-checkout-speed-audit-for-woocommerce' ), __( 'Size', 'tvarkom-checkout-speed-audit-for-woocommerce' ), __( 'Likely plugin', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		array_map(
			function ( $k ) {
				return array( $k['option_name'], size_format( $k['size_bytes'] ), null === $k['associated_plugin'] ? __( 'Unknown', 'tvarkom-checkout-speed-audit-for-woocommerce' ) : $k['associated_plugin'] );
			},
			$d['top_autoloaded_keys']
		),
		__( 'No autoloaded options found.', 'tvarkom-checkout-speed-audit-for-woocommerce' )
	);
	?>

	<h3><?php esc_html_e( 'Tables no active plugin seems to use', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></h3>
	<p class="description"><?php esc_html_e( 'Often left behind by removed plugins. Check before deleting: some belong to plugins that are only switched off.', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></p>
	<?php
	tvarkom_csa_table(
		array( __( 'Table', 'tvarkom-checkout-speed-audit-for-woocommerce' ), __( 'Size', 'tvarkom-checkout-speed-audit-for-woocommerce' ), __( 'Rows (estimate)', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		array_map(
			function ( $t ) {
				return array( $t['table_name'], number_format_i18n( $t['size_mb'], 1 ) . ' MB', number_format_i18n( $t['estimated_row_count'] ) );
			},
			$d['orphaned_tables']
		),
		__( 'None found.', 'tvarkom-checkout-speed-audit-for-woocommerce' )
	);
	?>

	<h3><?php esc_html_e( 'PHP settings', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></h3>
	<?php
	tvarkom_csa_table(
		array( __( 'Setting', 'tvarkom-checkout-speed-audit-for-woocommerce' ), __( 'Value', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		array(
			array( 'memory_limit', $php['memory_limit'] ),
			array( 'WP_MEMORY_LIMIT', null === $php['wp_memory_limit'] ? __( 'not set', 'tvarkom-checkout-speed-audit-for-woocommerce' ) : $php['wp_memory_limit'] ),
			/* translators: %d: seconds. */
			array( 'max_execution_time', 0 === $php['max_execution_time'] ? __( 'no limit', 'tvarkom-checkout-speed-audit-for-woocommerce' ) : sprintf( __( '%d s', 'tvarkom-checkout-speed-audit-for-woocommerce' ), $php['max_execution_time'] ) ),
			array( 'OPcache', null === $php['opcache_enabled'] ? __( 'unknown', 'tvarkom-checkout-speed-audit-for-woocommerce' ) : ( $php['opcache_enabled'] ? __( 'on', 'tvarkom-checkout-speed-audit-for-woocommerce' ) : __( 'off', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ) ),
		),
		''
	);
	?>

	<h3><?php esc_html_e( 'Failed background tasks (last 30 days)', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></h3>
	<?php
	tvarkom_csa_table(
		array( __( 'Task', 'tvarkom-checkout-speed-audit-for-woocommerce' ), __( 'Failures', 'tvarkom-checkout-speed-audit-for-woocommerce' ), __( 'Last error', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		array_map(
			function ( $t ) {
				return array( $t['hook_signature'], number_format_i18n( $t['failure_count'] ), null === $t['last_error_message'] ? '—' : $t['last_error_message'] );
			},
			$d['failed_task_breakdown']
		),
		__( 'No failed background tasks.', 'tvarkom-checkout-speed-audit-for-woocommerce' )
	);
	?>

	<h3><?php esc_html_e( 'Scheduled tasks more than 15 minutes late', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></h3>
	<?php
	tvarkom_csa_table(
		array( __( 'Task', 'tvarkom-checkout-speed-audit-for-woocommerce' ), __( 'Queue', 'tvarkom-checkout-speed-audit-for-woocommerce' ), __( 'Late by', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		array_map(
			function ( $a ) {
				/* translators: %d: minutes. */
				return array( $a['hook'], 'wp_cron' === $a['source'] ? 'WP-Cron' : 'Action Scheduler', sprintf( _n( '%d minute', '%d minutes', $a['delay_minutes'], 'tvarkom-checkout-speed-audit-for-woocommerce' ), $a['delay_minutes'] ) );
			},
			$d['cron_queue_stalled_actions']
		),
		__( 'No late scheduled tasks.', 'tvarkom-checkout-speed-audit-for-woocommerce' )
	);
	tvarkom_csa_render_local_profile();
}

/**
 * URL of the plugin page with the diagnostics shown.
 *
 * @return string
 */
function tvarkom_csa_diagnostics_url() {
	return wp_nonce_url( admin_url( 'admin.php?page=tvarkom-csa&csa_diagnostics=1' ), 'tvarkom_csa_diagnostics' );
}

/**
 * The checkout profile part: the button and the last result.
 */
function tvarkom_csa_render_local_profile() {
	$saved = get_option( TVARKOM_CSA_LOCAL_PROFILE );
	?>
	<h3><?php esc_html_e( 'Checkout profile', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></h3>
	<p class="description"><?php esc_html_e( 'Where your server spends its time on one checkout page load, by plugin. The plugin loads your checkout page once from your own server, with one product in a temporary cart that is never saved; nothing is ordered. Analytics or marketing plugins that track checkout visits on the server may count this load as one visit.', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="tvarkom_csa_profile">
		<?php wp_nonce_field( 'tvarkom_csa_profile' ); ?>
		<p><button type="submit" class="button"><?php esc_html_e( 'Profile my checkout', 'tvarkom-checkout-speed-audit-for-woocommerce' ); ?></button></p>
	</form>
	<?php
	if ( ! is_array( $saved ) || empty( $saved['profile'] ) ) {
		return;
	}
	$p = $saved['profile'];
	/* translators: %s: date and time. */
	echo '<p class="description">' . esc_html( sprintf( __( 'Last profile: %s.', 'tvarkom-checkout-speed-audit-for-woocommerce' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $saved['time'] ) ) ) . '</p>';
	if ( 'ok' !== $p['status'] ) {
		$reasons = array(
			'request_failed' => __( 'The request from your server to its own checkout page failed or timed out. Some hosts block a site from calling itself; Tools → Site Health shows whether loopback requests work.', 'tvarkom-checkout-speed-audit-for-woocommerce' ),
			'redirected'     => __( 'Your checkout page redirected instead of loading, for example because it requires a login.', 'tvarkom-checkout-speed-audit-for-woocommerce' ),
			'not_profiled'   => __( 'Your checkout page answered, but WordPress never saw the request. A firewall or a page cache in front of the store most likely answered first.', 'tvarkom-checkout-speed-audit-for-woocommerce' ),
		);
		$reason  = isset( $p['reason'], $reasons[ $p['reason'] ] ) ? $reasons[ $p['reason'] ] : $reasons['not_profiled'];
		echo '<p>' . esc_html( $reason ) . '</p>';
		return;
	}
	$phases = $p['phases'];
	echo '<p>' . esc_html(
		sprintf(
			/* translators: 1: total time, 2: loading time, 3: setup time, 4: routing time, 5: page building time. */
			__( 'Server time %1$s: loading WordPress and plugins %2$s, setup %3$s, finding the page %4$s, building the page %5$s.', 'tvarkom-checkout-speed-audit-for-woocommerce' ),
			tvarkom_csa_ms( $p['total_ms'] ),
			tvarkom_csa_ms( $phases['load_ms'] ),
			tvarkom_csa_ms( $phases['init_ms'] ),
			tvarkom_csa_ms( $phases['query_ms'] ),
			tvarkom_csa_ms( $phases['render_ms'] )
		)
	) . ( 'empty' === $p['cart'] ? ' ' . esc_html__( 'The store had no in-stock product to put in the cart, so this is the empty checkout.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) : '' ) . '</p>';

	tvarkom_csa_table(
		array( __( 'Plugin or theme', 'tvarkom-checkout-speed-audit-for-woocommerce' ), __( 'Code (hooks)', 'tvarkom-checkout-speed-audit-for-woocommerce' ), __( 'Database queries', 'tvarkom-checkout-speed-audit-for-woocommerce' ), __( 'Outside calls', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		array_map(
			function ( $c ) {
				return array( $c['name'], tvarkom_csa_ms( $c['hook_ms'] ), tvarkom_csa_ms( $c['query_ms'] ) . ' (' . number_format_i18n( $c['query_count'] ) . ')', $c['http_ms'] > 0 ? tvarkom_csa_ms( $c['http_ms'] ) : '—' );
			},
			$p['components']
		),
		__( 'No plugin time recorded.', 'tvarkom-checkout-speed-audit-for-woocommerce' )
	);
	echo '<h4>' . esc_html__( 'Slowest hooks', 'tvarkom-checkout-speed-audit-for-woocommerce' ) . '</h4>';
	tvarkom_csa_table(
		array( __( 'Hook', 'tvarkom-checkout-speed-audit-for-woocommerce' ), __( 'Plugin or theme', 'tvarkom-checkout-speed-audit-for-woocommerce' ), __( 'Time', 'tvarkom-checkout-speed-audit-for-woocommerce' ), __( 'Calls', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		array_map(
			function ( $h ) {
				return array( $h['hook'], $h['component'], tvarkom_csa_ms( $h['ms'] ), number_format_i18n( $h['calls'] ) );
			},
			$p['slowest_hooks']
		),
		__( 'No hooks recorded.', 'tvarkom-checkout-speed-audit-for-woocommerce' )
	);
	echo '<h4>' . esc_html__( 'Most expensive database queries', 'tvarkom-checkout-speed-audit-for-woocommerce' ) . '</h4>';
	echo '<p class="description">' . esc_html(
		sprintf(
			/* translators: 1: number of queries, 2: number of exact repeats. */
			__( '%1$s queries in total, %2$s of them exact repeats of an earlier one.', 'tvarkom-checkout-speed-audit-for-woocommerce' ),
			number_format_i18n( (int) $p['queries']['total_count'] ),
			number_format_i18n( (int) $p['queries']['identical_repeats'] )
		)
	) . '</p>';
	tvarkom_csa_table(
		array( __( 'Query', 'tvarkom-checkout-speed-audit-for-woocommerce' ), __( 'Run by', 'tvarkom-checkout-speed-audit-for-woocommerce' ), __( 'Runs', 'tvarkom-checkout-speed-audit-for-woocommerce' ), __( 'Total', 'tvarkom-checkout-speed-audit-for-woocommerce' ), __( 'Slowest', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		array_map(
			function ( $q ) {
				return array( $q['sql'], $q['component'], number_format_i18n( $q['count'] ), tvarkom_csa_ms( $q['total_ms'] ), tvarkom_csa_ms( $q['max_ms'] ) );
			},
			array_slice( $p['slow_queries'], 0, 5 )
		),
		__( 'Query details weren\'t available.', 'tvarkom-checkout-speed-audit-for-woocommerce' )
	);
	echo '<h4>' . esc_html__( 'Calls to outside services', 'tvarkom-checkout-speed-audit-for-woocommerce' ) . '</h4>';
	tvarkom_csa_table(
		array( __( 'Service', 'tvarkom-checkout-speed-audit-for-woocommerce' ), __( 'Called by', 'tvarkom-checkout-speed-audit-for-woocommerce' ), __( 'Time', 'tvarkom-checkout-speed-audit-for-woocommerce' ) ),
		array_map(
			function ( $c ) {
				return array( $c['own_site'] ? $c['host'] . ' ' . __( '(your store)', 'tvarkom-checkout-speed-audit-for-woocommerce' ) : $c['host'], $c['component'], tvarkom_csa_ms( $c['ms'] ) );
			},
			$p['http_calls']
		),
		__( 'No calls to outside services while the checkout loaded.', 'tvarkom-checkout-speed-audit-for-woocommerce' )
	);
	$cache = $p['object_cache'];
	if ( empty( $cache['persistent'] ) ) {
		echo '<p>' . esc_html__( 'Object cache: no persistent object cache, so WordPress\'s cache starts empty on every page load.', 'tvarkom-checkout-speed-audit-for-woocommerce' ) . '</p>';
	} elseif ( null !== $cache['hits'] && null !== $cache['misses'] ) {
		/* translators: 1: hits, 2: misses. */
		echo '<p>' . esc_html( sprintf( __( 'Object cache: persistent, %1$s hits and %2$s misses.', 'tvarkom-checkout-speed-audit-for-woocommerce' ), number_format_i18n( $cache['hits'] ), number_format_i18n( $cache['misses'] ) ) ) . '</p>';
	}
}

/**
 * "Profile my checkout": runs the checkout profile and keeps the result for the page.
 */
function tvarkom_csa_handle_profile() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'tvarkom-checkout-speed-audit-for-woocommerce' ), 403 );
	}
	check_admin_referer( 'tvarkom_csa_profile' );
	$profile = tvarkom_csa_profile_checkout();
	update_option(
		TVARKOM_CSA_LOCAL_PROFILE,
		array(
			'time'    => time(),
			'profile' => $profile,
		),
		false
	);
	tvarkom_csa_redirect(
		'ok' === $profile['status'] ? 'profiled' : 'profile_failed',
		array(
			'csa_diagnostics' => 1,
			'_wpnonce'        => wp_create_nonce( 'tvarkom_csa_diagnostics' ),
		)
	);
}
add_action( 'admin_post_tvarkom_csa_profile', 'tvarkom_csa_handle_profile' );
