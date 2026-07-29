<?php
/**
 * Plugin orchestrator.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo;

use Oyster\Woo\Admin\Catalog_Screen;
use Oyster\Woo\Admin\Connect_Screen;
use Oyster\Woo\Admin\Menu;
use Oyster\Woo\Admin\Setup_Guide;
use Oyster\Woo\Admin\Sync_Status_Column;
use Oyster\Woo\Admin\Widget_Settings_Screen;
use Oyster\Woo\Api\Client;
use Oyster\Woo\Catalog\Ingredients_Field;
use Oyster\Woo\Catalog\Size_Volume_Field;
use Oyster\Woo\Catalog\Skin_Type_Attribute;
use Oyster\Woo\Checkout\Cart_Controller;
use Oyster\Woo\Checkout\Cart_Filler;
use Oyster\Woo\Checkout\Email_Handoff;
use Oyster\Woo\Checkout\Order_Attribution;
use Oyster\Woo\Compliance\Gdpr;
use Oyster\Woo\Frontend\Widget_Loader;
use Oyster\Woo\Support\Connection;
use Oyster\Woo\Support\Self_Updater;
use Oyster\Woo\Sync\Catalog_Sync;
use Oyster\Woo\Sync\Product_Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Central wiring point. Owns the shared services (API client, connection store)
 * and hands them to the admin / frontend modules. One instance per request.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private Connection $connection;

	private Client $client;

	/**
	 * Guard so boot() is idempotent even if called twice.
	 */
	private bool $booted = false;

	private function __construct() {
		$this->connection = new Connection();
		$this->client     = new Client();
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function connection(): Connection {
		return $this->connection;
	}

	public function client(): Client {
		return $this->client;
	}

	/**
	 * Register hooks. Admin-only modules stay behind is_admin() so the
	 * storefront request never pays to load settings screens. Catalog_Sync,
	 * Product_Hooks, Cart_Controller, Email_Handoff, and Order_Attribution are
	 * the exception — they register unconditionally, because their work
	 * (WooCommerce hooks, REST routes, Action Scheduler callbacks,
	 * checkout/payment events) fires from storefront requests, REST API
	 * requests, WP-CLI, and the Action Scheduler queue runner, none of which
	 * are `is_admin()`.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Not distributed through wordpress.org, so core has no way to know a
		// new version exists on its own — this is what makes "Update
		// available" show up on the Plugins screen instead. Unconditional
		// (not is_admin()-gated): PUC hooks the update-check transients
		// itself and only ever surfaces UI on wp-admin regardless.
		Self_Updater::register();

		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Storefront: inject the widget loader on every front-end request.
		( new Widget_Loader( $this->connection ) )->register();

		$catalog_sync = new Catalog_Sync( $this->connection, $this->client );
		$catalog_sync->register();
		( new Product_Hooks( $catalog_sync ) )->register();

		// Storefront: the two ways a routine reaches the cart — the widget's
		// own checkout handoff and the scan-result email's CTA — plus
		// attribution from cart through to a reported paid order.
		$cart_filler = new Cart_Filler( $this->connection, $this->client );
		( new Cart_Controller( $cart_filler ) )->register();
		( new Email_Handoff( $this->connection, $cart_filler ) )->register();
		( new Order_Attribution( $this->connection, $this->client ) )->register();

		if ( is_admin() ) {
			$setup_guide = new Setup_Guide( $this->connection, $catalog_sync );
			$connect     = new Connect_Screen( $this->connection, $this->client, $setup_guide );
			$widget      = new Widget_Settings_Screen( $this->connection, $this->client );
			$catalog     = new Catalog_Screen( $this->connection, $catalog_sync );

			$setup_guide->register();
			$connect->register();
			$widget->register();
			$catalog->register();

			( new Menu( $connect, $widget, $catalog ) )->register();
			( new Sync_Status_Column() )->register();

			// Product-editor additions Oyster's recommendations rely on: an
			// ingredient list and a "Skin Type" attribute the catalog sync
			// forwards. Admin-only — the storefront just reads the stored data.
			( new Ingredients_Field() )->register();
			( new Size_Volume_Field() )->register();
			( new Skin_Type_Attribute() )->register();

			// Personal-data export/erase requests are processed via
			// admin-ajax.php (Tools > Export/Erase Personal Data), which is
			// `is_admin()` — safe to register alongside the settings screens.
			( new Gdpr() )->register();
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'oyster-woocommerce',
			false,
			dirname( plugin_basename( OYSTER_WOO_FILE ) ) . '/languages'
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Lifecycle
	 * -----------------------------------------------------------------------
	 */

	/**
	 * On activation we only seed defaults — no destructive work, and nothing
	 * that would fatal if WooCommerce is mid-activation. Connection state is
	 * created lazily on first connect.
	 */
	public static function on_activate(): void {
		if ( false === get_option( Connection::OPTION_KEY, false ) ) {
			add_option( Connection::OPTION_KEY, array() );
		}

		// Best-effort: seed the Skin Type attribute now if WooCommerce is
		// already loaded. If it isn't (activation order), the admin_init
		// self-heal in Skin_Type_Attribute::register() creates it on the next
		// admin load. Idempotent either way.
		Skin_Type_Attribute::maybe_ensure();
	}

	/**
	 * On deactivation, unschedule any recurring work. Data is preserved so a
	 * reactivate keeps the store connected; full teardown lives in uninstall.php.
	 */
	public static function on_deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), Catalog_Sync::ACTION_GROUP );
		}
	}
}
