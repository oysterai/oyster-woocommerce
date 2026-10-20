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
use Oyster\Woo\Support\Scan_Confirmation_Health;
use Oyster\Woo\Support\Scan_Payment_Methods;
use Oyster\Woo\Support\Scan_Pricing;
use WC_Order;
use WC_Product_Simple;

defined( 'ABSPATH' ) || exit;

/**
 * Scan payments for vendors who collect them on their own store, as one-line orders against a hidden product.
 *
 * An order brings every gateway the merchant configured, plus receipts, refunds, taxes and reporting.
 * Only the PHP confirmation of a genuinely paid order unblocks the scan, never the browser, so the
 * confirming credential never reaches the page.
 * Oyster invoices such a vendor for every scan, shopper-paid ones included: they hold that money.
 */
final class Scan_Payment {

	/** Oyster's reference for the scan an order pays for. */
	public const ORDER_META_REFERENCE = '_oyster_scan_reference';

	/** Guards against confirming the same order twice. */
	private const ORDER_META_CONFIRMED = '_oyster_scan_confirmed';

	/** Hidden product's id, so it is created only once. */
	private const PRODUCT_OPTION = 'oyster_woocommerce_scan_product_id';

	/** Last outcome Oyster was successfully told about. */
	private const ORDER_META_REPORTED = '_oyster_scan_reported';

	private const ORDER_META_ATTEMPTS = '_oyster_scan_confirm_attempts';

	private const STATUS_SUCCESS = 'success';

	public const RETRY_HOOK = 'oyster_woo_retry_scan_confirmation';

	/** Spread over most of a day: outages and rate limits outlast attempts minutes apart. */
	private const RETRY_DELAYS = array( 5 * MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS, 2 * HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS );

	private const MAX_CONFIRM_ATTEMPTS = 5;

	public function __construct(
		private Connection $connection,
		private Client $client,
		private Scan_Pricing $pricing
	) {}

	public function register(): void {
		// Lets a merchant hide storefront greetings here without PHP; see mark_as_scan_payment().
		add_filter( 'body_class', array( $this, 'add_body_class' ) );

		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'restrict_payment_methods' ) );
		add_filter( 'woocommerce_get_checkout_order_received_url', array( $this, 'name_the_scan_on_return' ), 10, 2 );

		// WooCommerce fires a different one per gateway and configuration; one alone misses whole kinds of store.
		add_action( 'woocommerce_payment_complete', array( $this, 'on_paid' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'on_paid' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'on_paid' ) );

		// Without these the shopper watches a spinner until the widget gives up, unaware the payment failed.
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'on_not_paid' ) );
		add_action( 'woocommerce_order_status_failed', array( $this, 'on_not_paid' ) );

		// Most paid orders never transition again, so waiting for the next status change waits forever.
		add_action( self::RETRY_HOOK, array( $this, 'retry_confirmation' ), 10, 2 );
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

		// A widget reload or a retried request must not open a second order and charge twice for one scan.
		$existing = $this->find_order_for_reference( $request['reference'] );
		if ( $existing instanceof WC_Order ) {
			return array(
				'checkout_url' => self::mark_as_scan_payment( $existing->get_checkout_payment_url() ),
				'order_id'     => $existing->get_id(),
			);
		}

		// The amount comes via the browser: floored at the store's price so nobody pays cents for a scan
		// billed in full. Never lowered: a session capturing more than a face scan costs more, and the widget knows which.
		$amount = max( $request['amount'], $this->pricing->minimum_charge() ?? 0.0 );

		$order = wc_create_order();
		if ( ! $order instanceof WC_Order ) {
			return array( 'error' => 'order_failed' );
		}

		// The product is a placeholder; the amount belongs to this scan, not a catalog item.
		$order->add_product( $product, 1, array( 'total' => $amount ) );

		if ( '' !== $request['email'] ) {
			$order->set_billing_email( $request['email'] );
		}

		$order->update_meta_data( self::ORDER_META_REFERENCE, $request['reference'] );
		// Lets a merchant tell which scan this paid for without leaving their admin.
		if ( '' !== $request['batch_id'] ) {
			$order->update_meta_data( '_oyster_scan_batch_id', $request['batch_id'] );
		}
		$order->set_created_via( 'oyster-scan' );
		$order->calculate_totals();
		$order->update_status( 'pending', __( 'Awaiting payment for an Oyster skin scan.', 'oyster-woocommerce' ) );

		return array(
			'checkout_url' => self::mark_as_scan_payment( $order->get_checkout_payment_url() ),
			'order_id'     => $order->get_id(),
		);
	}

	/**
	 * Read from the order's reference, not the URL flag, which a gateway bouncing the shopper back can drop.
	 * Narrow on purpose: an ordinary pay-for-order page is the merchant's own sale.
	 */
	public static function is_paying_for_a_scan(): bool {
		if ( ! function_exists( 'is_checkout_pay_page' ) || ! is_checkout_pay_page() ) {
			return false;
		}

		global $wp;

		$order_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;

		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}

		$order = wc_get_order( $order_id );

		return $order instanceof WC_Order
			&& '' !== (string) $order->get_meta( self::ORDER_META_REFERENCE );
	}

	/**
	 * Scoped to the scan payment page: the same filter drives the store's own cart and checkout.
	 * WooCommerce validates the submitted method against this list before charging, so this is the
	 * restriction itself, not a way of hiding methods.
	 *
	 * @param mixed $gateways Available gateways, keyed by id.
	 * @return mixed
	 */
	public function restrict_payment_methods( $gateways ) {
		if ( ! is_array( $gateways ) || ! self::is_paying_for_a_scan() ) {
			return $gateways;
		}

		return Scan_Payment_Methods::restrict( $gateways, Scan_Payment_Methods::chosen() );
	}

	/**
	 * @param array<int, string> $classes
	 * @return array<int, string>
	 */
	public function add_body_class( array $classes ): array {
		// Read-only page state, as a theme reads a query flag to vary its markup.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ self::PAYMENT_FLAG ] ) ) {
			$classes[] = 'oyster-scan-payment';
		}

		return $classes;
	}

	/** Query flag identifying a checkout opened to pay for a scan. */
	public const PAYMENT_FLAG = 'oyster_scan_payment';

	/** Kept in sync with the widget SDK, which reopens the scan only for a payment this browser started. */
	public const RETURN_REFERENCE_PARAM = 'oyster_scan_payment_ref';

	/**
	 * On Android checkout opens in another tab or in-app view and the shopper stays there; without the
	 * reference this page's widget never knew they had paid, and the scan was never taken.
	 *
	 * @param string         $url   Order-received URL WooCommerce built.
	 * @param WC_Order|mixed $order Order being returned to.
	 */
	public function name_the_scan_on_return( $url, $order ): string {
		if ( ! $order instanceof WC_Order ) {
			return (string) $url;
		}

		$reference = (string) $order->get_meta( self::ORDER_META_REFERENCE );
		if ( '' === $reference ) {
			return (string) $url;
		}

		return add_query_arg( self::RETURN_REFERENCE_PARAM, rawurlencode( $reference ), (string) $url );
	}

	/**
	 * This checkout opens in a popup mid-scan, where storefront greetings (newsletter modal, chat bubble,
	 * cookie banner) must be dismissed before paying. The plugin keeps its own widget off; the rest is the
	 * merchant's, so the flag becomes a body class they can target without PHP:
	 *     body.oyster-scan-payment .newsletter-modal { display: none; }
	 */
	private static function mark_as_scan_payment( string $url ): string {
		return add_query_arg( self::PAYMENT_FLAG, '1', $url );
	}

	/** Telling Oyster is what unblocks the scan. */
	public function on_paid( int $order_id ): void {
		$this->confirm( $order_id, 'success' );
	}

	/** Reported so the shopper hears promptly instead of waiting out the widget's timeout. */
	public function on_not_paid( int $order_id ): void {
		$this->confirm( $order_id, 'failed' );
	}

	/**
	 * Re-runs a confirmation that failed for a reason worth waiting out.
	 *
	 * @param int    $order_id Order to report again.
	 * @param string $status   'success' or 'failed', as first reported.
	 */
	public function retry_confirmation( int $order_id, string $status ): void {
		$this->confirm( $order_id, $status );
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

		// Only success is final: reporting it twice would mint a second billable scan. Failed is not, since
		// WooCommerce lets a shopper pay a failed order; treating it as final left them charged with no scan.
		if ( self::STATUS_SUCCESS === (string) $order->get_meta( self::ORDER_META_CONFIRMED ) ) {
			return;
		}

		// WooCommerce fires several of the hooks above for one transition.
		if ( $status === (string) $order->get_meta( self::ORDER_META_REPORTED ) ) {
			return;
		}

		$bearer = $this->connection->bearer();
		if ( null === $bearer ) {
			$this->block_and_note(
				$order,
				__( 'Could not tell Oyster about this scan payment: this store is not connected to Oyster.', 'oyster-woocommerce' )
			);

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
			$this->handle_confirmation_failure( $order, $order_id, $status, $e );

			return;
		}

		Scan_Confirmation_Health::clear();

		$order->delete_meta_data( self::ORDER_META_ATTEMPTS );
		$order->update_meta_data( self::ORDER_META_REPORTED, $status );
		if ( self::STATUS_SUCCESS === $status ) {
			$order->update_meta_data( self::ORDER_META_CONFIRMED, $status );
		}
		$order->add_order_note(
			self::STATUS_SUCCESS === $status
				? __( 'Oyster was told this scan payment settled. The shopper\'s scan is unblocked.', 'oyster-woocommerce' )
				: __( 'Oyster was told this scan payment did not complete.', 'oyster-woocommerce' )
		);
		$order->save();
	}

	/**
	 * A refused credential lasts until the store reconnects, a timeout is usually gone next attempt: retrying
	 * the first forever hides it, giving up on the second loses a scan the shopper paid for.
	 */
	private function handle_confirmation_failure( WC_Order $order, int $order_id, string $status, Api_Exception $e ): void {
		$detail = sprintf(
			/* translators: %s: error detail */
			__( 'Could not tell Oyster about this scan payment: %s', 'oyster-woocommerce' ),
			$e->user_message()
		);

		if ( $e->denies_access() ) {
			$this->block_and_note(
				$order,
				$detail . ' ' . __( 'Reconnect the store on the Oyster screen to fix this.', 'oyster-woocommerce' )
			);

			return;
		}

		$attempts = (int) $order->get_meta( self::ORDER_META_ATTEMPTS ) + 1;
		$order->update_meta_data( self::ORDER_META_ATTEMPTS, $attempts );

		if ( $attempts >= self::MAX_CONFIRM_ATTEMPTS ) {
			$order->add_order_note(
				$detail . ' ' . sprintf(
					/* translators: %d: number of attempts */
					__( 'Gave up after %d attempts. Contact Oyster support with this order number.', 'oyster-woocommerce' ),
					$attempts
				)
			);
			$order->save();
			self::log( sprintf( 'Scan confirmation abandoned for order %d after %d attempts', $order_id, $attempts ) );

			return;
		}

		wp_schedule_single_event(
			time() + self::RETRY_DELAYS[ $attempts - 1 ],
			self::RETRY_HOOK,
			array( $order_id, $status )
		);

		$order->add_order_note(
			$detail . ' ' . sprintf(
				/* translators: %d: attempt number */
				__( 'Will try again (attempt %d).', 'oyster-woocommerce' ),
				$attempts
			)
		);
		$order->save();
		self::log( sprintf( 'Scan confirmation failed for order %d, retrying (attempt %d)', $order_id, $attempts ) );
	}

	private function block_and_note( WC_Order $order, string $message ): void {
		Scan_Confirmation_Health::block( $message );

		$order->add_order_note( $message );
		$order->save();

		self::log( sprintf( 'Scan confirmation blocked on order %d: %s', $order->get_id(), $message ) );
	}

	private static function log( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $message, array( 'source' => 'oyster-woocommerce' ) );
		}
	}

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
	 * Hidden so shoppers can't buy scans off a shelf; it exists only for orders to reference.
	 * Its price is never used: each order sets its own.
	 */
	private function scan_product(): ?WC_Product_Simple {
		$stored = (int) get_option( self::PRODUCT_OPTION, 0 );

		if ( $stored > 0 ) {
			$product = wc_get_product( $stored );
			if ( $product instanceof WC_Product_Simple ) {
				return $product;
			}
			// Deleted by the merchant or left from a restored database: recreate rather than fail the payment.
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
