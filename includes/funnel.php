<?php
/**
 * Funnel counters and order sources (metrics schema 2, PLUGIN_DATA_EXPANSION_PLAN.md §5).
 *
 * Three daily totals, kept on this store in one small table: products added to the cart,
 * checkout page loads, and orders placed. Counted on the server by WooCommerce hooks: no
 * cookies, no browser script, nothing about the shopper. Logged-in administrators and shop
 * managers, and the plugin's own checkout profile load, aren't counted. Each event is one
 * indexed write; rows older than 35 days are removed. Uninstalling drops the table.
 *
 * Product page views can't be counted: cached pages never reach PHP.
 *
 * Order sources come from WooCommerce Order Attribution (built in since 8.5): paid and on-hold
 * orders of the last 30 days by source type, and the top 10 UTM sources, as counts.
 *
 * @package CheckoutSpeedAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TVARKOM_CSA_FUNNEL_TABLE', 'tvarkom_csa_funnel' );
define( 'TVARKOM_CSA_FUNNEL_SINCE', 'tvarkom_csa_funnel_since' ); // The day counting started, Y-m-d (UTC).
define( 'TVARKOM_CSA_FUNNEL_KEEP_DAYS', 35 );

/**
 * The counter table's name.
 *
 * @return string
 */
function tvarkom_csa_funnel_table() {
	global $wpdb;
	return $wpdb->prefix . TVARKOM_CSA_FUNNEL_TABLE;
}

/**
 * Creates the counter table if it's missing, and records when counting started. Runs on
 * activation and, for sites updated without reactivating, once per request until the table
 * exists (one cached option read otherwise).
 *
 * @return void
 */
function tvarkom_csa_funnel_install() {
	if ( get_option( TVARKOM_CSA_FUNNEL_SINCE ) ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table   = tvarkom_csa_funnel_table();
	$charset = $wpdb->get_charset_collate();
	dbDelta(
		"CREATE TABLE $table (
		day date NOT NULL,
		add_to_cart int(10) unsigned NOT NULL DEFAULT 0,
		checkout_views int(10) unsigned NOT NULL DEFAULT 0,
		orders int(10) unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (day)
		) $charset;"
	);
	update_option( TVARKOM_CSA_FUNNEL_SINCE, gmdate( 'Y-m-d' ), true ); // Autoloaded: every counted event reads it.
}

/**
 * Whether this request's events are counted: not by staff, not the plugin's checkout profile.
 *
 * @return bool
 */
function tvarkom_csa_funnel_counts_this_request() {
	if ( ! empty( $_SERVER['HTTP_X_TVARKOM_PROFILE'] ) ) {
		return false;
	}
	return ! ( is_user_logged_in() && current_user_can( 'manage_woocommerce' ) );
}

/**
 * Adds 1 to today's counter. One indexed write; a new day also removes days past 35.
 *
 * @param string $column add_to_cart | checkout_views | orders.
 * @return void
 */
function tvarkom_csa_funnel_count( $column ) {
	$queries = array(
		'add_to_cart'    => 'INSERT INTO %i (day, add_to_cart) VALUES (%s, 1) ON DUPLICATE KEY UPDATE add_to_cart = add_to_cart + 1',
		'checkout_views' => 'INSERT INTO %i (day, checkout_views) VALUES (%s, 1) ON DUPLICATE KEY UPDATE checkout_views = checkout_views + 1',
		'orders'         => 'INSERT INTO %i (day, orders) VALUES (%s, 1) ON DUPLICATE KEY UPDATE orders = orders + 1',
	);
	if ( ! isset( $queries[ $column ] ) || ! get_option( TVARKOM_CSA_FUNNEL_SINCE ) ) {
		return;
	}
	global $wpdb;
	$table = tvarkom_csa_funnel_table();
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- a counter: one write per event; fixed queries, the table as %i.
	$affected = $wpdb->query( $wpdb->prepare( $queries[ $column ], $table, gmdate( 'Y-m-d' ) ) );
	if ( 1 === $affected ) { // The day's first event: a new row.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE day < %s', $table, gmdate( 'Y-m-d', time() - TVARKOM_CSA_FUNNEL_KEEP_DAYS * DAY_IN_SECONDS ) ) );
	}
	// phpcs:enable
}

/**
 * A product added to the cart (classic forms, AJAX and the Store API all go through it).
 *
 * @return void
 */
function tvarkom_csa_funnel_add_to_cart() {
	if ( tvarkom_csa_funnel_counts_this_request() ) {
		tvarkom_csa_funnel_count( 'add_to_cart' );
	}
}

/**
 * The checkout page loaded (classic or block), not the order-received page.
 *
 * @return void
 */
function tvarkom_csa_funnel_checkout_view() {
	if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url( 'order-received' ) && ! is_wc_endpoint_url( 'order-pay' ) && tvarkom_csa_funnel_counts_this_request() ) {
		tvarkom_csa_funnel_count( 'checkout_views' );
	}
}

/**
 * An order placed through the checkout (classic or block).
 *
 * @return void
 */
function tvarkom_csa_funnel_order() {
	if ( tvarkom_csa_funnel_counts_this_request() ) {
		tvarkom_csa_funnel_count( 'orders' );
	}
}

/**
 * The funnel for the audit: totals over the last 30 days of counting (fewer if counting started
 * later), with the number of days they cover.
 *
 * @return array{funnel_days: int, funnel_add_to_cart: int, funnel_checkout_views: int, funnel_orders: int}
 */
function tvarkom_csa_funnel_totals() {
	$since = get_option( TVARKOM_CSA_FUNNEL_SINCE );
	$days  = $since ? (int) floor( ( time() - (int) strtotime( $since . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS ) : 0;
	$days  = max( 0, min( 30, $days ) ); // Complete days only: today is still counting.
	$out   = array(
		'funnel_days'           => $days,
		'funnel_add_to_cart'    => 0,
		'funnel_checkout_views' => 0,
		'funnel_orders'         => 0,
	);
	if ( 0 === $days ) {
		return $out;
	}
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one read per audit.
	$row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT COALESCE(SUM(add_to_cart), 0) AS a, COALESCE(SUM(checkout_views), 0) AS c, COALESCE(SUM(orders), 0) AS o FROM %i WHERE day >= %s AND day < %s',
			tvarkom_csa_funnel_table(),
			gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS ),
			gmdate( 'Y-m-d' )
		)
	);
	if ( $row ) {
		$out['funnel_add_to_cart']    = (int) $row->a;
		$out['funnel_checkout_views'] = (int) $row->c;
		$out['funnel_orders']         = (int) $row->o;
	}
	return $out;
}

/**
 * Paid and on-hold orders of the last 30 days by WooCommerce Order Attribution meta value.
 *
 * @param string $meta_key _wc_order_attribution_source_type or _wc_order_attribution_utm_source.
 * @return array<string, int> Value => orders, most first; orders without the meta aren't counted.
 */
function tvarkom_csa_orders_by_attribution( $meta_key ) {
	global $wpdb;
	$since    = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
	$statuses = array_map(
		function ( $s ) {
			return 'wc-' . $s;
		},
		tvarkom_csa_paid_statuses()
	);
	$in       = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.SlowDBQuery -- one aggregate per audit; tables as %i, statuses as placeholders.
	if ( tvarkom_csa_hpos() ) {
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.meta_value AS value, COUNT(*) AS orders FROM %i o JOIN %i m ON m.order_id = o.id AND m.meta_key = %s
				WHERE o.type = 'shop_order' AND o.status IN ($in) AND o.date_created_gmt > %s GROUP BY m.meta_value ORDER BY orders DESC",
				array_merge( array( $wpdb->prefix . 'wc_orders', $wpdb->prefix . 'wc_orders_meta', $meta_key ), $statuses, array( $since ) )
			)
		);
	} else {
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.meta_value AS value, COUNT(*) AS orders FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				WHERE p.post_type = 'shop_order' AND p.post_status IN ($in) AND p.post_date_gmt > %s GROUP BY m.meta_value ORDER BY orders DESC",
				array_merge( array( $meta_key ), $statuses, array( $since ) )
			)
		);
	}
	// phpcs:enable
	$out = array();
	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$out[ (string) $row->value ] = (int) $row->orders;
	}
	return $out;
}

/**
 * Order sources for the audit: counts by source type (utm, organic, referral, typein, …) and the
 * top 10 UTM sources, the rest as "other". Null when Order Attribution recorded nothing.
 *
 * @return array{order_sources: array<string, int>|null, utm_sources: array<string, int>|null}
 */
function tvarkom_csa_order_sources() {
	$types = tvarkom_csa_orders_by_attribution( '_wc_order_attribution_source_type' );
	$utm   = tvarkom_csa_orders_by_attribution( '_wc_order_attribution_utm_source' );
	$top   = array();
	$other = 0;
	foreach ( $utm as $source => $orders ) {
		// Campaign names are free text: one that could identify a person or a record (an email
		// address, a long number, a hash) is counted as "other", never sent.
		$looks_personal = preg_match( '/@|\d{6,}|[0-9a-f]{16,}/i', (string) $source );
		$name           = strtolower( substr( preg_replace( '/[^A-Za-z0-9 ._\-]/', '', $source ), 0, 40 ) );
		if ( $looks_personal || '' === $name || count( $top ) >= 10 ) {
			$other += $orders;
			continue;
		}
		$top[ $name ] = ( $top[ $name ] ?? 0 ) + $orders;
	}
	if ( $other > 0 ) {
		$top['other'] = ( $top['other'] ?? 0 ) + $other;
	}
	return array(
		'order_sources' => $types ? $types : null,
		'utm_sources'   => $top ? $top : null,
	);
}

/**
 * The domain the store's emails come from (WooCommerce → Settings → Emails, "From" address),
 * for the scanner's SPF and DMARC checks. The domain only, never the address.
 *
 * @return string|null
 */
function tvarkom_csa_email_from_domain() {
	$address = (string) get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) );
	$at      = strrpos( $address, '@' );
	if ( false === $at ) {
		return null;
	}
	$domain = strtolower( substr( $address, $at + 1 ) );
	return preg_match( '/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/', $domain ) ? $domain : null;
}
