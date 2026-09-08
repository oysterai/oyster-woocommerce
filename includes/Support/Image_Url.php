<?php
/**
 * Percent-encoding for media URLs on their way out of WordPress.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the link WordPress reports for an attachment into one that is actually
 * a valid URL.
 *
 * WordPress accepts characters in an attachment filename that a URL cannot
 * carry unescaped: spaces, square brackets, and anything outside ASCII. A
 * browser fetches the resulting link happily, so nothing looks wrong in the
 * media library, but a consumer that validates the string rejects it, and the
 * product it belongs to is rejected with it.
 *
 * Escapes already present are kept as they are, so a link that arrives encoded
 * does not gain a second layer.
 */
final class Image_Url {

	/** RFC 3986 pchar, plus the "/" that separates path segments. */
	private const PATH_SAFE = 'A-Za-z0-9\-._~!$&\'()*+,;=:@/';

	/** A query may carry "?" literally as well. */
	private const QUERY_SAFE = self::PATH_SAFE . '?';

	/**
	 * @param string $url Link as WordPress reported it.
	 * @return string The same link with its path, query and fragment encoded.
	 */
	public static function encode( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url

		// Too little structure to rebuild, so hand it back untouched rather than
		// guess at what was meant.
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return $url;
		}

		$encoded = $parts['scheme'] . '://';

		if ( isset( $parts['user'] ) ) {
			$encoded .= $parts['user'];
			$encoded .= isset( $parts['pass'] ) ? ':' . $parts['pass'] : '';
			$encoded .= '@';
		}

		$encoded .= $parts['host'];
		$encoded .= isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$encoded .= isset( $parts['path'] ) ? self::encode_part( $parts['path'], self::PATH_SAFE ) : '';
		$encoded .= isset( $parts['query'] ) ? '?' . self::encode_part( $parts['query'], self::QUERY_SAFE ) : '';
		$encoded .= isset( $parts['fragment'] ) ? '#' . self::encode_part( $parts['fragment'], self::QUERY_SAFE ) : '';

		return $encoded;
	}

	/**
	 * Byte-wise on purpose: an uploaded filename can hold bytes that are not
	 * valid UTF-8, and a /u pattern refuses to match anything at all against
	 * those.
	 *
	 * @param string $value Component to encode.
	 * @param string $safe  Character-class body of what may stay literal.
	 * @return string
	 */
	private static function encode_part( string $value, string $safe ): string {
		$encoded = preg_replace_callback(
			'#%[0-9A-Fa-f]{2}|[^' . $safe . ']#',
			static function ( array $match ): string {
				return 3 === strlen( $match[0] ) ? $match[0] : rawurlencode( $match[0] );
			},
			$value
		);

		return null === $encoded ? $value : $encoded;
	}
}
