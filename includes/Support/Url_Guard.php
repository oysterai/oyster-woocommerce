<?php
/**
 * Shared resolver for security-sensitive, wp-config-overridable URLs.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a security-sensitive URL (the Oyster API base, the storefront widget
 * script, the vendor-dashboard deep-link) from a wp-config constant — never
 * from a filter. A WordPress filter has no permission model: any active
 * plugin or theme can hook one, so a filter here would let any of them
 * silently redirect API traffic (bearer included), swap in an arbitrary
 * script to execute on every storefront visit, or repoint an admin's
 * "Open dashboard" click at a phishing page. A wp-config constant is a
 * materially higher bar — wp-config.php loads before any plugin code runs,
 * so a normally-installed plugin cannot set or race it.
 *
 * If you're tempted to add `apply_filters()` back around any URL resolved
 * here "for flexibility," don't — that reopens the exact hole this exists
 * to close. Add a new constant and call `resolve()` instead.
 *
 * Even a constant's value isn't trusted blindly: `https://` is only accepted
 * on Oyster's own infrastructure (`ALLOWED_HTTPS_ROOT_DOMAINS`), and
 * `http://` only on a loopback host (a local dev tunnel). Anything else is
 * rejected and logged, falling back to the caller's default — a malformed or
 * unexpected constant value never silently sends anything to an arbitrary
 * host, even one that happens to be https.
 */
final class Url_Guard {

	/**
	 * Root domains Oyster's own infrastructure runs on. A value is accepted
	 * only if its host is exactly one of these or a genuine subdomain of one
	 * — never an arbitrary host, even over https.
	 */
	private const ALLOWED_HTTPS_ROOT_DOMAINS = array(
		'oysterskin.com',
		'oysterskin.ai',
		'oyster.skin',
	);

	/**
	 * @param string $constant Name of the wp-config constant that may override $default.
	 * @param string $default  Value used when the constant is undefined or fails validation.
	 */
	public static function resolve( string $constant, string $default ): string {
		if ( ! defined( $constant ) ) {
			return $default;
		}

		$candidate = untrailingslashit( (string) constant( $constant ) );

		if ( self::is_allowed( $candidate ) ) {
			return $candidate;
		}

		self::log(
			sprintf(
				'%s "%s" was rejected (must be https:// on Oyster infrastructure, or http:// on localhost) — using the default instead.',
				$constant,
				$candidate
			)
		);

		return $default;
	}

	/**
	 * `https://` is allowed only on Oyster's own domains (exact match or any
	 * subdomain); `http://` only on a loopback host, since that only ever
	 * matches a local dev server, never a plausible production redirect
	 * target.
	 */
	private static function is_allowed( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		$host = strtolower( $parts['host'] );

		if ( 'https' === $parts['scheme'] ) {
			return self::is_oyster_host( $host );
		}

		return 'http' === $parts['scheme']
			// parse_url() keeps the brackets on a bracketed IPv6 literal
			// (host is "[::1]", not "::1") — both listed defensively.
			&& in_array( $host, array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true );
	}

	/**
	 * Exact match, or a genuine subdomain — a suffix check with the dot
	 * boundary enforced, NOT a substring check, so a host like
	 * "notoysterskin.com" or "oysterskin.com.evil.example" correctly does
	 * NOT match "oysterskin.com". Any future addition to
	 * ALLOWED_HTTPS_ROOT_DOMAINS must keep this shape.
	 */
	private static function is_oyster_host( string $host ): bool {
		foreach ( self::ALLOWED_HTTPS_ROOT_DOMAINS as $root ) {
			if ( $host === $root || str_ends_with( $host, '.' . $root ) ) {
				return true;
			}
		}

		return false;
	}

	private static function log( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $message, array( 'source' => 'oyster-woocommerce' ) );
		}
	}
}
