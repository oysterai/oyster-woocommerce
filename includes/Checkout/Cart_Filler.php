<?php
/**
 * Resolves Oyster products into the visitor's WooCommerce cart.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Checkout;

use Oyster\Woo\Api\Api_Exception;
use Oyster\Woo\Api\Client;
use Oyster\Woo\Support\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * The one place Oyster product ids become WooCommerce cart lines. Both routes
 * into checkout share it: the widget's own handoff (`Cart_Controller`) and the
 * scan-result email's CTA (`Email_Handoff`), so a cart built from an email is
 * indistinguishable from one built in the widget — same in-stock filtering,
 * same attribution stamp, same "nothing addable" behaviour.
 *
 * Resolution runs server-side with the vendor bearer on purpose: the browser
 * never sees the token, and the ids it can influence only ever resolve within
 * that vendor's own synced catalog.
 */
final class Cart_Filler {

	public function __construct(
		private Connection $connection,
		private Client $client
	) {}

	/**
	 * Add the given Oyster products to the requesting visitor's own cart.
	 *
	 * @param array<int, array{product_id:int, quantity:int}> $items       Sanitized Oyster product ids.
	 * @param array<string, string>                          $attribution Scan attribution stamped on each line.
	 * @param int[]                                          $routine_offer_product_ids Oyster ids whose lines earn the routine offer.
	 * @return array{added:int, skipped:int}|array{error:string} Error is one of
	 *         not_connected, cart_unavailable, resolve_failed.
	 */
	public function fill( array $items, array $attribution, array $routine_offer_product_ids = array() ): array {
		if ( ! $this->connection->is_connected() ) {
			return array( 'error' => 'not_connected' );
		}

		// A REST request never fires `wp_loaded`, where WooCommerce lazily boots
		// the visitor's cart and session, so WC()->cart is null without this.
		// A no-op on a front-end request.
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return array( 'error' => 'cart_unavailable' );
		}

		$bearer = $this->connection->bearer();
		if ( ! $bearer ) {
			return array( 'error' => 'not_connected' );
		}

		try {
			$resolved = $this->client->resolve_variants( $bearer, array_column( $items, 'product_id' ) );
		} catch ( Api_Exception $e ) {
			$this->log( 'resolve_variants failed: ' . $e->user_message() );
			return array( 'error' => 'resolve_failed' );
		}

		$mapping = is_array( $resolved['data'] ?? null ) ? $resolved['data'] : array();

		$added   = 0;
		$skipped = 0;

		foreach ( $items as $item ) {
			$entry = $mapping[ (string) $item['product_id'] ] ?? null;
			if ( ! is_array( $entry ) || empty( $entry['woocommerce_product_id'] ) ) {
				++$skipped;
				continue;
			}

			$product_id   = absint( $entry['woocommerce_product_id'] );
			$variation_id = ! empty( $entry['woocommerce_variation_id'] ) ? absint( $entry['woocommerce_variation_id'] ) : 0;

			$product = wc_get_product( $variation_id ?: $product_id );
			if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				++$skipped;
				continue;
			}

			$cart_item_data = array( 'oyster_attribution' => $attribution );
			if ( in_array( $item['product_id'], $routine_offer_product_ids, true ) ) {
				$cart_item_data[ Routine_Offer::CART_ITEM_FLAG ] = true;
			}

			$cart_item_key = WC()->cart->add_to_cart( $product_id, $item['quantity'], $variation_id, array(), $cart_item_data );

			if ( $cart_item_key ) {
				++$added;
			} else {
				++$skipped;
			}
		}

		return array(
			'added'   => $added,
			'skipped' => $skipped,
		);
	}

	/**
	 * Normalize a caller-supplied item list to `{product_id, quantity}` pairs,
	 * dropping anything without a usable Oyster product id.
	 *
	 * @param mixed $raw
	 * @return array<int, array{product_id:int, quantity:int}>
	 */
	public static function sanitize_items( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$items = array();
		foreach ( $raw as $entry ) {
			$product_id = is_array( $entry ) && isset( $entry['product_id'] ) ? absint( $entry['product_id'] ) : 0;
			if ( $product_id <= 0 ) {
				continue;
			}

			$quantity = is_array( $entry ) && isset( $entry['quantity'] ) ? max( 1, absint( $entry['quantity'] ) ) : 1;
			$items[]  = array(
				'product_id' => $product_id,
				'quantity'   => $quantity,
			);
		}

		return $items;
	}

	private function log( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $message, array( 'source' => 'oyster-woocommerce' ) );
		}
	}
}
