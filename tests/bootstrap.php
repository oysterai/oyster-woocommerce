<?php
/**
 * Bootstrap for the unit suite.
 *
 * WordPress is deliberately not loaded. The classes covered here are plain PHP
 * that happens to live in a plugin, and standing up WordPress + WooCommerce to
 * test a domain-suffix check would trade a suite that runs in a second for one
 * that needs a database.
 *
 * What that costs is honesty about the seam: every WordPress function these
 * classes touch is stubbed below, and a stub that drifts from WordPress'
 * real behaviour is a test that passes while production breaks. So the stubs
 * are kept to the few genuinely trivial ones — string helpers and a
 * dictionary — and anything with real WordPress semantics (hooks, queries,
 * sanitisers with their own escaping rules) is out of scope for this suite by
 * design rather than stubbed into something plausible-looking.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

// The plugin files all guard on this; without it every include exits.
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * WordPress' time constants. Plain integers with no behaviour of their own, so
 * unlike a stubbed function there is nothing here that can drift from what
 * WordPress does. Defined because classes use them in constant expressions,
 * which are evaluated at class-definition time.
 */
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );
defined( 'WEEK_IN_SECONDS' ) || define( 'WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS );

/**
 * Translation passthrough. WordPress returns the string unchanged when no
 * translation is loaded, which is exactly the case under test.
 */
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string { // phpcs:ignore
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string { // phpcs:ignore
		return $text;
	}
}

/**
 * Verbatim from WordPress' own implementation: trailing slashes stripped, then
 * one added back is untrailingslashit's inverse. Copied rather than approximated
 * because the exact handling of a bare "https://host/" matters to Url_Guard.
 */
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( string $string ): string {
		return rtrim( $string, '/\\' );
	}
}

/**
 * WordPress' wrapper around parse_url(). The real one adds handling for
 * protocol-relative URLs on old PHP; on any version this plugin supports it
 * defers to parse_url, which is what is reproduced here.
 */
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		return trim( strip_tags( $str ) ); // phpcs:ignore
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( string $str ): string {
		return trim( strip_tags( $str ) ); // phpcs:ignore
	}
}

/**
 * Accepts the 3- and 6-digit forms and returns null otherwise, matching what
 * WordPress does — the null return is the branch Widget_Settings relies on.
 */
if ( ! function_exists( 'sanitize_hex_color' ) ) {
	function sanitize_hex_color( string $color ): ?string {
		return preg_match( '|^#([A-Fa-f0-9]{3}){1,2}$|', $color ) ? $color : null;
	}
}
