<?php
/**
 * Admin menu registration.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the top-level "Oyster" menu and its subpages. Rendering is
 * delegated to the individual screen classes so this file only owns structure.
 */
final class Menu {

	public const PARENT_SLUG = 'oyster-woocommerce';

	public const WIDGET_SLUG = 'oyster-woocommerce-widget';

	/**
	 * Capability required to manage the integration. `manage_woocommerce` maps
	 * to Shop Managers + Admins, matching who configures other Woo extensions.
	 */
	public const CAPABILITY = 'manage_woocommerce';

	public function __construct(
		private Connect_Screen $connect,
		private Widget_Settings_Screen $widget
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_pages' ) );
	}

	public function add_pages(): void {
		add_menu_page(
			__( 'Oyster', 'oyster-woocommerce' ),
			__( 'Oyster', 'oyster-woocommerce' ),
			self::CAPABILITY,
			self::PARENT_SLUG,
			array( $this->connect, 'render' ),
			$this->menu_icon(),
			58 // Just below WooCommerce (55.x) in the admin menu.
		);

		// Relabel the auto-created first submenu (defaults to the menu title).
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Oyster — Connection', 'oyster-woocommerce' ),
			__( 'Connection', 'oyster-woocommerce' ),
			self::CAPABILITY,
			self::PARENT_SLUG,
			array( $this->connect, 'render' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Oyster — Widget', 'oyster-woocommerce' ),
			__( 'Widget', 'oyster-woocommerce' ),
			self::CAPABILITY,
			self::WIDGET_SLUG,
			array( $this->widget, 'render' )
		);
	}

	/**
	 * Inline SVG data URI so the menu icon ships with the plugin and inherits
	 * the admin menu's currentColor treatment.
	 */
	private function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="currentColor" d="M10 2C5.6 2 2 5.6 2 10s3.6 8 8 8 8-3.6 8-8-3.6-8-8-8zm0 3a5 5 0 0 1 5 5 5 5 0 0 1-5 5c-1.3 0-2.4-.5-3.3-1.3C7.5 13.5 8.7 14 10 14a4 4 0 0 0 0-8c-1.3 0-2.5.5-3.3 1.3A4.98 4.98 0 0 1 10 5z"/></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}
}
