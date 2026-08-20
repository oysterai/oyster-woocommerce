<?php
/**
 * Which of the store's payment methods may take a scan payment.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * A scan payment is an ordinary WooCommerce order, so the shopper is offered
 * every payment method the checkout has enabled. Not all of them suit it.
 *
 * An offline method — cash on delivery, cheque, direct bank transfer — moves the
 * order into a paid state without money changing hands, and a scan payment
 * reaching a paid state is what releases the shopper's scan. That is precisely
 * what makes those methods useful while a merchant is testing the integration,
 * and precisely what makes them expensive on a live storefront: the scan is
 * handed over, the merchant is billed for it, and no payment was ever taken.
 *
 * Which is why this is a choice rather than a ban. A merchant names the methods
 * a scan may be paid with; naming none keeps all of them, as every store had
 * before this setting existed. Nothing here touches the store's own checkout —
 * only the page a shopper pays for a scan on.
 */
final class Scan_Payment_Methods {

	public const OPTION_KEY = 'oyster_woocommerce_scan_payment_methods';

	/**
	 * The gateway ids the merchant chose. Empty means every method.
	 *
	 * @return array<int, string>
	 */
	public static function chosen(): array {
		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$ids = array_map( static fn( $id ): string => (string) $id, $stored );

		return array_values( array_filter( $ids, static fn( string $id ): bool => '' !== $id ) );
	}

	/**
	 * Narrow the available gateways to the chosen ones.
	 *
	 * An empty choice returns every gateway untouched. A choice that matches
	 * none of them returns nothing, and the shopper is told there is no way to
	 * pay: a merchant who disables the one method they had picked has left the
	 * store unable to take scan payments, and quietly offering the methods they
	 * ruled out would be a worse answer than saying so. The admin screen warns
	 * about that state before a shopper ever meets it.
	 *
	 * @param array<string, mixed> $gateways Available gateways, keyed by id.
	 * @param array<int, string>   $chosen   Gateway ids the merchant chose.
	 * @return array<string, mixed>
	 */
	public static function restrict( array $gateways, array $chosen ): array {
		if ( empty( $chosen ) ) {
			return $gateways;
		}

		return array_intersect_key( $gateways, array_flip( $chosen ) );
	}

	/**
	 * Keep only ids the checkout currently offers. Anything else was posted from
	 * a form that no longer describes this store.
	 *
	 * @param mixed             $input   Raw submission.
	 * @param array<int, string> $enabled Ids currently enabled in the checkout.
	 * @return array<int, string>
	 */
	public static function sanitize( $input, array $enabled ): array {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$ids = array_map( static fn( $id ): string => sanitize_text_field( (string) $id ), $input );

		return array_values( array_unique( array_intersect( $ids, $enabled ) ) );
	}

	/**
	 * The store's enabled payment methods as id => the title a shopper sees.
	 *
	 * @return array<string, string>
	 */
	public static function enabled_gateways(): array {
		if ( ! function_exists( 'WC' ) || null === WC() || null === WC()->payment_gateways() ) {
			return array();
		}

		$enabled = array();

		foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
			if ( 'yes' !== $gateway->enabled ) {
				continue;
			}

			$title = trim( (string) $gateway->get_title() );

			$enabled[ (string) $id ] = '' !== $title ? $title : (string) $id;
		}

		return $enabled;
	}
}
