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

	public function test_order_volume_is_sent_as_a_range() {
		$this->assertSame( '0-10', tvarkom_csa_order_tier() );
		for ( $i = 0; $i < 10; $i++ ) {
			wc_create_order( array( 'status' => 'completed' ) );
		}
		wc_create_order( array( 'status' => 'pending' ) ); // Unpaid: not counted.
		$this->assertSame( '10-50', tvarkom_csa_order_tier() );
	}

	public function test_metrics_sent_with_consent() {
		// The fields the gateway's metrics schema 1 expects; a change here is a contract change.
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
			),
			array_keys( tvarkom_csa_collect_metrics() )
		);
	}
}
