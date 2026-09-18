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
 *      data it passes to `WC()->cart->add_to_cart()`. WooCommerce round-trips
 *      plain array cart-item data through the session automatically, so no
 *      `woocommerce_get_cart_item_from_session` filter is needed here. At
 *      checkout, `woocommerce_checkout_create_order_line_item` reads the first
 *      cart item carrying attribution ("first item wins" for a cart that mixes
 *      recommended and unrelated products) and stamps it onto order meta.
 *
 *      A shopper who scanned and then shopped the store normally never touches
 *      that path, so the scan cookie the loader writes stands in when no cart
 *      line carries a stamp. It is the weaker claim of the two and always
 *      yields to a real one, whichever line that arrives on.
 *   2. Order -> Oyster: the order reaching a paid state queues a job that
 *      reports it for tracking-only attribution. Guarded by an
 *      `_oyster_order_recorded` meta flag so the several hooks one transition
 *      fires don't report the same order twice.
 *
 *      Every paid order containing a product this store has synced is reported,
 *      not only the stamped ones: Oyster can attribute a purchase to a scan the
 *      shopper never checked out from, and only it can tell whether a given
 *      order is one of those. An order of nothing we have ever synced cannot be
 *      attributed by any route, so it is not sent.
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

	/**
	 * Records that META_BATCH_ID was read off the scan cookie rather than
	 * carried by the cart. Personal-data-adjacent in the same way the three
	 * above are, so Compliance\Gdpr handles it alongside them.
	 */
	public const META_BATCH_FROM_COOKIE = '_oyster_batch_from_cookie';

	/**
	 * First-party cookie the storefront loader writes when a scan finishes, so
	 * a shopper who comes back to buy through the store's own pages can still
	 * be connected to it. Named here rather than in JavaScript because the
	 * checkout has to read what the storefront wrote.
	 */
	public const COOKIE_SCAN_BATCH = 'oyster_scan_batch';

	/**
	 * How long that cookie lives. Matches the window Oyster uses when matching
	 * a shopper to a past scan, so the two halves of the same claim do not
	 * disagree about how old a scan may be.
	 */
	public const COOKIE_DAYS = 90;

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
	 * its own meta, so it survives independently of the cart, and falls back to
	 * the scan cookie when no line carries one.
	 *
	 * The Store API rebuilds line items whenever the cart hash changes, so this
	 * runs repeatedly over a draft order's life. Every branch below is therefore
	 * written to be repeatable: "first attributed item wins" survives, and a
	 * later item cannot rewrite the scan the purchase is credited to.
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

		$attribution = is_array( $values ) ? ( $values['oyster_attribution'] ?? null ) : null;
		$batch_id    = is_array( $attribution ) ? (string) ( $attribution['batch_id'] ?? '' ) : '';

		if ( '' !== $batch_id ) {
			$this->stamp_from_cart( $order, $batch_id, is_array( $attribution ) ? $attribution : array() );
			return;
		}

		$this->stamp_from_cookie( $order );
	}

	/**
	 * The shopper reached checkout from the scan itself, so this is the strongest
	 * claim available and outranks a cookie stamp already on the order, whichever
	 * line it arrives on. Among cart stamps the first still wins.
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
	 * Nothing in the cart names a scan, so fall back to the one this browser
	 * ran. That the browser scanned is not proof this order acted on it, which
	 * is why the marker rides along: Oyster only credits a cookie-borne batch
	 * when the order also contains something that scan recommended.
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
	 * The cookie is written by the storefront and can be edited by whoever holds
	 * it, so it is read as an untrusted hint and never as an identity: a batch id
	 * is an opaque token, Oyster resolves whose scan it was and answers nothing
	 * about that person back to this store. The same is already true of the batch
	 * id the widget posts to `/cart/add`.
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

		if ( 'yes' === $order->get_meta( self::META_RECORDED ) ) {
			return;
		}

		if ( ! $this->is_reportable( $order ) ) {
			return;
		}

		$this->enqueue_report( $order_id );
	}

	/**
	 * Whether this order is Oyster's to hear about at all.
	 *
	 * A stamped order names its scan outright. An unstamped one may still belong
	 * to a shopper Oyster can recognise, but it can only recognise them through a
	 * product it recommended, so an order of nothing this store has ever synced
	 * is not attributable by any route and stays here.
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
	 * The batch id is optional. An order without one is still worth sending:
	 * Oyster can recognise the shopper from the email and what they bought, and
	 * answers `recorded: false` when it cannot.
	 *
	 * @return array<string, mixed>
	 */
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

		// Only ever sent as true. Absent means the batch id came from the cart,
		// which is what Oyster assumes, so a false would say nothing.
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
