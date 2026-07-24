<?php
/**
 * Deep-link to the Oyster vendor dashboard.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * In the hybrid admin model, native WP/Woo screens own Connect and Widget
 * settings; analytics, orders, and billing stay in the existing Oyster vendor
 * dashboard. Every connected admin screen surfaces a link there so a merchant
 * is never more than one click from the rest of their account, regardless of
 * which Oyster screen they're currently on.
 */
final class Dashboard_Link {

	private const DEFAULT_URL = 'https://dash.oysterskin.com';

	/**
	 * Not filterable — a plugin hooking this could repoint every "Open
	 * dashboard" link at a lookalike phishing page an already-authenticated
	 * admin might not think twice about clicking. The only override is the
	 * `OYSTER_WOO_DASHBOARD_URL` wp-config constant, validated by Url_Guard;
	 * see its class doc for why nothing here is a filter.
	 */
	public static function url(): string {
		return Url_Guard::resolve( 'OYSTER_WOO_DASHBOARD_URL', self::DEFAULT_URL );
	}

	/**
	 * Echoes a single link/button. Always opens in a new tab — merchants stay
	 * on the WP admin screen they were on.
	 */
	public static function render_button( string $label = '', string $class = 'button' ): void {
		$label = '' !== $label ? $label : __( 'Open Oyster dashboard', 'oyster-woocommerce' );

		printf(
			'<a class="%s" href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_attr( $class ),
			esc_url( self::url() ),
			esc_html( $label )
		);
	}
}
