<?php
/**
 * Store metrics for the audit. Only aggregate counts, sizes and settings: no customer
 * data, no order contents, no revenue. Every query is a COUNT/SUM on an indexed
 * column or a read of the table sizes MySQL already keeps, so it's light even on
 * shared hosting.
 *
 * @package CheckoutSpeedAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects the metrics sent with an audit (shown to the admin under "Preview data").
 *
 * @return array<string, mixed>
 */
function tvarkom_csa_collect_metrics() {
	$sizes     = tvarkom_csa_table_sizes();
	$scheduler = tvarkom_csa_scheduler_counts();
	$cron      = tvarkom_csa_cron_overdue_seconds();

	return array(
		'schema'                      => 1,
		'php_version'                 => PHP_VERSION,
		'wordpress_version'           => get_bloginfo( 'version' ),
		'woocommerce_version'         => defined( 'WC_VERSION' ) ? WC_VERSION : null,
		'options_table_size_mb'       => $sizes['options'],
		'database_size_mb'            => $sizes['total'],
		'autoloaded_kb'               => tvarkom_csa_autoloaded_kb(),
		'active_plugin_count'         => tvarkom_csa_active_plugin_count(),
		'object_cache_enabled'        => (bool) wp_using_ext_object_cache(),
		'object_cache_type'           => tvarkom_csa_object_cache_type(),
		'action_scheduler_pending'    => $scheduler['pending'],
		'action_scheduler_past_due'   => $scheduler['past_due'],
		'action_scheduler_failed'     => $scheduler['failed'],
		'action_scheduler_failed_7d'  => $scheduler['failed_7d'],
		'cron_disabled'               => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		'cron_oldest_overdue_seconds' => $cron,
		'order_tier'                  => tvarkom_csa_order_tier(),
	);
}

/**
 * Sizes of this site's tables in MB, from information_schema (data + indexes).
 *
 * @return array{options: float|null, total: float|null}
 */
function tvarkom_csa_table_sizes() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one read of table metadata per audit.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT TABLE_NAME AS name, DATA_LENGTH + INDEX_LENGTH AS bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s',
			DB_NAME,
			$wpdb->esc_like( $wpdb->prefix ) . '%'
		)
	);
	if ( empty( $rows ) ) {
		return array(
			'options' => null,
			'total'   => null,
		);
	}
	$total   = 0;
	$options = null;
	foreach ( $rows as $row ) {
		$total += (int) $row->bytes;
		if ( $row->name === $wpdb->options ) {
			$options = (int) $row->bytes;
		}
	}
	$mb = function ( $bytes ) {
		return null === $bytes ? null : round( $bytes / 1048576, 1 );
	};
	return array(
		'options' => $mb( $options ),
		'total'   => $mb( $total ),
	);
}

/**
 * Size of the options WordPress loads on every request, in KB.
 *
 * @return float
 */
function tvarkom_csa_autoloaded_kb() {
	global $wpdb;
	// WordPress 6.6+ stores several "autoload" values; older versions use "yes".
	$values       = function_exists( 'wp_autoload_values_to_autoload' ) ? wp_autoload_values_to_autoload() : array( 'yes' );
	$placeholders = implode( ', ', array_fill( 0, count( $values ), '%s' ) );
	// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$bytes = $wpdb->get_var(
		$wpdb->prepare( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ($placeholders)", $values )
	);
	// phpcs:enable
	return round( (int) $bytes / 1024, 1 );
}

/**
 * Active plugins, including network-activated ones.
 *
 * @return int
 */
function tvarkom_csa_active_plugin_count() {
	$count = count( (array) get_option( 'active_plugins', array() ) );
	if ( is_multisite() ) {
		$count += count( (array) get_site_option( 'active_sitewide_plugins', array() ) );
	}
	return $count;
}

/**
 * Persistent object cache backend, from the object-cache.php drop-in's code.
 *
 * @return string None | Redis | Memcached | Unknown
 */
function tvarkom_csa_object_cache_type() {
	if ( ! wp_using_ext_object_cache() ) {
		return 'None';
	}
	$dropin = WP_CONTENT_DIR . '/object-cache.php';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, first 8 KB only.
	$head = is_readable( $dropin ) ? (string) file_get_contents( $dropin, false, null, 0, 8192 ) : '';
	if ( false !== stripos( $head, 'redis' ) ) {
		return 'Redis';
	}
	if ( false !== stripos( $head, 'memcache' ) ) {
		return 'Memcached';
	}
	return 'Unknown';
}

/**
 * Action Scheduler task counts, through its own store API (works with any data store).
 *
 * @return array{pending: int, past_due: int, failed: int, failed_7d: int}
 */
function tvarkom_csa_scheduler_counts() {
	$counts = array(
		'pending'   => 0,
		'past_due'  => 0,
		'failed'    => 0,
		'failed_7d' => 0,
	);
	if ( ! class_exists( 'ActionScheduler_Store' ) || ! function_exists( 'as_get_datetime_object' ) ) {
		return $counts;
	}
	$store = ActionScheduler_Store::instance();
	$count = function ( array $query ) use ( $store ) {
		return (int) $store->query_actions( $query, 'count' );
	};

	$counts['pending']   = $count( array( 'status' => ActionScheduler_Store::STATUS_PENDING ) );
	$counts['past_due']  = $count(
		array(
			'status'       => ActionScheduler_Store::STATUS_PENDING,
			'date'         => as_get_datetime_object( '-15 minutes' ),
			'date_compare' => '<',
		)
	);
	$counts['failed']    = $count( array( 'status' => ActionScheduler_Store::STATUS_FAILED ) );
	$counts['failed_7d'] = $count(
		array(
			'status'           => ActionScheduler_Store::STATUS_FAILED,
			'modified'         => as_get_datetime_object( '-7 days' ),
			'modified_compare' => '>=',
		)
	);
	return $counts;
}

/**
 * How late the oldest due WP-Cron event is, in seconds (0 if none is late).
 *
 * @return int
 */
function tvarkom_csa_cron_overdue_seconds() {
	$crons = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
	if ( empty( $crons ) ) {
		return 0;
	}
	return max( 0, time() - (int) min( array_keys( $crons ) ) );
}

/**
 * Orders in the last 30 days as a range, never the exact number.
 *
 * @return string 0-10 | 10-50 | 50-200 | 200-1000 | 1000+
 */
function tvarkom_csa_order_tier() {
	$result = wc_get_orders(
		array(
			'limit'        => 1,
			'paginate'     => true,
			'return'       => 'ids',
			'status'       => array_merge( wc_get_is_paid_statuses(), array( 'on-hold' ) ),
			'date_created' => '>' . ( time() - 30 * DAY_IN_SECONDS ),
		)
	);
	$orders = is_object( $result ) && isset( $result->total ) ? (int) $result->total : 0;

	if ( $orders < 10 ) {
		return '0-10';
	}
	if ( $orders < 50 ) {
		return '10-50';
	}
	if ( $orders < 200 ) {
		return '50-200';
	}
	return $orders < 1000 ? '200-1000' : '1000+';
}
