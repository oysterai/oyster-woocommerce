<?php
/**
 * Carries scan attribution from cart to order, and reports paid orders.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Checkout;

use Oyster\Woo\Api\Api_Exception;
use Oyster\Woo\Api\Client;
use Oyster\Woo\Support\Connection;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Two hand-offs:
 *
 *   1. Cart -> order: Cart_Controller stamps `oyster_attribution` directly
 *      into the cart item data it passes to `WC()->cart->add_to_cart()`.
 *      WooCommerce round-trips plain array cart-item data through the session
 *      automatically, so no `woocommerce_get_cart_item_from_session` filter
 *      is needed here. At checkout, `woocommerce_checkout_create_order` reads
 *      the first cart item carrying attribution ("first item wins" for a
 *      cart that mixes recommended and unrelated products) and stamps it
 *      onto order meta.
 *   2. Order -> Oyster: `woocommerce_payment_complete` reports the paid
 *      order for tracking-only attribution. Guarded by an `_oyster_order_
 *      recorded` meta flag so a gateway that fires payment_complete more than
 *      once for the same order doesn't call out twice (the backend is also
 *      idempotent on woocommerce_order_id, so this is a belt-and-suspenders
 *      guard against a redundant network call, not a correctness dependency).
 */
final class Order_Attribution {

	/**
	 * Public: these three identify the scan a purchase is attributed to and
	 * are the only personal-data-adjacent fields this plugin adds to an
	 * order, so Compliance\Gdpr references them directly rather than
	 * duplicating the string literals.
	 */
	public const META_BATCH_ID = '_oyster_batch_id';

	public const META_ROUTINE_ID = '_oyster_routine_id';

	public const META_ATTRIBUTION_ID = '_oyster_widget_attribution_id';

	private const META_RECORDED = '_oyster_order_recorded';

	public function __construct(
		private Connection $connection,
		private Client $client
	) {}

	public function register(): void {
		add_action( 'woocommerce_checkout_create_order', array( $this, 'stamp_order_meta' ), 10, 1 );
		add_action( 'woocommerce_payment_complete', array( $this, 'report_paid_order' ) );
	}

	/**
	 * Fires just before WooCommerce persists the new order — reads whichever
	 * cart item (if any) carries `oyster_attribution` and copies it onto the
	 * order as its own meta, so it survives independently of the cart.
	 */
	public function stamp_order_meta( WC_Order $order ): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$attribution = $cart_item['oyster_attribution'] ?? null;
			if ( ! is_array( $attribution ) || empty( $attribution['batch_id'] ) ) {
				continue;
			}

			$order->update_meta_data( self::META_BATCH_ID, (string) $attribution['batch_id'] );
			if ( ! empty( $attribution['routine_id'] ) ) {
				$order->update_meta_data( self::META_ROUTINE_ID, (string) $attribution['routine_id'] );
			}
			if ( ! empty( $attribution['widget_attribution_id'] ) ) {
				$order->update_meta_data( self::META_ATTRIBUTION_ID, (string) $attribution['widget_attribution_id'] );
			}

			// First attributed cart item wins for a cart with mixed/unrelated
			// items.
			return;
		}
	}

	/**
	 * Fires when payment is confirmed (regardless of gateway). Reports the
	 * order to Oyster for tracking-only attribution. Silently no-ops for
	 * an order with no Oyster attribution — the overwhelming majority of a
	 * store's orders won't have any.
	 */
	public function report_paid_order( int $order_id ): void {
		$bearer = $this->connection->bearer();
		if ( ! $bearer ) {
			return;
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$batch_id = $order->get_meta( self::META_BATCH_ID );
		if ( ! $batch_id ) {
			return;
		}

		if ( 'yes' === $order->get_meta( self::META_RECORDED ) ) {
			return;
		}

		try {
			$this->client->record_order( $bearer, $this->build_payload( $order, (string) $batch_id ) );
		} catch ( Api_Exception $e ) {
			// Left un-flagged so a future payment_complete firing (or a manual
			// admin retry, once one exists) can still succeed.
			$this->log( 'record_order failed: ' . $e->user_message() );
			return;
		}

		$order->update_meta_data( self::META_RECORDED, 'yes' );
		$order->save();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_payload( WC_Order $order, string $batch_id ): array {
		$line_items = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$quantity = max( 1, (int) $item->get_quantity() );
			$product  = $item->get_product();

			$line_items[] = array(
				'woocommerce_product_id'   => (string) $item->get_product_id(),
				'woocommerce_variation_id' => $item->get_variation_id() ? (string) $item->get_variation_id() : null,
				'sku'                      => $product ? ( $product->get_sku() ?: null ) : null,
				'quantity'                 => $quantity,
				'price'                    => round( (float) $item->get_total() / $quantity, 2 ),
			);
		}

		$routine_id = $order->get_meta( self::META_ROUTINE_ID );
		$attribution_id = $order->get_meta( self::META_ATTRIBUTION_ID );
		$placed_at = $order->get_date_created();

		return array_filter(
			array(
				'woocommerce_order_id'     => (string) $order->get_id(),
				'woocommerce_order_number' => $order->get_order_number(),
				'oyster_batch_id'          => $batch_id,
				'oyster_routine_id'        => $routine_id ?: null,
				'widget_attribution_id'    => $attribution_id ?: null,
				'currency'                 => $order->get_currency(),
				'total'                    => (float) $order->get_total(),
				'customer_email'           => $order->get_billing_email() ?: null,
				'placed_at'                => $placed_at ? $placed_at->format( DATE_ATOM ) : null,
				'store_url'                => home_url(),
				'line_items'               => $line_items,
			),
			static fn( $value ) => null !== $value
		);
	}

	private function log( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $message, array( 'source' => 'oyster-woocommerce' ) );
		}
	}
}
