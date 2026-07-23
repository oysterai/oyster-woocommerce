<?php
/**
 * Plugin orchestrator.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo;

use Oyster\Woo\Admin\Connect_Screen;
use Oyster\Woo\Admin\Menu;
use Oyster\Woo\Admin\Widget_Settings_Screen;
use Oyster\Woo\Api\Client;
use Oyster\Woo\Frontend\Widget_Loader;
use Oyster\Woo\Support\Connection;

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
	 * storefront request never pays to load settings screens.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Storefront: inject the widget loader on every front-end request.
		( new Widget_Loader( $this->connection ) )->register();

		if ( is_admin() ) {
			$connect = new Connect_Screen( $this->connection, $this->client );
			$widget  = new Widget_Settings_Screen( $this->connection, $this->client );

			$connect->register();
			$widget->register();

			( new Menu( $connect, $widget ) )->register();
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
			as_unschedule_all_actions( '', array(), 'oyster-woocommerce' );
		}
	}
}
