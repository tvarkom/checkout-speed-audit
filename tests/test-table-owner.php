<?php
/**
 * Who a database table belongs to (detailed.php): the report suggests removing only tables of
 * known plugins that aren't installed, so every other case must stay out of that class.
 *
 * @package CheckoutSpeedAudit
 */

/**
 * Table classification.
 */
class Tvarkom_CSA_Table_Owner_Test extends Tvarkom_CSA_Test_Case {

	/**
	 * Installed plugins and themes like a real store's: Defender Pro (folder wp-defender) and
	 * Elementor active, the Woodmart theme active, an old theme and a form plugin switched off.
	 *
	 * @return array{plugins: array<string, array{0: string, 1: bool}>, themes: array<string, array{0: string, 1: bool}>, names: array<string, bool>}
	 */
	private function components() {
		$out = array(
			'plugins' => array(),
			'themes'  => array(),
			'names'   => array(),
		);
		foreach ( array(
			array( 'wp-defender', 'Defender Pro', true ),
			array( 'elementor', 'Elementor', true ),
			array( 'wordfence', 'Wordfence Security', true ),
			array( 'old-forms', 'Old Forms', false ),
		) as list( $folder, $name, $on ) ) {
			foreach ( tvarkom_csa_slug_variants( $folder ) as $prefix ) {
				$out['plugins'][ $prefix ] = array( $name, $on );
			}
			$out['names'][ $name ] = $on;
		}
		foreach ( array( array( 'woodmart', 'Woodmart', true ), array( 'oldtheme', 'Old Theme', false ) ) as list( $slug, $name, $on ) ) {
			foreach ( tvarkom_csa_slug_variants( $slug ) as $prefix ) {
				$out['themes'][ $prefix ] = array( $name, $on );
			}
		}
		return $out;
	}

	/**
	 * Tables and their expected classification and owner.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string|null}>
	 */
	public function data_tables() {
		return array(
			'active plugin, prefix without its "wp-"'  => array( 'defender_antibot', 'active', 'Defender Pro' ),
			'active theme'                             => array( 'woodmart_wishlists', 'active', 'Woodmart' ),
			'Elementor table named e_'                 => array( 'e_events', 'active', 'Elementor' ),
			'known prefix, installed under a longer name' => array( 'wfconfig', 'active', 'Wordfence' ),
			'WooCommerce'                              => array( 'wc_orders', 'active', 'WooCommerce' ),
			'switched-off plugin'                      => array( 'old_forms_entries', 'inactive', 'Old Forms' ),
			'switched-off theme'                       => array( 'oldtheme_settings', 'inactive', 'Old Theme' ),
			'known plugin not installed'               => array( 'aioseo_posts', 'not_installed', 'All in One SEO' ),
			'nothing identified'                       => array( 'old_stats', 'unknown', null ),
			'short prefix never says not installed'    => array( 'gf_entry', 'unknown', null ),
		);
	}

	/**
	 * Each table gets the expected class and owner.
	 *
	 * @dataProvider data_tables
	 *
	 * @param string      $table          Table name without the site prefix.
	 * @param string      $classification Expected class.
	 * @param string|null $owner          Expected owner.
	 */
	public function test_table_owner( $table, $classification, $owner ) {
		$this->assertSame( array( $classification, $owner ), tvarkom_csa_table_owner( $table, $this->components() ) );
	}

	/**
	 * Folder names give their table prefixes, without short or generic ones.
	 */
	public function test_slug_variants() {
		$this->assertSame( array( 'wp_defender', 'defender' ), tvarkom_csa_slug_variants( 'wp-defender' ) );
		$this->assertSame( array( 'elementor_pro', 'elementor' ), tvarkom_csa_slug_variants( 'elementor-pro' ) );
		$this->assertSame( array( 'woodmart' ), tvarkom_csa_slug_variants( 'woodmart' ) );
		$this->assertSame( array( 'wp_seo' ), tvarkom_csa_slug_variants( 'wp-seo' ), '"seo" alone is too short to tie a table to it' );
	}

	/**
	 * On the test site, the active theme and WooCommerce count as installed and active.
	 */
	public function test_installed_components_include_the_active_theme() {
		$components = tvarkom_csa_installed_components();
		$theme      = str_replace( '-', '_', strtolower( get_stylesheet() ) );
		$this->assertArrayHasKey( $theme, $components['themes'] );
		$this->assertTrue( $components['themes'][ $theme ][1] );
		$this->assertSame( 'active', tvarkom_csa_table_owner( $theme . '_settings', $components )[0] );
	}

	/**
	 * Every listed table carries a classification the scanner reads, and never "active".
	 */
	public function test_orphaned_tables_carry_a_classification() {
		foreach ( tvarkom_csa_orphaned_tables() as $table ) {
			$this->assertContains( $table['classification'], array( 'not_installed', 'inactive', 'unknown' ) );
			$this->assertArrayHasKey( 'owner', $table );
		}
	}
}
