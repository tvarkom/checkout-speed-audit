<?php
/**
 * PHPStan only: constants that wp-config.php and WordPress core define, missing from the stubs.
 *
 * @package CheckoutSpeedAudit
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- WordPress's own constants, never loaded by the plugin.
define( 'DB_NAME', 'wordpress' );
define( 'WPINC', 'wp-includes' );
