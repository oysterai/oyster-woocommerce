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
use Oyster\Woo\Sync\Catalog_Sync;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Two hand-offs:
 *
 *   1. Cart -> order: Cart_Filler stamps `oyster_attribution` into the cart item
 *      data it passes to `WC()->cart->add_to_cart()`. WooCommerce round-trips
 *      plain array cart-item data through the session automatically, so no
 *      `woocommerce_get_cart_item_from_session` filter is needed here. At
 *      checkout, `woocommerce_checkout_create_order_line_item` reads the first
 *      cart item carrying attribution ("first item wins" for a cart that mixes
 *      recommended and unrelated products) and stamps it onto order meta.
 *   2. Order -> Oyster: the order reaching a paid state queues a job that
 *      reports it for tracking-only attribution. Guarded by an
 *      `_oyster_order_recorded` meta flag so the several hooks one transition
 *      fires don't report the same order twice.
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

	public const HOOK_REPORT_ORDER = 'oyster_woo_report_order';

	private const META_RECORDED = '_oyster_order_recorded';

	public function __construct(
		private Connection $connection,
		private Client $client
	) {}

	public function register(): void {
		// Per line item, not `woocommerce_checkout_create_order`: that hook
		// fires only in WC_Checkout::create_order(), the classic shortcode
		// checkout. The Store API behind the block checkout builds its order
		// directly and never fires it, so a block-checkout store stamped
		// nothing and every order went unattributed. Both checkouts do run
		// create_order_line_items(), which is where this fires.
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'stamp_order_meta' ), 10, 4 );

		// Every route to "the shopper has paid", matching Scan_Payment.
		// payment_complete alone misses every offline gateway: cash on
		// delivery, bank transfer and cheque move the order straight to
		// processing/on-hold without ever firing it, as does an admin marking
		// an order paid by hand.
		add_action( 'woocommerce_payment_complete', array( $this, 'on_paid' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'on_paid' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'on_paid' ) );

		add_action( self::HOOK_REPORT_ORDER, array( $this, 'report_paid_order' ) );
	}

	/**
	 * Fires once per line item as WooCommerce builds the order from the cart —
	 * copies whichever cart item carries `oyster_attribution` onto the order as
	 * its own meta, so it survives independently of the cart.
	 *
	 * The Store API rebuilds line items whenever the cart hash changes, so this
	 * runs repeatedly over a draft order's life. Writing only when the order has
	 * no batch id yet keeps "first attributed item wins" and stops a later item
	 * from overwriting the first.
	 *
	 * Deliberately order meta, not item meta: Compliance\Gdpr finds and erases
	 * these through `$order->get_meta()` and a `META_BATCH_ID EXISTS` order
	 * query, both of which go blind if the stamp moves onto the item.
	 *
	 * @param mixed                $item          Order line item being built.
	 * @param string               $cart_item_key Cart item key it came from.
	 * @param array<string, mixed> $values        The cart item.
	 * @param mixed                $order         Order being built.
	 */
	public function stamp_order_meta( $item, $cart_item_key, $values, $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( $order->get_meta( self::META_BATCH_ID ) ) {
			return;
		}

		$attribution = is_array( $values ) ? ( $values['oyster_attribution'] ?? null ) : null;
		if ( ! is_array( $attribution ) || empty( $attribution['batch_id'] ) ) {
			return;
		}

		$order->update_meta_data( self::META_BATCH_ID, (string) $attribution['batch_id'] );
		if ( ! empty( $attribution['routine_id'] ) ) {
			$order->update_meta_data( self::META_ROUTINE_ID, (string) $attribution['routine_id'] );
		}
		if ( ! empty( $attribution['widget_attribution_id'] ) ) {
			$order->update_meta_data( self::META_ATTRIBUTION_ID, (string) $attribution['widget_attribution_id'] );
		}
	}

	/**
	 * The order reached a paid state. Queues the report rather than making it
	 * here: this runs inside the shopper's checkout request, and the API call
	 * blocks for up to 15 seconds.
	 */
	public function on_paid( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( ! $order->get_meta( self::META_BATCH_ID ) ) {
			return;
		}

		if ( 'yes' === $order->get_meta( self::META_RECORDED ) ) {
			return;
		}

		$this->enqueue_report( $order_id );
	}

	/**
	 * Reports the order to Oyster for tracking-only attribution. Runs from the
	 * queue, so it re-reads the order and re-checks the guards rather than
	 * trusting what was true when it was scheduled.
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
			// Left un-flagged so a later paid-status transition, or an Action
			// Scheduler retry, can still succeed.
			$this->log( 'record_order failed: ' . $e->user_message() );
			return;
		}

		$order->update_meta_data( self::META_RECORDED, 'yes' );
		$order->save();
	}

	/**
	 * One transition fires several of the hooks above, so check for an already
	 * queued job as well as the recorded flag — the flag is only set once the
	 * report succeeds, which is after all of them have run.
	 */
	private function enqueue_report( int $order_id ): void {
		$args = array( 'order_id' => $order_id );

		// Action Scheduler ships with WooCommerce, so this is defensive only.
		// Reporting inline beats dropping the order on the floor.
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			$this->report_paid_order( $order_id );
			return;
		}

		if ( as_next_scheduled_action( self::HOOK_REPORT_ORDER, $args, Catalog_Sync::ACTION_GROUP ) ) {
			return;
		}

		as_enqueue_async_action( self::HOOK_REPORT_ORDER, $args, Catalog_Sync::ACTION_GROUP );
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
