<?php
/**
 * Collecting scan payments through this store's own checkout.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Checkout;

use Oyster\Woo\Api\Api_Exception;
use Oyster\Woo\Api\Client;
use Oyster\Woo\Support\Connection;
use Oyster\Woo\Support\Scan_Pricing;
use WC_Order;
use WC_Product_Simple;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a scan payment into an ordinary WooCommerce order.
 *
 * Some vendors are set up to take scan payments themselves rather than through
 * Oyster's checkout. For those, the widget stops asking for money and hands this
 * store a reference instead: collect however you like, then tell Oyster you did.
 *
 * An order is the natural way for a WooCommerce store to charge for anything —
 * it brings every payment gateway the merchant already configured, plus
 * receipts, refunds, taxes and reporting, none of which we would want to
 * reimplement. So a scan payment is a one-line order against a hidden product,
 * and the shopper pays for it on the store's normal pay-for-order page.
 *
 * ## What actually completes the payment
 *
 * Not this store's say-so from the storefront. The scan stays blocked until the
 * confirmation below is sent from PHP, hooked to the order genuinely reaching a
 * paid state. Nothing the browser reports can admit a scan — which is why the
 * credential that confirms lives here and never reaches the page.
 *
 * ## Billing consequence
 *
 * A vendor collecting this way is invoiced by Oyster for every scan, including
 * the ones their shopper paid for — they are holding that money.
 */
final class Scan_Payment {

	/** Post meta holding Oyster's reference for the scan an order is paying for. */
	public const ORDER_META_REFERENCE = '_oyster_scan_reference';

	/** Guards against confirming the same order twice. */
	private const ORDER_META_CONFIRMED = '_oyster_scan_confirmed';

	/** Option holding the hidden product's id, so it is created only once. */
	private const PRODUCT_OPTION = 'oyster_woocommerce_scan_product_id';

	public function __construct(
		private Connection $connection,
		private Client $client,
		private Scan_Pricing $pricing
	) {}

	public function register(): void {
		// Every route to "the shopper has paid". WooCommerce fires these for
		// different gateways and configurations, so listening to one alone
		// silently misses whole categories of store.
		add_action( 'woocommerce_payment_complete', array( $this, 'on_paid' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'on_paid' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'on_paid' ) );

		// And the ways it ends without money. Reporting these matters as much as
		// success: without them the shopper watches a spinner until the widget
		// gives up, with no idea their payment failed.
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'on_not_paid' ) );
		add_action( 'woocommerce_order_status_failed', array( $this, 'on_not_paid' ) );
	}

	/**
	 * Create the order a shopper pays to run their scan, and return where to pay.
	 *
	 * @param array{reference: string, amount: float, currency: string, email: string, batch_id: string} $request
	 *
	 * @return array{checkout_url: string, order_id: int}|array{error: string}
	 */
	public function create_order( array $request ): array {
		if ( ! $this->connection->is_connected() ) {
			return array( 'error' => 'not_connected' );
		}

		if ( ! function_exists( 'wc_create_order' ) ) {
			return array( 'error' => 'woocommerce_unavailable' );
		}

		$product = $this->scan_product();
		if ( null === $product ) {
			return array( 'error' => 'product_unavailable' );
		}

		// One order per reference. A shopper who reloads the widget, or a retry
		// after a dropped response, must not end up with a second order for the
		// same scan — they would be charged twice for one thing.
		$existing = $this->find_order_for_reference( $request['reference'] );
		if ( $existing instanceof WC_Order ) {
			return array(
				'checkout_url' => $existing->get_checkout_payment_url(),
				'order_id'     => $existing->get_id(),
			);
		}

		// The amount reaches us through the shopper's browser, so it is raised to
		// at least the price this store has set. Without this a shopper could ask
		// for an order of a few cents and get a scan the store is billed in full
		// for. Never lowered: a session capturing more than a face scan costs
		// more than the base price, and the widget knows which this is.
		$amount = max( $request['amount'], $this->pricing->minimum_charge() ?? 0.0 );

		$order = wc_create_order();
		if ( ! $order instanceof WC_Order ) {
			return array( 'error' => 'order_failed' );
		}

		// Priced from the request rather than the product, because the product
		// is a placeholder — the amount belongs to this scan, not to a catalog
		// item the merchant maintains.
		$order->add_product( $product, 1, array( 'total' => $amount ) );

		if ( '' !== $request['email'] ) {
			$order->set_billing_email( $request['email'] );
		}

		$order->update_meta_data( self::ORDER_META_REFERENCE, $request['reference'] );
		// The scan this paid for, so a merchant looking at the order can tell
		// which one it was without leaving their admin.
		if ( '' !== $request['batch_id'] ) {
			$order->update_meta_data( '_oyster_scan_batch_id', $request['batch_id'] );
		}
		$order->set_created_via( 'oyster-scan' );
		$order->calculate_totals();
		$order->update_status( 'pending', __( 'Awaiting payment for an Oyster skin scan.', 'oyster-woocommerce' ) );

		return array(
			'checkout_url' => $order->get_checkout_payment_url(),
			'order_id'     => $order->get_id(),
		);
	}

	/**
	 * The order reached a paid state — tell Oyster, which unblocks the scan.
	 */
	public function on_paid( int $order_id ): void {
		$this->confirm( $order_id, 'success' );
	}

	/**
	 * The order was cancelled or the charge failed. Reported so the shopper is
	 * told promptly rather than waiting out the widget's timeout.
	 */
	public function on_not_paid( int $order_id ): void {
		$this->confirm( $order_id, 'failed' );
	}

	private function confirm( int $order_id, string $status ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$reference = (string) $order->get_meta( self::ORDER_META_REFERENCE );
		if ( '' === $reference ) {
			return; // An ordinary store order, nothing to do with a scan.
		}

		// WooCommerce fires several of the hooks above for one payment, so
		// without this a single order would confirm two or three times.
		if ( '' !== (string) $order->get_meta( self::ORDER_META_CONFIRMED ) ) {
			return;
		}

		$bearer = $this->connection->bearer();
		if ( null === $bearer ) {
			return;
		}

		try {
			$this->client->confirm_scan_payment(
				$bearer,
				$reference,
				$status,
				array(
					'external_reference' => (string) $order->get_id(),
					'amount'             => (float) $order->get_total(),
					'currency'           => (string) $order->get_currency(),
				)
			);
		} catch ( Api_Exception $e ) {
			// Left unmarked so a later status transition on the same order tries
			// again. The shopper has paid, so silently giving up would leave them
			// without the scan they bought.
			$order->add_order_note(
				sprintf(
					/* translators: %s: error detail */
					__( 'Could not tell Oyster this scan payment settled: %s', 'oyster-woocommerce' ),
					$e->user_message()
				)
			);
			$order->save();

			return;
		}

		$order->update_meta_data( self::ORDER_META_CONFIRMED, $status );
		$order->add_order_note(
			'success' === $status
				? __( 'Oyster was told this scan payment settled. The shopper\'s scan is unblocked.', 'oyster-woocommerce' )
				: __( 'Oyster was told this scan payment did not complete.', 'oyster-woocommerce' )
		);
		$order->save();
	}

	/**
	 * Find an order already raised for this reference.
	 */
	private function find_order_for_reference( string $reference ): ?WC_Order {
		if ( '' === $reference || ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'status'     => array( 'pending', 'on-hold' ),
				'meta_key'   => self::ORDER_META_REFERENCE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $reference, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return ! empty( $orders ) && $orders[0] instanceof WC_Order ? $orders[0] : null;
	}

	/**
	 * The hidden product scan orders are raised against, created on first use.
	 *
	 * Hidden from the catalog and from search: it exists so an order has
	 * something to reference, not so shoppers can buy scans off a shelf. Its
	 * price is never used — each order sets its own.
	 */
	private function scan_product(): ?WC_Product_Simple {
		$stored = (int) get_option( self::PRODUCT_OPTION, 0 );

		if ( $stored > 0 ) {
			$product = wc_get_product( $stored );
			if ( $product instanceof WC_Product_Simple ) {
				return $product;
			}
			// Deleted by the merchant, or left over from a restored database.
			// Fall through and make a new one rather than failing the payment.
		}

		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return null;
		}

		$product = new WC_Product_Simple();
		$product->set_name( __( 'Skin scan', 'oyster-woocommerce' ) );
		$product->set_status( 'private' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_virtual( true );
		$product->set_sold_individually( true );
		$product->set_price( 0 );
		$product->set_regular_price( 0 );
		$product->set_description(
			__( 'Used to charge for skin scans taken in the Oyster widget. Hidden from your storefront — deleting it is safe; it is recreated when next needed.', 'oyster-woocommerce' )
		);

		$id = $product->save();
		if ( ! $id ) {
			return null;
		}

		update_option( self::PRODUCT_OPTION, $id, false );

		return $product;
	}
}
