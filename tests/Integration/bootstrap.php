<?php
/**
 * Bootstrap for the integration suite.
 *
 * Unlike the unit suite, this loads a real WordPress and a real WooCommerce.
 * Nothing here is stubbed: hooks fire, post meta round-trips through the
 * database, and WC_Product objects are the genuine article. That is the point —
 * it covers precisely what the unit suite cannot, and what a stub would only
 * pretend to.
 *
 * Runs inside wp-env, which provides WordPress' own PHPUnit test library and
 * points WP_TESTS_DIR at it.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = '/wordpress-phpunit';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		"Could not find WordPress' test library at {$_tests_dir}.\n" .
		"This suite runs inside wp-env — try: npm run test:integration\n"
	);
	exit( 1 );
}

/**
 * WordPress' test suite is built on Yoast's PHPUnit Polyfills and refuses to
 * boot without them. Pointed at explicitly rather than relying on the Composer
 * autoloader having been pulled in first, which is the difference between a
 * clear failure and a confusing one.
 */
$_polyfills = dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills';

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) && is_dir( $_polyfills ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_polyfills );
}

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once $_tests_dir . '/includes/functions.php';

/**
 * WooCommerce first, then this plugin: our hooks assume WooCommerce's classes
 * and functions already exist, which is also the order WordPress loads them in
 * on a real site.
 */
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require_once WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		require_once dirname( __DIR__, 2 ) . '/oyster-woocommerce.php';
	}
);

/**
 * WooCommerce keeps its own tables and roles outside WordPress' schema, and the
 * test installer does not know about them. Without this, anything touching a
 * product or an order fails on a missing table rather than on the thing under
 * test.
 */
tests_add_filter(
	'setup_theme',
	static function (): void {
		if ( ! class_exists( 'WC_Install' ) ) {
			return;
		}

		WC_Install::install();

		// install() adds WooCommerce's roles; the cached instance predates them.
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_roles();
	}
);

require $_tests_dir . '/includes/bootstrap.php';
