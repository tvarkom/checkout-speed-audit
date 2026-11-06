<?php
/**
 * What leaves the store: values stripped from query shapes and error messages, order volume
 * as a range, and the metrics the gateway receives (metrics.php, detailed.php, profile-report.php).
 *
 * @package CheckoutSpeedAudit
 */

/**
 * Data minimisation in what is sent.
 */
class Tvarkom_CSA_Privacy_Test extends Tvarkom_CSA_Test_Case {

	/**
	 * Queries and their shapes.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function data_query_shapes() {
		return array(
			'strings and numbers'      => array( "SELECT * FROM wp_users WHERE user_email = 'a@b.example' AND ID = 42", 'SELECT * FROM wp_users WHERE user_email = ? AND ID = ?' ),
			'escaped quote'            => array( "SELECT 1 FROM t WHERE a = 'it\\'s'", 'SELECT ? FROM t WHERE a = ?' ),
			'double quotes'            => array( 'SELECT 1 FROM t WHERE a = "x"', 'SELECT ? FROM t WHERE a = ?' ),
			'IN list'                  => array( 'SELECT * FROM t WHERE id IN (1, 2, 3)', 'SELECT * FROM t WHERE id IN (?)' ),
			'comment, hex and decimal' => array( 'SELECT /* user 5 */ 0xFF, -1.5e3 FROM t', 'SELECT ?, ? FROM t' ),
			'numbers in names stay'    => array( 'SELECT option_value FROM wp_2_options LIMIT 1', 'SELECT option_value FROM wp_2_options LIMIT ?' ),
		);
	}

	/**
	 * Every value in a query becomes "?".
	 *
	 * @dataProvider data_query_shapes
	 *
	 * @param string $sql   Query.
	 * @param string $shape Expected shape.
	 */
	public function test_query_shape( $sql, $shape ) {
		$this->assertSame( $shape, tvarkom_csa_profile_query_shape( $sql ) );
	}

	public function test_query_shape_is_at_most_300_characters() {
		$this->assertSame( 300, strlen( tvarkom_csa_profile_query_shape( 'SELECT ' . str_repeat( 'col, ', 200 ) . 'x FROM t' ) ) );
	}

	public function test_error_messages_are_scrubbed() {
		$this->assertSame(
			'Mail to [email] failed at [url] from [ip], order [number] (code 1234)',
			tvarkom_csa_scrub( "Mail to jane.doe+shop@mail.example.com failed at <b>https://pay.example/x?id=1</b>\n from 192.168.10.20, order 1234567 (code 1234)" )
		);
	}

	/**
	 * Plugin-chosen names and what is sent.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function data_identifiers() {
		return array(
			'session id in an option' => array( '_transient_wc_session_1234567', '_transient_wc_session_N' ),
			'email in a task hook'    => array( 'wc_deliver_webhook_jane.doe@example.com', 'wc_deliver_webhook_email' ),
			'md5 in an option'        => array( 'cache_5f4dcc3b5aa765d61d8327deb882cf99', 'cache_id' ),
			'uuid in a hook'          => array( 'job_3f2b8c1e-9a7d-4c2b-8e1f-0a9b8c7d6e5f', 'job_id' ),
			'plugin names stay'       => array( 'woocommerce_scheduled_sales', 'woocommerce_scheduled_sales' ),
			'short numbers stay'      => array( 'wp_2_options', 'wp_2_options' ),
			'hosts stay'              => array( 'api.stripe.com', 'api.stripe.com' ),
		);
	}

	/**
	 * Option, table, hook and host names lose what could identify a person or a record.
	 *
	 * @dataProvider data_identifiers
	 *
	 * @param string $name Name on the store.
	 * @param string $sent What leaves the store.
	 */
	public function test_identifiers_are_masked( $name, $sent ) {
		$this->assertSame( $sent, tvarkom_csa_identifier( $name ) );
	}

	public function test_order_volume_is_sent_as_a_range() {
		$this->assertSame( '0-10', tvarkom_csa_order_tier() );
		for ( $i = 0; $i < 10; $i++ ) {
			wc_create_order( array( 'status' => 'completed' ) );
		}
		wc_create_order( array( 'status' => 'pending' ) ); // Unpaid: not counted.
		$this->assertSame( '10-50', tvarkom_csa_order_tier() );
	}

	public function test_metrics_sent_with_a_full_scan() {
		// The fields of the gateway's metrics schema 2 (its README): schema 1's, which the audit
		// tool reads, then the business context. A change here is a contract change.
		$this->assertSame(
			array(
				'schema',
				'php_version',
				'wordpress_version',
				'woocommerce_version',
				'options_table_size_mb',
				'database_size_mb',
				'autoloaded_kb',
				'active_plugin_count',
				'object_cache_enabled',
				'object_cache_type',
				'action_scheduler_pending',
				'action_scheduler_past_due',
				'action_scheduler_failed',
				'action_scheduler_failed_7d',
				'cron_disabled',
				'cron_oldest_overdue_seconds',
				'order_tier',
				'country',
				'currency',
				'orders_30d',
				'orders_failed_30d',
				'orders_pending_30d',
				'orders_cancelled_30d',
				'sales_30d_range',
				'aov_range',
				'customers_new_30d',
				'customers_returning_30d',
				'products',
				'variations',
				'stock_management',
				'payment_gateways',
				'shipping_methods',
				'checkout_type',
				'hpos',
				'database_server',
				'web_server',
				'template_overrides_outdated',
				'plugins_updates_pending',
				'backup_plugin',
				'email_from_domain',
				'funnel_days',
				'funnel_add_to_cart',
				'funnel_checkout_views',
				'funnel_orders',
				'order_sources',
				'utm_sources',
			),
			array_keys( tvarkom_csa_collect_metrics() )
		);
		$this->assertSame( 2, tvarkom_csa_collect_metrics()['schema'] );
	}
}
