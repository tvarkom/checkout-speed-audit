<?php
/**
 * PHPUnit bootstrap: the WordPress test suite with WooCommerce and this plugin loaded.
 * See DEVELOPMENT.md for how to run it.
 *
 * @package CheckoutSpeedAudit
 */

$tvarkom_csa_root = dirname( __DIR__ );

if ( false === getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- How wp-phpunit finds the config.
}
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $tvarkom_csa_root . '/vendor/yoast/phpunit-polyfills' );

require_once $tvarkom_csa_root . '/vendor/autoload.php';
require_once $tvarkom_csa_root . '/vendor/wp-phpunit/wp-phpunit/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () use ( $tvarkom_csa_root ) {
		require WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		require $tvarkom_csa_root . '/checkout-speed-audit.php';
	}
);

// WooCommerce's tables, pages and roles (manage_woocommerce), as in WooCommerce's own test bootstrap.
tests_add_filter(
	'setup_theme',
	function () {
		WC_Install::install();
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reload the roles WooCommerce just changed.
		wp_roles();
	}
);

require $tvarkom_csa_root . '/vendor/wp-phpunit/wp-phpunit/includes/bootstrap.php';
require __DIR__ . '/class-tvarkom-csa-redirect.php';
require __DIR__ . '/class-tvarkom-csa-test-case.php';
