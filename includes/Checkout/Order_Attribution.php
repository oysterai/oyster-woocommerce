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
use Oyster\Woo\Sync\Sync_State;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Two hand-offs:
 *
 *   1. Cart -> order: Cart_Filler stamps `oyster_attribution` into the cart item
 *      data, which WooCommerce round-trips through the session on its own. At
 *      checkout the first cart item carrying it wins, and the scan cookie stands
 *      in when no line carries one.
 *   2. Order -> Oyster: a paid order queues a report, guarded by
 *      `_oyster_order_recorded` so the several hooks one transition fires do not
 *      report it twice. An order holding nothing this store has synced cannot be
 *      attributed by any route, so it is not sent.
 */
final class Order_Attribution {

	/**
	 * Order meta this plugin adds. Public so Compliance\Gdpr can reference them
	 * rather than repeat the literals.
	 */
	public const META_BATCH_ID = '_oyster_batch_id';

	public const META_ROUTINE_ID = '_oyster_routine_id';

	public const META_ATTRIBUTION_ID = '_oyster_widget_attribution_id';

	public const META_BATCH_FROM_COOKIE = '_oyster_batch_from_cookie';

	/** Named in PHP, not JavaScript: the checkout has to read what the storefront wrote. */
	public const COOKIE_SCAN_BATCH = 'oyster_scan_batch';

	/** Matches the window Oyster uses when matching a shopper to a past scan. */
	public const COOKIE_DAYS = 90;

	public const HOOK_REPORT_ORDER = 'oyster_woo_report_order';

	private const META_RECORDED = '_oyster_order_recorded';

	public function __construct(
		private Connection $connection,
		private Client $client
	) {}

	public function register(): void {
		// Not `woocommerce_checkout_create_order`: that fires only in the classic
		// shortcode checkout, never in the Store API behind the block checkout.
		// Both run create_order_line_items().
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'stamp_order_meta' ), 10, 4 );

		// payment_complete alone misses every offline gateway: cash on delivery,
		// bank transfer and cheque go straight to processing/on-hold, as does an
		// admin marking an order paid by hand.
		add_action( 'woocommerce_payment_complete', array( $this, 'on_paid' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'on_paid' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'on_paid' ) );

		add_action( self::HOOK_REPORT_ORDER, array( $this, 'report_paid_order' ) );
	}

	/**
	 * The Store API rebuilds line items whenever the cart hash changes, so this
	 * runs repeatedly over a draft order and every branch below has to be repeatable.
	 *
	 * Order meta, not item meta: Compliance\Gdpr finds and erases these through
	 * `$order->get_meta()` and a `META_BATCH_ID EXISTS` query, both of which go
	 * blind if the stamp moves onto the item.
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

		$attribution = is_array( $values ) ? ( $values['oyster_attribution'] ?? null ) : null;
		$batch_id    = is_array( $attribution ) ? (string) ( $attribution['batch_id'] ?? '' ) : '';

		if ( '' !== $batch_id ) {
			$this->stamp_from_cart( $order, $batch_id, is_array( $attribution ) ? $attribution : array() );
			return;
		}

		$this->stamp_from_cookie( $order );
	}

	/**
	 * A cart stamp outranks a cookie stamp already on the order, whichever line it
	 * arrives on. Among cart stamps the first still wins.
	 *
	 * @param array<string, mixed> $attribution
	 */
	private function stamp_from_cart( WC_Order $order, string $batch_id, array $attribution ): void {
		$already_stamped  = (string) $order->get_meta( self::META_BATCH_ID );
		$stamped_by_guess = 'yes' === $order->get_meta( self::META_BATCH_FROM_COOKIE );

		if ( '' !== $already_stamped && ! $stamped_by_guess ) {
			return;
		}

		$order->delete_meta_data( self::META_BATCH_FROM_COOKIE );
		$order->update_meta_data( self::META_BATCH_ID, $batch_id );

		if ( ! empty( $attribution['routine_id'] ) ) {
			$order->update_meta_data( self::META_ROUTINE_ID, (string) $attribution['routine_id'] );
		}
		if ( ! empty( $attribution['widget_attribution_id'] ) ) {
			$order->update_meta_data( self::META_ATTRIBUTION_ID, (string) $attribution['widget_attribution_id'] );
		}
	}

	/**
	 * The marker rides along because a browser having scanned is not proof this
	 * order acted on it: Oyster credits a cookie-borne batch only when the order
	 * also contains something that scan recommended.
	 */
	private function stamp_from_cookie( WC_Order $order ): void {
		if ( $order->get_meta( self::META_BATCH_ID ) ) {
			return;
		}

		$batch_id = $this->cookie_batch_id();
		if ( null === $batch_id ) {
			return;
		}

		$order->update_meta_data( self::META_BATCH_ID, $batch_id );
		$order->update_meta_data( self::META_BATCH_FROM_COOKIE, 'yes' );
	}

	/**
	 * Read as an untrusted hint, never as an identity: whoever holds the cookie can
	 * edit it, and a batch id is an opaque token that says nothing to this store
	 * about the person behind it.
	 */
	private function cookie_batch_id(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a first-party cookie, not acting on a form submission.
		$raw = $_COOKIE[ self::COOKIE_SCAN_BATCH ] ?? null;
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$batch_id = sanitize_text_field( wp_unslash( $raw ) );

		return preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $batch_id ) ? $batch_id : null;
	}

	/** Queued rather than sent here: this runs inside the shopper's checkout request. */
	public function on_paid( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( 'yes' === $order->get_meta( self::META_RECORDED ) ) {
			return;
		}

		if ( ! $this->is_reportable( $order ) ) {
			return;
		}

		$this->enqueue_report( $order_id );
	}

	/**
	 * A stamped order names its scan. An unstamped one is only recognisable through
	 * a product Oyster recommended, so an order of nothing this store has synced
	 * stays here.
	 */
	private function is_reportable( WC_Order $order ): bool {
		if ( $order->get_meta( self::META_BATCH_ID ) ) {
			return true;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			if ( Sync_State::is_synced( (int) $item->get_product_id() ) ) {
				return true;
			}
		}

		return false;
	}

	/** Runs from the queue, so it re-reads the order and re-checks the guards. */
	public function report_paid_order( int $order_id ): void {
		$bearer = $this->connection->bearer();
		if ( ! $bearer ) {
			return;
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( 'yes' === $order->get_meta( self::META_RECORDED ) ) {
			return;
		}

		if ( ! $this->is_reportable( $order ) ) {
			return;
		}

		try {
			$this->client->record_order( $bearer, $this->build_payload( $order ) );
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
	 * One transition fires several of the hooks above, and the recorded flag is only
	 * set once the report succeeds, after all of them have run.
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

	/** @return array<string, mixed> */
	private function build_payload( WC_Order $order ): array {
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

		$batch_id       = (string) $order->get_meta( self::META_BATCH_ID );
		$routine_id     = $order->get_meta( self::META_ROUTINE_ID );
		$attribution_id = $order->get_meta( self::META_ATTRIBUTION_ID );
		$placed_at      = $order->get_date_created();

		// Absent means the batch came from the cart, so a false would say nothing.
		$from_cookie = 'yes' === $order->get_meta( self::META_BATCH_FROM_COOKIE ) ? true : null;

		return array_filter(
			array(
				'woocommerce_order_id'     => (string) $order->get_id(),
				'woocommerce_order_number' => $order->get_order_number(),
				'oyster_batch_id'          => '' !== $batch_id ? $batch_id : null,
				'batch_from_cookie'        => $from_cookie,
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
