<?php

declare( strict_types=1 );

namespace Oyster\Woo\Tests\Integration;

use Oyster\Woo\Checkout\Order_Attribution;
use Oyster\Woo\Sync\Catalog_Sync;
use Oyster\Woo\Sync\Sync_State;
use WC_Order;
use WC_Product_Simple;
use WP_UnitTestCase;

/**
 * Attribution surviving the trip from cart to order, and the order reaching
 * Oyster once it is paid.
 *
 * Both halves shipped broken and neither was caught, because both failures are
 * invisible from inside the plugin: the cart was stamped correctly, the order
 * was created correctly, and the attribution evaporated in between. So these
 * tests go through WooCommerce's own order-building call rather than a double,
 * and cover a gateway that never reports payment.
 */
final class OrderAttributionTest extends WP_UnitTestCase {

	private const BATCH = '01a08bcd-71cf-7023-9cb9-c6c177845c4e';

	public function set_up(): void {
		parent::set_up();

		// WooCommerce builds the cart on `wp_loaded` during a front-end request,
		// which never happens here, so it has to be asked for explicitly.
		if ( ! WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}
	}

	public function tear_down(): void {
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		unset( $_COOKIE[ Order_Attribution::COOKIE_SCAN_BATCH ] );

		parent::tear_down();
	}

	public function test_the_plugin_listens_on_both_checkouts_and_every_paid_route(): void {
		$this->assertNotFalse(
			has_action( 'woocommerce_checkout_create_order_line_item' ),
			'the line-item hook is the only one both the classic and block checkouts fire'
		);

		foreach ( array( 'woocommerce_payment_complete', 'woocommerce_order_status_processing', 'woocommerce_order_status_completed' ) as $hook ) {
			$this->assertNotFalse( has_action( $hook ), "nothing is listening on {$hook}" );
		}
	}

	/**
	 * `create_order_line_items()` is what the Store API calls to build a block
	 * checkout's order, and what WC_Checkout calls for a classic one. Driving it
	 * directly covers both without pretending to be an HTTP request.
	 */
	public function test_an_attributed_cart_stamps_the_order(): void {
		$this->add_to_cart( $this->product(), $this->attribution() );

		$order = $this->build_order();

		$this->assertSame( self::BATCH, $order->get_meta( Order_Attribution::META_BATCH_ID ) );
		$this->assertSame( '294', $order->get_meta( Order_Attribution::META_ROUTINE_ID ) );
		$this->assertSame(
			'7d15b88c-f240-4e4e-a367-da738e6ed1c8',
			$order->get_meta( Order_Attribution::META_ATTRIBUTION_ID )
		);
	}

	public function test_an_ordinary_cart_stamps_nothing(): void {
		$this->add_to_cart( $this->product() );

		$order = $this->build_order();

		$this->assertSame( '', (string) $order->get_meta( Order_Attribution::META_BATCH_ID ) );
	}

	public function test_the_first_attributed_item_wins(): void {
		$this->add_to_cart( $this->product(), $this->attribution() );
		$this->add_to_cart( $this->product( 'Unrelated' ), $this->attribution( 'a-later-batch' ) );

		$order = $this->build_order();

		$this->assertSame( self::BATCH, $order->get_meta( Order_Attribution::META_BATCH_ID ) );
	}

	/** The Store API rebuilds line items every time the cart hash changes. */
	public function test_rebuilding_the_line_items_does_not_move_the_stamp(): void {
		$this->add_to_cart( $this->product(), $this->attribution() );
		$order = $this->build_order();

		WC()->cart->empty_cart();
		$this->add_to_cart( $this->product( 'Swapped in later' ), $this->attribution( 'a-later-batch' ) );
		$order->remove_order_items( 'line_item' );
		WC()->checkout->create_order_line_items( $order, WC()->cart );

		$this->assertSame( self::BATCH, $order->get_meta( Order_Attribution::META_BATCH_ID ) );
	}

	/** Cash on delivery moves the order to processing itself. */
	public function test_an_order_that_never_fires_payment_complete_is_still_queued(): void {
		$order = $this->paid_order_via_offline_gateway();

		$this->assertNotEmpty(
			$this->queued_report_for( $order->get_id() ),
			'reaching processing without payment_complete must still queue the report'
		);
	}

	/**
	 * One status change fires several of the hooks the plugin listens on, and the
	 * recorded flag is only set once the report succeeds.
	 */
	public function test_a_second_paid_transition_does_not_queue_a_duplicate(): void {
		$order = $this->paid_order_via_offline_gateway();
		$order->update_status( 'completed' );

		$this->assertCount(
			1,
			$this->queued_report_for( $order->get_id() ),
			'processing then completed is one order, not two reports'
		);
	}

	public function test_an_unattributed_order_is_never_queued(): void {
		$order = wc_create_order();
		$order->set_payment_method( 'cod' );
		$order->update_status( 'processing' );

		$this->assertSame( array(), $this->queued_report_for( $order->get_id() ) );
	}

	/*
	 * -----------------------------------------------------------------------
	 * A scan remembered on the shopper's browser
	 * -----------------------------------------------------------------------
	 */

	public function test_an_unstamped_cart_falls_back_to_the_scan_cookie(): void {
		$this->remember_scan();
		$this->add_to_cart( $this->product() );

		$order = $this->build_order();

		$this->assertSame( self::BATCH, $order->get_meta( Order_Attribution::META_BATCH_ID ) );
		$this->assertSame( 'yes', $order->get_meta( Order_Attribution::META_BATCH_FROM_COOKIE ) );
	}

	/** The weaker claim has to lose even when it got there first. */
	public function test_a_cart_stamp_overrides_a_cookie_already_on_the_order(): void {
		$this->remember_scan( 'an-older-scan' );
		$this->add_to_cart( $this->product( 'Unrelated' ) );
		$this->add_to_cart( $this->product(), $this->attribution() );

		$order = $this->build_order();

		$this->assertSame( self::BATCH, $order->get_meta( Order_Attribution::META_BATCH_ID ) );
		$this->assertSame(
			'',
			$order->get_meta( Order_Attribution::META_BATCH_FROM_COOKIE ),
			'the marker must go with the guess it described'
		);
	}

	public function test_a_cookie_never_overrides_a_cart_stamp(): void {
		$this->remember_scan( 'an-older-scan' );
		$this->add_to_cart( $this->product(), $this->attribution() );
		$this->add_to_cart( $this->product( 'Unrelated' ) );

		$order = $this->build_order();

		$this->assertSame( self::BATCH, $order->get_meta( Order_Attribution::META_BATCH_ID ) );
	}

	public function test_a_malformed_cookie_is_not_stamped(): void {
		$this->remember_scan( 'not a batch id<script>' );
		$this->add_to_cart( $this->product() );

		$order = $this->build_order();

		$this->assertSame( '', $order->get_meta( Order_Attribution::META_BATCH_ID ) );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Reporting an order that carries no stamp
	 * -----------------------------------------------------------------------
	 */

	/** Oyster is the only side that can tell whether an unstamped order is one of its own. */
	public function test_an_unstamped_order_containing_a_synced_product_is_queued(): void {
		$product = $this->product();
		Sync_State::mark_synced( $product->get_id(), 'oyster-123', time() );

		$order = $this->paid_order_containing( $product );

		$this->assertNotEmpty( $this->queued_report_for( $order->get_id() ) );
	}

	/** The reason the store is not simply told to send everything. */
	public function test_an_unstamped_order_of_products_oyster_does_not_know_is_not_queued(): void {
		$order = $this->paid_order_containing( $this->product( 'Never synced' ) );

		$this->assertSame( array(), $this->queued_report_for( $order->get_id() ) );
	}

	public function test_an_order_with_no_items_at_all_is_not_queued(): void {
		$order = wc_create_order();
		$order->set_payment_method( 'cod' );
		$order->save();
		$order->update_status( 'processing' );

		$this->assertSame( array(), $this->queued_report_for( $order->get_id() ) );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Helpers
	 * -----------------------------------------------------------------------
	 */

	private function product( string $name = 'Calming Cleanser' ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( '24' );
		$product->save();

		return $product;
	}

	/**
	 * @return array<string, string>
	 */
	private function attribution( string $batch = self::BATCH ): array {
		return array(
			'batch_id'              => $batch,
			'routine_id'            => '294',
			'widget_attribution_id' => '7d15b88c-f240-4e4e-a367-da738e6ed1c8',
		);
	}

	/**
	 * @param array<string, string>|null $attribution
	 */
	private function add_to_cart( WC_Product_Simple $product, ?array $attribution = null ): void {
		WC()->cart->add_to_cart(
			$product->get_id(),
			1,
			0,
			array(),
			null === $attribution ? array() : array( 'oyster_attribution' => $attribution )
		);
	}

	private function build_order(): WC_Order {
		$order = wc_create_order();
		WC()->checkout->create_order_line_items( $order, WC()->cart );
		$order->save();

		return $order;
	}

	/** $_COOKIE is what the checkout reads, and a request with no headers has nowhere else. */
	private function remember_scan( string $batch = self::BATCH ): void {
		$_COOKIE[ Order_Attribution::COOKIE_SCAN_BATCH ] = $batch;
	}

	private function paid_order_containing( WC_Product_Simple $product ): WC_Order {
		$this->add_to_cart( $product );

		$order = $this->build_order();
		$order->set_payment_method( 'cod' );
		$order->save();

		$order->update_status( 'processing' );

		return $order;
	}

	private function paid_order_via_offline_gateway(): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( 'cod' );
		$order->update_meta_data( Order_Attribution::META_BATCH_ID, self::BATCH );
		$order->save();

		$order->update_status( 'processing' );

		return $order;
	}

	/**
	 * @return int[]
	 */
	private function queued_report_for( int $order_id ): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'Action Scheduler is not available in this environment.' );
		}

		return array_values(
			(array) as_get_scheduled_actions(
				array(
					'hook'   => Order_Attribution::HOOK_REPORT_ORDER,
					'args'   => array( 'order_id' => $order_id ),
					'group'  => Catalog_Sync::ACTION_GROUP,
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				),
				'ids'
			)
		);
	}
}
