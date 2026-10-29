<?php
/**
 * The businesses an Oyster login can connect this store to.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * One Oyster login can run several businesses, each with its own catalog,
 * billing and widget. The login response lists them; this keeps the list in a
 * shape the connection screen can store and show, and decides whether a
 * submitted choice is one the login actually offered.
 */
final class Business_Choice {

	/**
	 * @param array<string, mixed> $login_response What logging in returned.
	 *
	 * @return list<array{vendor_id: int, business_name: string, is_current: bool}>
	 */
	public static function from_login( array $login_response ): array {
		$listed = is_array( $login_response['businesses'] ?? null ) ? $login_response['businesses'] : array();
		$choices = array();

		foreach ( $listed as $business ) {
			$vendor_id = is_array( $business ) ? (int) ( $business['vendor_id'] ?? 0 ) : 0;

			if ( $vendor_id <= 0 ) {
				continue;
			}

			$choices[] = array(
				'vendor_id'     => $vendor_id,
				'business_name' => (string) ( $business['business_name'] ?? '' ),
				'is_current'    => (bool) ( $business['is_current'] ?? false ),
			);
		}

		return $choices;
	}

	/** Only a login with more than one business has anything to choose. */
	public static function needs_choice( array $choices ): bool {
		return count( $choices ) > 1;
	}

	/**
	 * The offered business with this id, or null. A posted id is the browser's
	 * word, so it only counts when the login listed it.
	 *
	 * @param list<array{vendor_id: int, business_name: string, is_current: bool}> $choices
	 *
	 * @return array{vendor_id: int, business_name: string, is_current: bool}|null
	 */
	public static function find( array $choices, int $vendor_id ): ?array {
		foreach ( $choices as $choice ) {
			if ( $choice['vendor_id'] === $vendor_id ) {
				return $choice;
			}
		}

		return null;
	}
}
