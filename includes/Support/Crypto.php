<?php
/**
 * At-rest encryption for the vendor bearer token.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * AES-256-CBC using a key derived from the site's `auth` salt.
 *
 * Threat model: this protects the bearer against a leaked DB dump (backups,
 * SQL exports, shared hosting snapshots). It does NOT defend against an
 * attacker who already has both the database and wp-config.php — that is the
 * standard, accepted ceiling for secrets stored by a WordPress plugin. Storing
 * the raw Sanctum token in plaintext would be strictly worse, so we encrypt.
 *
 * Ciphertext is versioned (`v1:`) so the scheme can rotate without a migration.
 */
final class Crypto {

	private const VERSION = 'v1';

	private const CIPHER = 'aes-256-cbc';

	/**
	 * Encrypt a value for storage. Returns null on empty input so callers can
	 * clear the stored token by passing an empty string.
	 */
	public static function encrypt( string $plaintext ): ?string {
		if ( '' === $plaintext ) {
			return null;
		}

		if ( ! self::openssl_available() ) {
			// Last-resort fallback: base64 so the value round-trips, tagged
			// distinctly so decrypt() knows not to attempt an openssl pass.
			return 'b64:' . base64_encode( $plaintext );
		}

		$iv     = random_bytes( openssl_cipher_iv_length( self::CIPHER ) );
		$cipher = openssl_encrypt( $plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv );

		if ( false === $cipher ) {
			return null;
		}

		return self::VERSION . ':' . base64_encode( $iv . $cipher );
	}

	/**
	 * Decrypt a stored value. Returns null when the payload is missing or
	 * tampered with, so callers treat that as "not connected".
	 */
	public static function decrypt( ?string $stored ): ?string {
		if ( null === $stored || '' === $stored ) {
			return null;
		}

		if ( 0 === strpos( $stored, 'b64:' ) ) {
			$decoded = base64_decode( substr( $stored, 4 ), true );

			return false === $decoded ? null : $decoded;
		}

		if ( 0 !== strpos( $stored, self::VERSION . ':' ) ) {
			return null;
		}

		if ( ! self::openssl_available() ) {
			return null;
		}

		$raw = base64_decode( substr( $stored, strlen( self::VERSION ) + 1 ), true );
		if ( false === $raw ) {
			return null;
		}

		$iv_length = openssl_cipher_iv_length( self::CIPHER );
		if ( strlen( $raw ) <= $iv_length ) {
			return null;
		}

		$iv     = substr( $raw, 0, $iv_length );
		$cipher = substr( $raw, $iv_length );

		$plaintext = openssl_decrypt( $cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv );

		return false === $plaintext ? null : $plaintext;
	}

	private static function key(): string {
		// 32 raw bytes for AES-256, derived from the site-unique auth salt.
		return hash( 'sha256', wp_salt( 'auth' ), true );
	}

	private static function openssl_available(): bool {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
	}
}
