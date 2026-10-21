<?php
/**
 * WordPress test suite configuration, read by vendor/wp-phpunit/wp-phpunit. Everything comes
 * from the environment so the same file works in CI (MySQL service) and locally.
 *
 * WP_CORE_DIR       WordPress to test against, with WooCommerce in wp-content/plugins/woocommerce.
 * WP_TESTS_DB_NAME  Database the suite may empty; never a real site's.
 * WP_TESTS_DB_USER, WP_TESTS_DB_PASSWORD, WP_TESTS_DB_HOST
 *
 * @package CheckoutSpeedAudit
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The test suite's own constants.

/**
 * An environment variable, or $fallback when it is unset or empty.
 *
 * @param string $name     Variable.
 * @param string $fallback Default.
 * @return string
 */
function tvarkom_csa_tests_env( $name, $fallback ) {
	$value = getenv( $name );
	return false === $value || '' === $value ? $fallback : $value;
}

define( 'ABSPATH', rtrim( tvarkom_csa_tests_env( 'WP_CORE_DIR', '/tmp/wordpress' ), '/' ) . '/' );

define( 'DB_NAME', tvarkom_csa_tests_env( 'WP_TESTS_DB_NAME', 'wordpress_test' ) );
define( 'DB_USER', tvarkom_csa_tests_env( 'WP_TESTS_DB_USER', 'root' ) );
define( 'DB_PASSWORD', tvarkom_csa_tests_env( 'WP_TESTS_DB_PASSWORD', '' ) );
define( 'DB_HOST', tvarkom_csa_tests_env( 'WP_TESTS_DB_HOST', 'localhost' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Store' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );

// A gateway that doesn't exist: every request to it is answered by the tests (Tvarkom_CSA_Test_Case).
define( 'TVARKOM_CSA_API_URL', 'https://gateway.test' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required by the test suite.
