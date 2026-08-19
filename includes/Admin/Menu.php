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

	public const CATALOG_SLUG = 'oyster-woocommerce-catalog';

	public const PRICING_SLUG = 'oyster-woocommerce-scan-pricing';

	/**
	 * Capability required to manage the integration. `manage_woocommerce` maps
	 * to Shop Managers + Admins, matching who configures other Woo extensions.
	 */
	public const CAPABILITY = 'manage_woocommerce';

	public function __construct(
		private Connect_Screen $connect,
		private Widget_Settings_Screen $widget,
		private Catalog_Screen $catalog,
		private Scan_Pricing_Screen $pricing
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

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Oyster — Catalog', 'oyster-woocommerce' ),
			__( 'Catalog', 'oyster-woocommerce' ),
			self::CAPABILITY,
			self::CATALOG_SLUG,
			array( $this->catalog, 'render' )
		);

		// Only relevant when this store takes the shopper's scan payment
		// itself. The screen says so rather than the menu hiding it, so a
		// merchant who was told they can set a price can find where.
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Oyster — Scan pricing', 'oyster-woocommerce' ),
			__( 'Scan pricing', 'oyster-woocommerce' ),
			self::CAPABILITY,
			self::PRICING_SLUG,
			array( $this->pricing, 'render' )
		);
	}

	/**
	 * The Oyster mark, served as a file URL rather than a base64 data URI.
	 *
	 * WordPress renders those two forms differently (wp-admin/menu-header.php):
	 * a `data:image/svg+xml;base64,` icon becomes a CSS `background-image` on
	 * `div.wp-menu-image.svg`, while any other URL becomes an `<img>`. That
	 * matters twice over:
	 *
	 * - Only the `<img>` form picks up core's `opacity: .6` → `1` on
	 *   hover/current, so a file URL dims and brightens exactly like every
	 *   built-in menu icon. The background-image form gets no such rule and
	 *   sits at full strength permanently.
	 * - An SVG used as a background-image is an isolated document, so
	 *   `currentColor` in it resolves to black instead of the menu's text
	 *   colour — which is why the previous placeholder rendered as a near
	 *   invisible dark blob against the default dark admin sidebar.
	 *
	 * The mark is therefore filled with the brand orange, which is legible on
	 * both the dark colour schemes and the light one — no admin scheme can
	 * recolour it for us.
	 */
	private function menu_icon(): string {
		return OYSTER_WOO_URL . 'assets/img/menu-icon.svg';
	}
}
