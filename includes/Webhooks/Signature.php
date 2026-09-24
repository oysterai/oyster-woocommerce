<?php
/**
 * Verifies the signature on an incoming Oyster delivery.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Deliberately free of WordPress: this is the one part of the receiver that
 * decides whether a request is genuine, and it is worth being able to test it
 * against a table of adversarial inputs without standing up WordPress.
 *
 * Header format is `t=<unix>,v1=<hex>`, signing `{t}.{raw body}` with HMAC-SHA256.
 */
final class Signature {

	/**
	 * How far out a delivery's timestamp may be, matching what Oyster publishes.
	 * Without this a captured request stays replayable forever.
	 */
	public const TOLERANCE_SECONDS = 300;

	/**
	 * @param string   $header Value of the Oyster-Signature header.
	 * @param string   $body   Raw request body, exactly as received.
	 * @param string   $secret This store's signing secret.
	 * @param int|null $now    Injectable for tests.
	 */
	public static function is_valid( string $header, string $body, string $secret, ?int $now = null ): bool {
		if ( '' === $secret ) {
			return false;
		}

		$parsed = self::parse( $header );
		if ( null === $parsed ) {
			return false;
		}

		[ $timestamp, $provided ] = $parsed;

		$now ??= time();

		if ( abs( $now - $timestamp ) > self::TOLERANCE_SECONDS ) {
			return false;
		}

		return hash_equals( hash_hmac( 'sha256', $timestamp . '.' . $body, $secret ), $provided );
	}

	/**
	 * @return array{0:int,1:string}|null
	 */
	private static function parse( string $header ): ?array {
		$parts = array();

		foreach ( explode( ',', $header ) as $segment ) {
			$pair = explode( '=', trim( $segment ), 2 );
			if ( 2 === count( $pair ) ) {
				$parts[ trim( $pair[0] ) ] = trim( $pair[1] );
			}
		}

		$timestamp = isset( $parts['t'] ) ? $parts['t'] : '';
		$provided  = (string) ( $parts['v1'] ?? '' );

		// Rejected rather than cast: (int) '' is 0 and (int) 'abc' is 0, and a
		// zero timestamp would otherwise be compared against the clock instead
		// of refused outright.
		if ( '' === $provided || '' === $timestamp || ! ctype_digit( $timestamp ) ) {
			return null;
		}

		return array( (int) $timestamp, $provided );
	}
}
