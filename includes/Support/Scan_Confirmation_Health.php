<?php
/**
 * Whether this store is still able to tell Oyster that a scan payment settled.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * A scan payment the store cannot report is money a shopper handed over for a
 * scan they will never receive, and nothing about the order itself looks wrong:
 * it is paid, it is complete, and the failure lives entirely in a call nobody
 * watched. A store can stay in that state indefinitely.
 *
 * So the one condition that does not recover on its own — a credential Oyster
 * refuses, or none at all — is recorded here and shown across wp-admin until a
 * confirmation succeeds again. Transient failures deliberately do not raise it:
 * they retry, and a banner that cries wolf during a blip is one a merchant
 * learns to ignore before the blip that matters.
 */
final class Scan_Confirmation_Health {

	private const OPTION = 'oyster_woo_scan_confirmation_blocked';

	/**
	 * @param string $reason Merchant-facing detail, already translated.
	 */
	public static function block( string $reason ): void {
		update_option(
			self::OPTION,
			array(
				'reason' => $reason,
				'since'  => time(),
			),
			false
		);
	}

	public static function clear(): void {
		delete_option( self::OPTION );
	}

	public static function is_blocked(): bool {
		return is_array( get_option( self::OPTION ) );
	}

	public static function reason(): string {
		$stored = get_option( self::OPTION );

		return is_array( $stored ) ? (string) ( $stored['reason'] ?? '' ) : '';
	}

	/**
	 * When the store first failed to report, as a WordPress-formatted date, or
	 * an empty string when it is not blocked.
	 */
	public static function blocked_since(): string {
		$stored = get_option( self::OPTION );

		if ( ! is_array( $stored ) || empty( $stored['since'] ) ) {
			return '';
		}

		return wp_date( (string) get_option( 'date_format' ), (int) $stored['since'] ) ?: '';
	}
}
