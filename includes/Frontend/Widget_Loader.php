<?php
/**
 * Storefront widget injection.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Frontend;

use Oyster\Woo\Checkout\Scan_Payment;
use Oyster\Woo\Support\Connection;
use Oyster\Woo\Support\Url_Guard;
use Oyster\Woo\Support\Widget_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Puts the Oyster widget on the storefront. This plugin runs server-side
 * inside WordPress, so it injects the resolved public key + display config
 * inline as a JS global rather than fetching it client-side. The loader
 * script (assets/js/oyster-loader.js) reads that global and boots
 * createScanWidget() for each anchor on the page.
 *
 * Anchors come from three sources, all sharing the same loader:
 *   - the floating launcher (wp_footer, when enabled)
 *   - the [oyster_scan] shortcode (inline)
 *   - the oyster/skin-scan block (inline)
 */
final class Widget_Loader {

	private const HANDLE = 'oyster-woo-loader';

	private const DEFAULT_BUNDLE = 'https://widget-lib.oysterskin.com/v1/oysterskin-vendor-widget-web.umd.js';

	public function __construct( private Connection $connection ) {}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_float_launcher' ) );
		add_shortcode( 'oyster_scan', array( $this, 'render_shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Whether the storefront can show the widget at all.
	 */
	private function is_active(): bool {
		return $this->connection->is_connected() && '' !== $this->connection->public_key();
	}


	/**
	 * Register (not enqueue) the loader + attach the inline config. Registering
	 * lets us enqueue on demand — only pages that actually render an anchor pull
	 * the script in. The config global is attached here so it rides along
	 * whenever the handle is enqueued.
	 */
	public function register_assets(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		wp_register_script(
			self::HANDLE,
			OYSTER_WOO_URL . 'assets/js/oyster-loader.js',
			array(),
			OYSTER_WOO_VERSION,
			true
		);

		wp_add_inline_script( self::HANDLE, 'window.OysterWooConfig = ' . wp_json_encode( $this->config() ) . ';', 'before' );

		// Float launcher is site-wide, so enqueue eagerly when it's on; inline
		// anchors enqueue lazily from their render methods.
		if ( Widget_Settings::get()['float_enabled'] ) {
			wp_enqueue_script( self::HANDLE );
		}
	}

	/**
	 * @return array<string, mixed> Non-secret config safe to expose on the storefront.
	 */
	private function config(): array {
		$settings = Widget_Settings::get();
		$color    = '' !== $settings['primary_color'] ? $settings['primary_color'] : $this->connection->primary_color();

		return array(
			'publicKey'    => $this->connection->public_key(),
			'primaryColor' => $color,
			'logoUrl'      => $this->connection->logo_url(),
			'loaderUrl'    => $this->bundle_url(),
			'app'          => 'woocommerce',
			// Fallback destination if the checkout handoff (cart/add) fails —
			// see oyster-loader.js's wooCheckoutHandoff catch handler. Better
			// than stranding the shopper on the widget with no way forward.
			'cartUrl'      => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
			// Where the loader raises a scan-payment order, for vendors set up to
			// take those payments through this store. Always present: whether it
			// gets used is Oyster's decision at scan time, not something the
			// storefront should try to predict.
			//
			// Root-relative, NOT the absolute URL rest_url() returns. That one is
			// built from the site's stored home URL, which routinely differs from
			// the host the shopper is actually on — `127.0.0.1` vs `localhost`, or
			// www vs bare. The browser treats those as different origins, so the
			// POST becomes cross-origin, gets a preflight, and WordPress's
			// canonical redirect answers that preflight with a 302 — which
			// browsers refuse outright. Relative keeps it same-origin whatever
			// host the page was served from.
			//
			// Still built from rest_url() rather than hard-coded, so a site with a
			// custom REST prefix keeps working.
			'scanPaymentUrl' => wp_make_link_relative( rest_url( 'oyster-woocommerce/v1/scan-payment/create' ) ),
		);
	}

	/**
	 * The floating launcher anchor + settings, emitted in the footer.
	 */
	public function render_float_launcher(): void {
		// A scan payment opens this very page in a popup, so the launcher
		// was inviting the shopper to start a scan on top of the checkout they
		// had already accepted one to reach — and had to be dismissed before
		// they could pay. Only that page: an ordinary pay-for-order page is the
		// merchant's own sale and none of this plugin's business.
		if ( ! $this->is_active() || Scan_Payment::is_paying_for_a_scan() ) {
			return;
		}

		$settings = Widget_Settings::get();
		if ( empty( $settings['float_enabled'] ) ) {
			return;
		}

		wp_enqueue_script( self::HANDLE );

		printf(
			'<div data-oyster-widget="launcher" data-mode="float" data-intro-message="%s" data-message-body="%s" data-display-logo="%s" data-auto-open="%s"></div>',
			esc_attr( (string) $settings['intro_message'] ),
			esc_attr( (string) $settings['message_body'] ),
			esc_attr( $settings['display_logo'] ? 'true' : 'false' ),
			esc_attr( $this->auto_open( $settings ) ? 'true' : 'false' )
		);
	}

	/**
	 * Whether the widget may open itself, which a purchase in progress overrides.
	 *
	 * A shopper who has reached the cart or the checkout has already decided
	 * what they are buying. Opening a scan over the page they are reviewing or
	 * paying on interrupts that and has to be dismissed before they can finish —
	 * the same interruption the scan payment page had, arriving on the
	 * merchant's own sale instead.
	 *
	 * The launcher itself stays: starting a scan from either page is a
	 * reasonable thing for a shopper to choose, and this only stops it being
	 * chosen for them.
	 *
	 * @param array<string, mixed> $settings
	 */
	private function auto_open( array $settings ): bool {
		if ( empty( $settings['auto_open'] ) ) {
			return false;
		}

		return ! $this->is_mid_purchase();
	}

	private function is_mid_purchase(): bool {
		if ( ! function_exists( 'is_cart' ) || ! function_exists( 'is_checkout' ) ) {
			return false;
		}

		return is_cart() || is_checkout();
	}

	/**
	 * [oyster_scan height="480"] — inline scan surface.
	 *
	 * @param array<string, mixed>|string $atts
	 */
	public function render_shortcode( $atts ): string {
		if ( ! $this->is_active() ) {
			return '';
		}

		$atts   = shortcode_atts( array( 'height' => 0 ), is_array( $atts ) ? $atts : array(), 'oyster_scan' );
		$height = max( 0, (int) $atts['height'] );

		wp_enqueue_script( self::HANDLE );

		return $this->inline_anchor( $height );
	}

	/**
	 * Register the dynamic "Oyster Skin Scan" block. Server-rendered — the
	 * editor script only provides a placeholder + a height control, so there is
	 * no front-end build step.
	 */
	public function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'oyster-woo-block',
			OYSTER_WOO_URL . 'assets/js/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			OYSTER_WOO_VERSION,
			true
		);

		register_block_type(
			'oyster/skin-scan',
			array(
				'api_version'     => 2,
				'title'           => __( 'Oyster Skin Scan', 'oyster-woocommerce' ),
				'category'        => 'woocommerce',
				'icon'            => 'visibility',
				'editor_script'   => 'oyster-woo-block',
				'attributes'      => array(
					'height' => array(
						'type'    => 'number',
						'default' => 762,
					),
				),
				'render_callback' => array( $this, 'render_block' ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public function render_block( array $attributes ): string {
		if ( ! $this->is_active() ) {
			return '';
		}

		wp_enqueue_script( self::HANDLE );

		return $this->inline_anchor( max( 0, (int) ( $attributes['height'] ?? 0 ) ) );
	}

	private function inline_anchor( int $height ): string {
		$height_attr = $height > 0 ? sprintf( ' data-inline-height="%d"', $height ) : '';

		return sprintf( '<div data-oyster-widget="inline" data-mode="inline"%s></div>', $height_attr );
	}

	/**
	 * This URL is loaded as a `<script src>` on every storefront page the
	 * widget appears on — an even higher-stakes value than the API base URL
	 * to leave filterable, since a redirected bundle is arbitrary JS running
	 * in every shopper's browser, not just a misdirected API call. Same
	 * wp-config-constant-only pattern as `Api\Client::base_url()`; see
	 * Url_Guard's class doc for why there's no filter here.
	 */
	private function bundle_url(): string {
		return Url_Guard::resolve( 'OYSTER_WOO_WIDGET_BUNDLE_URL', self::DEFAULT_BUNDLE );
	}
}
