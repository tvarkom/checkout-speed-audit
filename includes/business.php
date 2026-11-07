<?php
/**
 * Business context for the audit (metrics schema 3): what kind of store this is, so the report
 * can put the technical findings in business terms. Aggregates only: counts, codes, component
 * names, and the last 30 days' gross sales and average order value in the store's own currency
 * (founder decision of 7 Nov 2026, replacing the euro ranges of schema 2), which tvarkom uses only
 * to write the report and doesn't keep. No customer names, emails or addresses and no order
 * contents leave the store.
 *
 * Every value is read with WooCommerce or WordPress functions, or one aggregate query on an
 * indexed column; nothing runs in the background and nothing is written.
 *
 * @package CheckoutSpeedAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Known backup plugins (main file => name), for "backup plugin active". Hosting companies' own
 * backups can't be seen from here, so the report says "no backup plugin found", never "no backups".
 */
const TVARKOM_CSA_BACKUP_PLUGINS = array(
	'updraftplus/updraftplus.php'                         => 'UpdraftPlus',
	'backwpup/backwpup.php'                               => 'BackWPup',
	'jetpack-backup/jetpack-backup.php'                   => 'Jetpack VaultPress Backup',
	'duplicator/duplicator.php'                           => 'Duplicator',
	'duplicator-pro/duplicator-pro.php'                   => 'Duplicator Pro',
	'all-in-one-wp-migration/all-in-one-wp-migration.php' => 'All-in-One WP Migration',
	'wpvivid-backuprestore/wpvivid-backuprestore.php'     => 'WPvivid',
	'blogvault-real-time-backup/blogvault.php'            => 'BlogVault',
	'backup-backup/backup-backup.php'                     => 'Backup Migration',
	'backupbuddy/backupbuddy.php'                         => 'Solid Backups',
	'boldgrid-backup/boldgrid-backup.php'                 => 'Total Upkeep',
	'wp-db-backup/wp-db-backup.php'                       => 'WP-DB-Backup',
	'xcloner-backup-and-restore/xcloner.php'              => 'XCloner',
	'wp-time-capsule/wp-time-capsule.php'                 => 'WP Time Capsule',
	'akeeba-backup-wordpress/akeebabackupwp.php'          => 'Akeeba Backup',
	'backup-guard-platinum/backup-guard-platinum.php'     => 'BackupGuard',
);

/**
 * The business fields of metrics schema 3 (the gateway's README, "Metrics schema 3").
 *
 * @return array<string, mixed>
 */
function tvarkom_csa_collect_business() {
	$currency = get_woocommerce_currency();
	$sales    = tvarkom_csa_sales_30d( $currency );
	$returns  = tvarkom_csa_new_vs_returning_30d();

	return array(
		'country'                     => strtoupper( (string) WC()->countries->get_base_country() ),
		'currency'                    => strtoupper( $currency ),
		'orders_30d'                  => tvarkom_csa_paid_orders_30d(),
		'orders_failed_30d'           => tvarkom_csa_orders_30d( array( 'failed' ) ),
		'orders_pending_30d'          => tvarkom_csa_orders_30d( array( 'pending' ) ),
		'orders_cancelled_30d'        => tvarkom_csa_orders_30d( array( 'cancelled' ) ),
		'sales_30d'                   => round( $sales['total'], 2 ),
		'aov_30d'                     => $sales['orders'] > 0 ? round( $sales['total'] / $sales['orders'], 2 ) : null,
		'customers_new_30d'           => $returns['new'],
		'customers_returning_30d'     => $returns['returning'],
		'products'                    => tvarkom_csa_published_count( 'product' ),
		'variations'                  => tvarkom_csa_published_count( 'product_variation' ),
		'stock_management'            => 'yes' === get_option( 'woocommerce_manage_stock' ),
		'payment_gateways'            => tvarkom_csa_payment_gateway_ids(),
		'shipping_methods'            => tvarkom_csa_shipping_method_ids(),
		'checkout_type'               => tvarkom_csa_checkout_type(),
		'hpos'                        => tvarkom_csa_hpos(),
		'database_server'             => tvarkom_csa_database_server(),
		'web_server'                  => tvarkom_csa_web_server(),
		'template_overrides_outdated' => tvarkom_csa_outdated_template_overrides(),
		'plugins_updates_pending'     => tvarkom_csa_plugins_updates_pending(),
		'backup_plugin'               => tvarkom_csa_backup_plugin(),
		'email_from_domain'           => tvarkom_csa_email_from_domain(),
	) + tvarkom_csa_funnel_totals() + tvarkom_csa_order_sources();
}

/**
 * The order statuses that count as sales: WooCommerce's paid statuses and on-hold.
 *
 * @return string[] Without the "wc-" prefix.
 */
function tvarkom_csa_paid_statuses() {
	return array_values( array_unique( array_merge( wc_get_is_paid_statuses(), array( 'on-hold' ) ) ) );
}

/**
 * Orders created in the last 30 days with one of the statuses (one paginated count query).
 *
 * @param string[] $statuses Without the "wc-" prefix.
 * @return int
 */
function tvarkom_csa_orders_30d( array $statuses ) {
	$result = wc_get_orders(
		array(
			'limit'        => 1,
			'paginate'     => true,
			'return'       => 'ids',
			'type'         => 'shop_order',
			'status'       => $statuses,
			'date_created' => '>' . ( time() - 30 * DAY_IN_SECONDS ),
		)
	);
	return is_object( $result ) && isset( $result->total ) ? (int) $result->total : 0;
}

/**
 * Paid and on-hold orders in the last 30 days: the exact number, which gives the store's size.
 *
 * @return int
 */
function tvarkom_csa_paid_orders_30d() {
	return tvarkom_csa_orders_30d( tvarkom_csa_paid_statuses() );
}

/**
 * Whether WooCommerce stores orders in its own tables (High-Performance Order Storage).
 *
 * @return bool
 */
function tvarkom_csa_hpos() {
	return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}

/**
 * Gross sales (order totals with tax and shipping) and the number of orders, last 30 days, paid
 * and on-hold orders in the store's currency only (orders in other currencies, from a
 * multi-currency plugin, are left out). One aggregate query, HPOS or legacy storage.
 *
 * @param string $currency The store's currency.
 * @return array{total: float, orders: int}
 */
function tvarkom_csa_sales_30d( $currency ) {
	global $wpdb;
	$since    = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
	$statuses = array_map(
		function ( $s ) {
			return 'wc-' . $s;
		},
		tvarkom_csa_paid_statuses()
	);
	$in       = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

	if ( tvarkom_csa_hpos() ) {
		$table = $wpdb->prefix . 'wc_orders';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one aggregate per audit; the table as %i, statuses as placeholders.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS orders, COALESCE(SUM(total_amount), 0) AS total FROM %i
				WHERE type = 'shop_order' AND status IN ($in) AND date_created_gmt > %s AND currency = %s",
				array_merge( array( $table ), $statuses, array( $since, $currency ) )
			)
		);
		// phpcs:enable
	} else {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.SlowDBQuery -- one aggregate per audit; statuses as placeholders.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS orders, COALESCE(SUM(CAST(t.meta_value AS DECIMAL(20,4))), 0) AS total
				FROM {$wpdb->posts} p
				JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '_order_total'
				JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = '_order_currency' AND c.meta_value = %s
				WHERE p.post_type = 'shop_order' AND p.post_status IN ($in) AND p.post_date_gmt > %s",
				array_merge( array( $currency ), $statuses, array( $since ) )
			)
		);
		// phpcs:enable
	}
	return array(
		'total'  => $row ? (float) $row->total : 0.0,
		'orders' => $row ? (int) $row->orders : 0,
	);
}

/**
 * New and returning customers among the last 30 days' paid and on-hold orders, as WooCommerce
 * Analytics already records them (wc_order_stats.returning_customer). Null when Analytics has no
 * data here. Only the two counts leave the store.
 *
 * @return array{new: int|null, returning: int|null}
 */
function tvarkom_csa_new_vs_returning_30d() {
	global $wpdb;
	$none  = array(
		'new'       => null,
		'returning' => null,
	);
	$table = $wpdb->prefix . 'wc_order_stats';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one check per audit.
	if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
		return $none;
	}
	$statuses = array_map(
		function ( $s ) {
			return 'wc-' . $s;
		},
		tvarkom_csa_paid_statuses()
	);
	$in       = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one aggregate per audit; the table as %i, statuses as placeholders.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT returning_customer AS returning_customer, COUNT(DISTINCT customer_id) AS customers FROM %i
			WHERE parent_id = 0 AND status IN ($in) AND date_created_gmt > %s GROUP BY returning_customer",
			array_merge( array( $table ), $statuses, array( gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) )
		)
	);
	// phpcs:enable
	if ( ! is_array( $rows ) ) {
		return $none;
	}
	$out = array(
		'new'       => 0,
		'returning' => 0,
	);
	foreach ( $rows as $row ) {
		$out[ (int) $row->returning_customer ? 'returning' : 'new' ] += (int) $row->customers;
	}
	return $out;
}

/**
 * Published posts of a type (products, or product variations).
 *
 * @param string $type Post type.
 * @return int
 */
function tvarkom_csa_published_count( $type ) {
	$counts = wp_count_posts( $type );
	return isset( $counts->publish ) ? (int) $counts->publish : 0;
}

/**
 * Ids of the enabled payment gateways, e.g. stripe, ppcp-gateway. Names only, never settings.
 *
 * @return string[]
 */
function tvarkom_csa_payment_gateway_ids() {
	$ids = array();
	foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
		if ( is_object( $gateway ) && isset( $gateway->enabled, $gateway->id ) && 'yes' === $gateway->enabled ) {
			$ids[] = (string) $gateway->id;
		}
	}
	return array_values( array_unique( $ids ) );
}

/**
 * Ids of the shipping methods enabled in any zone, e.g. flat_rate, free_shipping.
 *
 * @return string[]
 */
function tvarkom_csa_shipping_method_ids() {
	if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
		return array();
	}
	$zones = array( new WC_Shipping_Zone( 0 ) ); // "Locations not covered by your other zones".
	foreach ( WC_Shipping_Zones::get_zones( 'json' ) as $zone ) {
		$zones[] = new WC_Shipping_Zone( (int) $zone['id'] );
	}
	$ids = array();
	foreach ( $zones as $zone ) {
		foreach ( $zone->get_shipping_methods( true ) as $method ) {
			$ids[] = (string) $method->id;
		}
	}
	return array_values( array_unique( $ids ) );
}

/**
 * Whether the checkout page uses the Checkout block or the classic shortcode.
 *
 * @return string|null classic | block, or null without a checkout page.
 */
function tvarkom_csa_checkout_type() {
	$page = wc_get_page_id( 'checkout' );
	if ( $page <= 0 ) {
		return null;
	}
	return has_block( 'woocommerce/checkout', $page ) ? 'block' : 'classic';
}

/**
 * The database server's product and major.minor version, e.g. "MariaDB 10.11", "MySQL 8.0".
 *
 * @return string|null
 */
function tvarkom_csa_database_server() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one read per audit.
	$version = (string) $wpdb->get_var( 'SELECT VERSION()' );
	if ( ! preg_match( '/^(\d+)\.(\d+)/', $version, $m ) ) {
		return null;
	}
	$product = false !== stripos( $version, 'mariadb' ) ? 'MariaDB' : 'MySQL';
	return $product . ' ' . $m[1] . '.' . $m[2];
}

/**
 * The web server's product name only, e.g. nginx, Apache, LiteSpeed (no version or modules).
 *
 * @return string|null
 */
function tvarkom_csa_web_server() {
	$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
	return preg_match( '/^([A-Za-z][A-Za-z\-]{0,29})/', $software, $m ) ? $m[1] : null;
}

/**
 * How many of the theme's WooCommerce template overrides are older than WooCommerce's own
 * templates (WooCommerce's System Status check, run locally: no remote calls).
 *
 * @return int|null Null when WooCommerce's check isn't available.
 */
function tvarkom_csa_outdated_template_overrides() {
	if ( ! class_exists( 'WC_REST_System_Status_V2_Controller' ) ) {
		return null;
	}
	try {
		$info = ( new WC_REST_System_Status_V2_Controller() )->get_theme_info();
	} catch ( Throwable $e ) {
		return null;
	}
	$outdated = 0;
	foreach ( isset( $info['overrides'] ) && is_array( $info['overrides'] ) ? $info['overrides'] : array() as $override ) {
		$version = isset( $override['version'] ) ? (string) $override['version'] : '';
		$core    = isset( $override['core_version'] ) ? (string) $override['core_version'] : '';
		if ( '' !== $core && ( '' === $version || version_compare( $version, $core, '<' ) ) ) {
			++$outdated;
		}
	}
	return $outdated;
}

/**
 * Plugins with an update waiting, from WordPress's last update check (no new remote call).
 *
 * @return int|null Null when WordPress hasn't checked yet.
 */
function tvarkom_csa_plugins_updates_pending() {
	$updates = get_site_transient( 'update_plugins' );
	if ( ! is_object( $updates ) || ! isset( $updates->response ) || ! is_array( $updates->response ) ) {
		return null;
	}
	return count( $updates->response );
}

/**
 * The first active plugin from the list of known backup plugins, or null.
 *
 * @return string|null
 */
function tvarkom_csa_backup_plugin() {
	$active = (array) get_option( 'active_plugins', array() );
	if ( is_multisite() ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}
	foreach ( TVARKOM_CSA_BACKUP_PLUGINS as $file => $name ) {
		if ( in_array( $file, $active, true ) ) {
			return $name;
		}
	}
	return null;
}
