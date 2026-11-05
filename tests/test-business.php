<?php
/**
 * The business context sent with an audit (includes/business.php): counts, euro ranges and
 * codes only, never amounts, names or addresses.
 *
 * @package CheckoutSpeedAudit
 */

/**
 * Metrics schema 2's business fields.
 */
class Tvarkom_CSA_Business_Test extends Tvarkom_CSA_Test_Case {

	/**
	 * Rates as GET /v1/client returns them: units per euro.
	 *
	 * @return array<string, mixed>
	 */
	private function rates() {
		return array(
			'date'  => '2026-11-05',
			'rates' => array(
				'EUR' => 1,
				'USD' => 1.25,
			),
		);
	}

	/**
	 * A paid order of $total in the store's currency, created today.
	 *
	 * @param float  $total  Order total.
	 * @param string $status Order status.
	 */
	private function order( $total, $status = 'completed' ) {
		$order = wc_create_order( array( 'status' => $status ) );
		$order->set_currency( get_woocommerce_currency() );
		$order->set_total( $total );
		$order->save();
	}

	/**
	 * Euro range edges: below the bound is the lower range, at the bound the next.
	 *
	 * @return array<string, array{0: float, 1: float, 2: string}>
	 */
	public function data_sales_ranges() {
		return array(
			'nothing'                  => array( 0, 1, '0-1000' ),
			'just under 1,000'         => array( 999.99, 1, '0-1000' ),
			'1,000'                    => array( 1000, 1, '1000-5000' ),
			'25,000 dollars at 1.25'   => array( 25000, 1.25, '5000-25000' ),
			'1,000,000'                => array( 1000000, 1, '1000000+' ),
			'no rate for the currency' => array( 500, 0, 'unsupported_currency' ),
		);
	}

	/**
	 * Sales ranges from an amount and a rate.
	 *
	 * @dataProvider data_sales_ranges
	 *
	 * @param float  $amount In the store's currency.
	 * @param float  $rate   Units per euro.
	 * @param string $range  Expected range.
	 */
	public function test_sales_ranges( $amount, $rate, $range ) {
		$this->assertSame( $range, tvarkom_csa_euro_range( $amount, $rate, TVARKOM_CSA_SALES_RANGES, '1000000+' ) );
	}

	public function test_rates_lookup() {
		$this->assertSame( 1.25, tvarkom_csa_eur_rate( $this->rates(), 'usd' ) );
		$this->assertSame( 0.0, tvarkom_csa_eur_rate( $this->rates(), 'XYZ' ) );
		$this->assertSame( 0.0, tvarkom_csa_eur_rate( null, 'EUR' ) );
		$this->assertSame( 0.0, tvarkom_csa_eur_rate( array( 'rates' => array( 'EUR' => 'x' ) ), 'EUR' ) );
	}

	public function test_sales_orders_and_ranges() {
		update_option( 'woocommerce_currency', 'USD' );
		$this->order( 100 );
		$this->order( 150, 'processing' );
		$this->order( 50, 'on-hold' );
		$this->order( 999, 'pending' ); // Not paid: not sales.
		$this->order( 999, 'failed' );

		$data = tvarkom_csa_collect_business( $this->rates() );

		$this->assertSame( 'USD', $data['currency'] );
		$this->assertSame( 3, $data['orders_30d'] );
		$this->assertSame( 1, $data['orders_pending_30d'] );
		$this->assertSame( 1, $data['orders_failed_30d'] );
		$this->assertSame( 0, $data['orders_cancelled_30d'] );
		$this->assertSame( '0-1000', $data['sales_30d_range'] ); // 300 dollars = 240 euros.
		$this->assertSame( '50-100', $data['aov_range'] ); // 100 dollars = 80 euros.
		$this->assertSame( 'unsupported_currency', tvarkom_csa_collect_business( null )['sales_30d_range'] );
	}

	public function test_no_orders_has_no_average() {
		$this->assertNull( tvarkom_csa_collect_business( $this->rates() )['aov_range'] );
	}

	public function test_store_settings() {
		update_option( 'woocommerce_default_country', 'LT' );
		update_option( 'woocommerce_manage_stock', 'yes' );
		$product = new WC_Product_Simple();
		$product->set_status( 'publish' );
		$product->save();

		$data = tvarkom_csa_collect_business( $this->rates() );

		$this->assertSame( 'LT', $data['country'] );
		$this->assertTrue( $data['stock_management'] );
		$this->assertSame( 1, $data['products'] );
		$this->assertIsArray( $data['payment_gateways'] );
		$this->assertIsArray( $data['shipping_methods'] );
		$this->assertIsBool( $data['hpos'] );
		$this->assertMatchesRegularExpression( '/^(MySQL|MariaDB) \d+\.\d+$/', (string) $data['database_server'] );
	}

	public function test_checkout_type() {
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<!-- wp:woocommerce/checkout /-->',
			)
		);
		update_option( 'woocommerce_checkout_page_id', $page );
		$this->assertSame( 'block', tvarkom_csa_checkout_type() );
		wp_update_post(
			array(
				'ID'           => $page,
				'post_content' => '[woocommerce_checkout]',
			)
		);
		$this->assertSame( 'classic', tvarkom_csa_checkout_type() );
	}

	public function test_enabled_payment_gateways_only() {
		update_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes' ) );
		update_option( 'woocommerce_bacs_settings', array( 'enabled' => 'no' ) );
		WC()->payment_gateways()->init();
		$ids = tvarkom_csa_payment_gateway_ids();
		$this->assertContains( 'cod', $ids );
		$this->assertNotContains( 'bacs', $ids );
	}

	public function test_backup_plugin() {
		$this->assertNull( tvarkom_csa_backup_plugin() );
		update_option( 'active_plugins', array( 'akismet/akismet.php', 'updraftplus/updraftplus.php' ) );
		$this->assertSame( 'UpdraftPlus', tvarkom_csa_backup_plugin() );
	}

	public function test_web_server_is_the_product_name_only() {
		$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25.3 (Ubuntu)';
		$this->assertSame( 'nginx', tvarkom_csa_web_server() );
		$_SERVER['SERVER_SOFTWARE'] = '';
		$this->assertNull( tvarkom_csa_web_server() );
	}

	public function test_the_audit_converts_with_the_gateways_rates() {
		tvarkom_csa_update_settings(
			array(
				'api_key' => 'tvk_test',
				'email'   => 'owner@example.org',
			)
		);
		update_option( 'woocommerce_currency', 'USD' );
		$this->order( 2000 );
		$this->respond( 'GET', '/v1/client', 200, array_merge( $this->client_status(), array( 'eur_rates' => $this->rates() ) ) );
		$this->respond( 'POST', '/v1/audits', 202, array( 'status' => 'queued' ) );

		tvarkom_csa_run_audit();

		$metrics = $this->requests[1]['body']['metrics'];
		$this->assertSame( 2, $metrics['schema'] );
		$this->assertSame( '1000-5000', $metrics['sales_30d_range'] ); // 2,000 dollars = 1,600 euros.
		$this->assertArrayNotHasKey( 'sales_30d', $metrics, 'no amount leaves the store' );
	}
}
