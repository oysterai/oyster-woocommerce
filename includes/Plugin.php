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
use Oyster\Woo\Admin\Widget_Settings_Screen;
use Oyster\Woo\Api\Client;
use Oyster\Woo\Checkout\Cart_Controller;
use Oyster\Woo\Checkout\Order_Attribution;
use Oyster\Woo\Frontend\Widget_Loader;
use Oyster\Woo\Support\Connection;
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
		$this->client     = new Client( $this->connection );
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
	 * Product_Hooks, Cart_Controller, and Order_Attribution are the exception
	 * — they register unconditionally, because their work (WooCommerce hooks,
	 * REST routes, Action Scheduler callbacks, checkout/payment events) fires
	 * from storefront requests, REST API requests, WP-CLI, and the Action
	 * Scheduler queue runner, none of which are `is_admin()`.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Storefront: inject the widget loader on every front-end request.
		( new Widget_Loader( $this->connection ) )->register();

		$catalog_sync = new Catalog_Sync( $this->connection, $this->client );
		$catalog_sync->register();
		( new Product_Hooks( $catalog_sync ) )->register();

		// Storefront: the widget's checkout handoff (cart add) + attribution
		// from cart through to a reported paid order.
		( new Cart_Controller( $this->connection, $this->client ) )->register();
		( new Order_Attribution( $this->connection, $this->client ) )->register();

		if ( is_admin() ) {
			$connect = new Connect_Screen( $this->connection, $this->client );
			$widget  = new Widget_Settings_Screen( $this->connection, $this->client );
			$catalog = new Catalog_Screen( $this->connection, $catalog_sync );

			$connect->register();
			$widget->register();
			$catalog->register();

			( new Menu( $connect, $widget, $catalog ) )->register();
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
