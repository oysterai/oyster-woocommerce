<?php
/**
 * Plugin Name:       Oyster for WooCommerce
 * Plugin URI:        https://oysterskin.com/woocommerce
 * Description:       Add Oyster's AI face-scan skincare concierge to your WooCommerce store. Shoppers scan their skin, get personalized product recommendations from your catalog, and check out natively — with every order attributed back to the scan.
 * Version:           0.3.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Oyster Skin
 * Author URI:        https://oysterskin.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       oyster-woocommerce
 * Domain Path:       /languages
 *
 * WC requires at least: 8.0
 * WC tested up to:      9.6
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo;

// Block direct access.
defined( 'ABSPATH' ) || exit;

/*
 * ---------------------------------------------------------------------------
 * Constants
 * ---------------------------------------------------------------------------
 */
define( 'OYSTER_WOO_VERSION', '0.3.0' );
define( 'OYSTER_WOO_FILE', __FILE__ );
define( 'OYSTER_WOO_PATH', plugin_dir_path( __FILE__ ) );
define( 'OYSTER_WOO_URL', plugin_dir_url( __FILE__ ) );
define( 'OYSTER_WOO_MIN_PHP', '8.1' );

/*
 * ---------------------------------------------------------------------------
 * PSR-4-ish autoloader for the Oyster\Woo namespace.
 *
 * Oyster\Woo\Api\Client        -> includes/Api/Client.php
 * Oyster\Woo\Support\Connection -> includes/Support/Connection.php
 *
 * Kept dependency-free (no Composer) so the release zip is self-contained and
 * passes WordPress.org review without a vendor/ tree.
 * ---------------------------------------------------------------------------
 */
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$path     = OYSTER_WOO_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/*
 * ---------------------------------------------------------------------------
 * Environment guards
 * ---------------------------------------------------------------------------
 */

/**
 * Bail out early (with an admin notice) if the runtime can't support the plugin.
 * Returning false here prevents bootstrap so we never fatal on activation.
 */
function meets_requirements(): bool {
	if ( version_compare( PHP_VERSION, OYSTER_WOO_MIN_PHP, '<' ) ) {
		add_action(
			'admin_notices',
			static function (): void {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html(
						sprintf(
							/* translators: 1: required PHP version, 2: current PHP version */
							__( 'Oyster for WooCommerce requires PHP %1$s or newer. You are running %2$s.', 'oyster-woocommerce' ),
							OYSTER_WOO_MIN_PHP,
							PHP_VERSION
						)
					)
				);
			}
		);

		return false;
	}

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			static function (): void {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__( 'Oyster for WooCommerce requires WooCommerce to be installed and active.', 'oyster-woocommerce' )
				);
			}
		);

		return false;
	}

	return true;
}

/*
 * ---------------------------------------------------------------------------
 * HPOS (High-Performance Order Storage) compatibility.
 *
 * Declared unconditionally on before_woocommerce_init — this plugin never
 * touches the legacy order-post tables directly (all order reads/writes go
 * through the CRUD API), so it is HPOS-safe.
 * ---------------------------------------------------------------------------
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				OYSTER_WOO_FILE,
				true
			);
		}
	}
);

/*
 * ---------------------------------------------------------------------------
 * Bootstrap
 *
 * WooCommerce loads on `plugins_loaded` priority 10; we boot at 20 so
 * `class_exists( 'WooCommerce' )` is reliable in the requirements guard.
 * ---------------------------------------------------------------------------
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! meets_requirements() ) {
			return;
		}

		Plugin::instance()->boot();
	},
	20
);

/*
 * ---------------------------------------------------------------------------
 * Lifecycle hooks — registered at top level per WP guidance.
 * ---------------------------------------------------------------------------
 */
register_activation_hook( __FILE__, array( Plugin::class, 'on_activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'on_deactivate' ) );
