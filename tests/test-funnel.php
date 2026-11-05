<?php
/**
 * Funnel counters and order sources (includes/funnel.php): daily totals only, staff and the
 * checkout profile not counted, 35 days kept, attribution counts from WooCommerce.
 *
 * @package CheckoutSpeedAudit
 */

/**
 * The funnel.
 */
class Tvarkom_CSA_Funnel_Test extends Tvarkom_CSA_Test_Case {

	public function set_up() {
		parent::set_up();
		delete_option( TVARKOM_CSA_FUNNEL_SINCE );
		tvarkom_csa_funnel_install();
		unset( $_SERVER['HTTP_X_TVARKOM_PROFILE'] );
	}

	/**
	 * Today's row, or null.
	 *
	 * @return object|null
	 */
	private function today() {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE day = %s', tvarkom_csa_funnel_table(), gmdate( 'Y-m-d' ) ) );
	}

	public function test_events_are_counted_per_day() {
		tvarkom_csa_funnel_add_to_cart();
		tvarkom_csa_funnel_add_to_cart();
		tvarkom_csa_funnel_order();
		$row = $this->today();
		$this->assertSame( 2, (int) $row->add_to_cart );
		$this->assertSame( 0, (int) $row->checkout_views );
		$this->assertSame( 1, (int) $row->orders );
	}

	public function test_staff_and_the_checkout_profile_are_not_counted() {
		$this->sign_in_shop_manager();
		tvarkom_csa_funnel_add_to_cart();
		wp_set_current_user( 0 );
		$_SERVER['HTTP_X_TVARKOM_PROFILE'] = 'token';
		tvarkom_csa_funnel_order();
		unset( $_SERVER['HTTP_X_TVARKOM_PROFILE'] );
		$this->assertNull( $this->today() );
	}

	public function test_a_new_day_removes_days_past_35() {
		global $wpdb;
		$table = tvarkom_csa_funnel_table();
		$wpdb->insert(
			$table,
			array(
				'day'    => gmdate( 'Y-m-d', time() - 40 * DAY_IN_SECONDS ),
				'orders' => 5,
			)
		);
		$wpdb->insert(
			$table,
			array(
				'day'    => gmdate( 'Y-m-d', time() - 20 * DAY_IN_SECONDS ),
				'orders' => 3,
			)
		);
		tvarkom_csa_funnel_add_to_cart(); // Today's first event.
		$this->assertSame( '2', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) );
	}

	public function test_totals_cover_complete_days_since_counting_started() {
		$this->assertSame( 0, tvarkom_csa_funnel_totals()['funnel_days'], 'nothing before the first complete day' );

		global $wpdb;
		$table = tvarkom_csa_funnel_table();
		update_option( TVARKOM_CSA_FUNNEL_SINCE, gmdate( 'Y-m-d', time() - 10 * DAY_IN_SECONDS ) );
		$wpdb->insert(
			$table,
			array(
				'day'            => gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ),
				'add_to_cart'    => 40,
				'checkout_views' => 12,
				'orders'         => 4,
			)
		);
		tvarkom_csa_funnel_add_to_cart(); // Today: not complete, not counted yet.
		$this->assertSame(
			array(
				'funnel_days'           => 10,
				'funnel_add_to_cart'    => 40,
				'funnel_checkout_views' => 12,
				'funnel_orders'         => 4,
			),
			tvarkom_csa_funnel_totals()
		);
		update_option( TVARKOM_CSA_FUNNEL_SINCE, gmdate( 'Y-m-d', time() - 90 * DAY_IN_SECONDS ) );
		$this->assertSame( 30, tvarkom_csa_funnel_totals()['funnel_days'], 'at most 30 days' );
	}

	public function test_order_sources_from_order_attribution() {
		foreach ( array(
			'utm'      => 'Google',
			'organic'  => '',
			'referral' => '',
		) as $type => $utm ) {
			$order = wc_create_order( array( 'status' => 'completed' ) );
			$order->update_meta_data( '_wc_order_attribution_source_type', $type );
			if ( $utm ) {
				$order->update_meta_data( '_wc_order_attribution_utm_source', $utm );
			}
			$order->save();
		}
		$unpaid = wc_create_order( array( 'status' => 'pending' ) );
		$unpaid->update_meta_data( '_wc_order_attribution_source_type', 'utm' );
		$unpaid->save();

		$sources = tvarkom_csa_order_sources();
		ksort( $sources['order_sources'] );
		$this->assertSame(
			array(
				'organic'  => 1,
				'referral' => 1,
				'utm'      => 1,
			),
			$sources['order_sources']
		);
		$this->assertSame( array( 'google' => 1 ), $sources['utm_sources'] );
	}

	public function test_no_attribution_is_null() {
		$this->assertSame(
			array(
				'order_sources' => null,
				'utm_sources'   => null,
			),
			tvarkom_csa_order_sources()
		);
	}

	public function test_email_from_domain_is_the_domain_only() {
		update_option( 'woocommerce_email_from_address', 'Orders@Shop.Example.com' );
		$this->assertSame( 'shop.example.com', tvarkom_csa_email_from_domain() );
		update_option( 'woocommerce_email_from_address', 'not an address' );
		$this->assertNull( tvarkom_csa_email_from_domain() );
	}
}
