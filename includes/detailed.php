<?php
/**
 * Pro detailed diagnostics, collected only for a Pro store's detailed audit (the
 * gateway decides) and only with the admin's consent. Names, sizes and counts:
 * never option values, task arguments or customer data. Error messages are
 * scrubbed of emails, links, IP addresses and long numbers before they leave.
 *
 * @package CheckoutSpeedAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects the detailed diagnostics.
 *
 * @param bool $with_profile Also profile one checkout page load (includes/profile.php). The preview skips it.
 * @return array<string, mixed>
 */
function tvarkom_csa_collect_detailed( $with_profile = true ) {
	$detailed = array(
		'schema'                     => 2,
		'top_autoloaded_keys'        => tvarkom_csa_top_autoloaded_keys(),
		'orphaned_tables'            => tvarkom_csa_orphaned_tables(),
		'php_configuration'          => tvarkom_csa_php_configuration(),
		'failed_task_breakdown'      => tvarkom_csa_failed_tasks(),
		'cron_queue_stalled_actions' => tvarkom_csa_stalled_actions(),
	);
	if ( $with_profile ) {
		$detailed['checkout_profile'] = tvarkom_csa_profile_checkout();
	}
	return $detailed;
}

/**
 * Active plugins as [normalized slug => name], e.g. "wordpress_seo" => "Yoast SEO".
 *
 * @return array<string, string>
 */
function tvarkom_csa_active_plugin_names() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$all    = get_plugins();
	$active = (array) get_option( 'active_plugins', array() );
	if ( is_multisite() ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}
	$names = array();
	foreach ( $active as $file ) {
		$slug           = str_replace( '-', '_', strtolower( dirname( $file ) === '.' ? basename( $file, '.php' ) : dirname( $file ) ) );
		$names[ $slug ] = isset( $all[ $file ]['Name'] ) ? $all[ $file ]['Name'] : $slug;
	}
	return $names;
}

/**
 * Known option and table prefixes of WordPress, WooCommerce and popular plugins
 * whose names don't match their plugin slug.
 *
 * @return array<string, string> Prefix => owner.
 */
function tvarkom_csa_known_prefixes() {
	return array(
		'woocommerce'      => 'WooCommerce',
		'wc_'              => 'WooCommerce',
		'_wc_'             => 'WooCommerce',
		'actionscheduler'  => 'WooCommerce (Action Scheduler)',
		'action_scheduler' => 'WooCommerce (Action Scheduler)',
		'yoast'            => 'Yoast SEO',
		'wpseo'            => 'Yoast SEO',
		'rank_math'        => 'Rank Math',
		'elementor'        => 'Elementor',
		'wpforms'          => 'WPForms',
		'jetpack'          => 'Jetpack',
		'wordfence'        => 'Wordfence',
		'wf'               => 'Wordfence',
		'litespeed'        => 'LiteSpeed Cache',
		'rocket'           => 'WP Rocket',
		'wp_rocket'        => 'WP Rocket',
		'revslider'        => 'Slider Revolution',
		'redirection'      => 'Redirection',
		'mailchimp'        => 'Mailchimp',
		'klaviyo'          => 'Klaviyo',
		'gf_'              => 'Gravity Forms',
		'rg_'              => 'Gravity Forms',
		'smush'            => 'Smush',
		'ewww'             => 'EWWW Image Optimizer',
		'aioseo'           => 'All in One SEO',
		'wpml'             => 'WPML',
		'icl_'             => 'WPML',
		'theme_mods_'      => 'Theme',
		'widget_'          => 'WordPress (widgets)',
	);
}

/**
 * Likely owner of an option or table name (without the table prefix), or null.
 *
 * @param string                $name    Option or table name.
 * @param array<string, string> $plugins Active plugins from tvarkom_csa_active_plugin_names().
 * @return string|null
 */
function tvarkom_csa_owner( $name, array $plugins ) {
	$name = strtolower( preg_replace( '/^_(site_)?transient_(timeout_)?/', '', $name ) );
	$core = array( 'rewrite_rules', 'cron', 'doing_cron', 'active_plugins', 'sidebars_widgets', 'permalink_structure', 'recently_activated', 'uninstall_plugins', 'can_compress_scripts', 'wp_core_block_css_files', 'update_core', 'update_plugins', 'update_themes', 'dirsize_cache' );
	if ( in_array( $name, $core, true ) ) {
		return 'WordPress core';
	}
	foreach ( tvarkom_csa_known_prefixes() as $prefix => $owner ) {
		if ( 0 === strpos( $name, $prefix ) ) {
			return $owner;
		}
	}
	foreach ( $plugins as $slug => $plugin_name ) {
		if ( strlen( $slug ) >= 3 && 0 === strpos( $name, $slug ) ) {
			return $plugin_name;
		}
	}
	return null;
}

/**
 * The 20 largest autoloaded options: name and size only.
 *
 * @return array<int, array{option_name: string, size_bytes: int, associated_plugin: string|null}>
 */
function tvarkom_csa_top_autoloaded_keys() {
	global $wpdb;
	$values       = function_exists( 'wp_autoload_values_to_autoload' ) ? wp_autoload_values_to_autoload() : array( 'yes' );
	$placeholders = implode( ', ', array_fill( 0, count( $values ), '%s' ) );
	// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$rows = $wpdb->get_results(
		$wpdb->prepare( "SELECT option_name, LENGTH(option_value) AS size FROM {$wpdb->options} WHERE autoload IN ($placeholders) ORDER BY size DESC LIMIT 20", $values )
	);
	// phpcs:enable
	$plugins = tvarkom_csa_active_plugin_names();
	$out     = array();
	foreach ( (array) $rows as $row ) {
		$name  = (string) $row->option_name;
		$out[] = array(
			'option_name'       => $name,
			'size_bytes'        => (int) $row->size,
			'associated_plugin' => $wpdb->prefix . 'user_roles' === $name ? 'WordPress core' : tvarkom_csa_owner( $name, $plugins ),
		);
	}
	return $out;
}

/**
 * Tables with this site's prefix that belong neither to WordPress, WooCommerce
 * nor an active plugin (by name), largest first.
 *
 * @return array<int, array{table_name: string, size_mb: float, estimated_row_count: int}>
 */
function tvarkom_csa_orphaned_tables() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table metadata, once per detailed audit.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT TABLE_NAME AS name, DATA_LENGTH + INDEX_LENGTH AS bytes, TABLE_ROWS AS row_count FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s ORDER BY bytes DESC',
			DB_NAME,
			$wpdb->esc_like( $wpdb->prefix ) . '%'
		)
	);
	$core    = array_flip( $wpdb->tables( 'all', true ) );
	$plugins = tvarkom_csa_active_plugin_names();
	$out     = array();
	foreach ( (array) $rows as $row ) {
		$table = (string) $row->name;
		$short = substr( $table, strlen( $wpdb->prefix ) );
		if ( isset( $core[ $table ] ) || ( is_multisite() && preg_match( '/^\d+_/', $short ) ) ) {
			continue;
		}
		$owner = tvarkom_csa_owner( $short, $plugins );
		if ( null !== $owner && ( false !== strpos( $owner, 'WooCommerce' ) || in_array( $owner, $plugins, true ) ) ) {
			continue;
		}
		$out[] = array(
			'table_name'          => $table,
			'size_mb'             => round( (int) $row->bytes / 1048576, 1 ),
			'estimated_row_count' => (int) $row->row_count,
		);
		if ( count( $out ) >= 50 ) {
			break;
		}
	}
	return $out;
}

/**
 * PHP settings that matter for WooCommerce.
 *
 * @return array{memory_limit: string, wp_memory_limit: string|null, max_execution_time: int, opcache_enabled: bool|null}
 */
function tvarkom_csa_php_configuration() {
	$opcache = null;
	if ( function_exists( 'opcache_get_status' ) ) {
		$status  = @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- opcache.restrict_api makes this warn.
		$opcache = is_array( $status ) ? ! empty( $status['opcache_enabled'] ) : (bool) ini_get( 'opcache.enable' );
	} elseif ( extension_loaded( 'Zend OPcache' ) ) {
		$opcache = (bool) ini_get( 'opcache.enable' );
	} else {
		$opcache = false;
	}
	return array(
		'memory_limit'       => (string) ini_get( 'memory_limit' ),
		'wp_memory_limit'    => defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : null,
		'max_execution_time' => (int) ini_get( 'max_execution_time' ),
		'opcache_enabled'    => $opcache,
	);
}

/**
 * Removes emails, links, IP addresses and long numbers from an error message.
 *
 * @param string $message Raw message.
 * @return string
 */
function tvarkom_csa_scrub( $message ) {
	$message = preg_replace( '/[\w.+-]+@[\w-]+(\.[\w-]+)+/', '[email]', $message );
	$message = preg_replace( '#\bhttps?://\S+#i', '[url]', $message );
	$message = preg_replace( '/\b\d{1,3}(\.\d{1,3}){3}\b/', '[ip]', $message );
	$message = preg_replace( '/\d{5,}/', '[number]', $message );
	$message = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $message ) ) );
	return function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 300 ) : substr( $message, 0, 300 );
}

/**
 * Action Scheduler table names, or null when its tables aren't in use.
 *
 * @return array{actions: string, logs: string}|null
 */
function tvarkom_csa_scheduler_tables() {
	global $wpdb;
	$actions = $wpdb->prefix . 'actionscheduler_actions';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $actions ) ) ) !== $actions ) {
		return null;
	}
	return array(
		'actions' => $actions,
		'logs'    => $wpdb->prefix . 'actionscheduler_logs',
	);
}

/**
 * Failed Action Scheduler tasks in the last 30 days, grouped by hook, with the
 * latest (scrubbed) error of each.
 *
 * @return array<int, array{hook_signature: string, failure_count: int, last_error_message: string|null}>
 */
function tvarkom_csa_failed_tasks() {
	global $wpdb;
	$tables = tvarkom_csa_scheduler_tables();
	if ( null === $tables ) {
		return array();
	}
	$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- read once per detailed audit; Action Scheduler has no API for these aggregates.
	$groups = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT hook, COUNT(*) AS failures, MAX(action_id) AS last_id FROM %i WHERE status = 'failed' AND last_attempt_gmt >= %s GROUP BY hook ORDER BY failures DESC LIMIT 20",
			$tables['actions'],
			$since
		)
	);
	$out = array();
	foreach ( (array) $groups as $group ) {
		$message = $wpdb->get_var(
			$wpdb->prepare( 'SELECT message FROM %i WHERE action_id = %d ORDER BY log_id DESC LIMIT 1', $tables['logs'], (int) $group->last_id )
		);
		$out[]   = array(
			'hook_signature'     => (string) $group->hook,
			'failure_count'      => (int) $group->failures,
			'last_error_message' => is_string( $message ) && '' !== $message ? tvarkom_csa_scrub( $message ) : null,
		);
	}
	// phpcs:enable
	return $out;
}

/**
 * Scheduled tasks more than 15 minutes late: Action Scheduler and WP-Cron, hook
 * names and times only.
 *
 * @return array<int, array{hook: string, source: string, scheduled_at: string, delay_minutes: int}>
 */
function tvarkom_csa_stalled_actions() {
	global $wpdb;
	$cutoff = time() - 15 * MINUTE_IN_SECONDS;
	$out    = array();

	$tables = tvarkom_csa_scheduler_tables();
	if ( null !== $tables ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- read once per detailed audit.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT hook, scheduled_date_gmt FROM %i WHERE status = 'pending' AND scheduled_date_gmt < %s ORDER BY scheduled_date_gmt ASC LIMIT 15",
				$tables['actions'],
				gmdate( 'Y-m-d H:i:s', $cutoff )
			)
		);
		// phpcs:enable
		foreach ( (array) $rows as $row ) {
			$at    = strtotime( $row->scheduled_date_gmt . ' UTC' );
			$out[] = array(
				'hook'          => (string) $row->hook,
				'source'        => 'action_scheduler',
				'scheduled_at'  => gmdate( 'c', $at ),
				'delay_minutes' => (int) floor( ( time() - $at ) / MINUTE_IN_SECONDS ),
			);
		}
	}

	$crons = function_exists( '_get_cron_array' ) ? (array) _get_cron_array() : array();
	foreach ( $crons as $timestamp => $hooks ) {
		if ( $timestamp >= $cutoff || count( $out ) >= 25 ) {
			break; // _get_cron_array() is sorted by time.
		}
		foreach ( array_keys( (array) $hooks ) as $hook ) {
			$out[] = array(
				'hook'          => (string) $hook,
				'source'        => 'wp_cron',
				'scheduled_at'  => gmdate( 'c', (int) $timestamp ),
				'delay_minutes' => (int) floor( ( time() - $timestamp ) / MINUTE_IN_SECONDS ),
			);
		}
	}
	return array_slice( $out, 0, 25 );
}
