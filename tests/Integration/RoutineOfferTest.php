<?php
declare( strict_types=1 );
namespace Oyster\Woo\Tests\Integration;

use Oyster\Woo\Api\Client;
use Oyster\Woo\Checkout\Cart_Controller;
use Oyster\Woo\Checkout\Cart_Filler;
use Oyster\Woo\Checkout\Order_Attribution;
use Oyster\Woo\Checkout\Routine_Offer;
use Oyster\Woo\Support\Connection;
use Oyster\Woo\Wheel\Provisioner;
use WC_Coupon;
use WC_Product_Simple;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Driven through the real cart-add handoff and WooCommerce's own totals, because
 * the discount is only correct if WooCommerce agrees which lines it covers.
 */
final class RoutineOfferTest extends WP_UnitTestCase {
	private const BATCH = 'b6f1c2d4-0000-4000-8000-000000000001';
	private const TIERS = array( array( 'minItems' => 2, 'percent' => 10 ), array( 'minItems' => 3, 'percent' => 20 ) );

	private Connection $connection;

	/** @var array<int, WC_Product_Simple> Keyed by Oyster product id. */
	private array $products = array();

	private array $coupons = array();

	/** @var array<string, mixed>|null|false Null answers a server error. */
	private $quote_response;

	private array $quote_requests = array();

	private array $order_reports = array();

	public function set_up(): void {
		parent::set_up();
		$this->connection = new Connection();
		$this->connection->save( array( 'bearer' => 'test-store-bearer', 'vendor_id' => 9301 ) );
		update_option( 'woocommerce_enable_coupons', 'yes' );
		foreach ( array( 501, 502, 503, 504 ) as $oyster_id ) {
			$product = new WC_Product_Simple();
			$product->set_name( 'Routine product ' . $oyster_id );
			$product->set_status( 'publish' );
			$product->set_regular_price( '20' );
			$product->save();
			$this->products[ $oyster_id ] = $product;
		}
		$this->quote_response = array( 'tiers' => self::TIERS, 'eligible_product_ids' => array( 501, 502, 503 ) );
		add_filter( 'pre_http_request', array( $this, 'answer_request' ), 10, 3 );
		wc_load_cart();
		WC()->cart->empty_cart();
		WC()->session->set( Routine_Offer::SESSION_TIERS, null );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'answer_request' ), 10 );
		WC()->cart->empty_cart();
		WC()->session->set( Routine_Offer::SESSION_TIERS, null );
		foreach ( $this->products as $product ) {
			$product->delete( true );
		}
		foreach ( $this->coupons as $coupon ) {
			$coupon->delete( true );
		}
		$this->connection->clear();
		parent::tear_down();
	}

	/**
	 * @param mixed  $preempt
	 * @param array  $args
	 * @param string $url
	 * @return mixed
	 */
	public function answer_request( $preempt, $args, $url ) {
		$body = json_decode( (string) ( $args['body'] ?? '' ), true );
		if ( str_ends_with( $url, '/products/resolve-variants' ) ) {
			$data = array();
			foreach ( $body['product_ids'] as $oyster_id ) {
				$data[ (string) $oyster_id ] = array( 'woocommerce_product_id' => $this->products[ $oyster_id ]->get_id() );
			}
			return $this->json( 200, array( 'data' => $data ) );
		}
		if ( str_ends_with( $url, '/routine-offer/quote' ) ) {
			$this->quote_requests[] = array( 'url' => $url, 'auth' => $args['headers']['Authorization'] ?? null, 'body' => $body );
			return null === $this->quote_response ? $this->json( 500, array( 'message' => 'Server Error' ) ) : $this->json( 200, array( 'data' => $this->quote_response ) );
		}
		if ( str_ends_with( $url, '/integrations/woocommerce/orders' ) ) {
			$this->order_reports[] = $body;
			return $this->json( 200, array( 'data' => array( 'id' => 1 ), 'recorded' => true ) );
		}
		return $preempt;
	}

	private function json( int $status, array $body ): array {
		return array( 'headers' => array(), 'response' => array( 'code' => $status, 'message' => '' ), 'body' => wp_json_encode( $body ), 'cookies' => array() );
	}

	private function controller(): Cart_Controller {
		$client = new Client();
		return new Cart_Controller( new Cart_Filler( $this->connection, $client ), new Routine_Offer( $this->connection, $client ) );
	}

	/** @param int[] $oyster_ids */
	private function hand_off( array $oyster_ids, ?string $batch = self::BATCH, $wheel_reward = null ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/oyster-woocommerce/v1/cart/add' );
		$request->set_param( 'items', array_map( static fn( $id ) => array( 'product_id' => $id, 'quantity' => 1 ), $oyster_ids ) );
		if ( null !== $batch ) {
			$request->set_param( 'batch_id', $batch );
		}
		if ( null !== $wheel_reward ) {
			$request->set_param( 'wheel_reward', $wheel_reward );
		}
		$response = $this->controller()->handle_add( $request );
		WC()->cart->calculate_totals();
		return $response;
	}

	private function routine_discount(): float {
		return (float) WC()->cart->get_coupon_discount_amount( Routine_Offer::COUPON_CODE );
	}

	private function has_routine_coupon(): bool {
		return in_array( Routine_Offer::COUPON_CODE, WC()->cart->get_applied_coupons(), true );
	}

	public function test_the_quote_is_asked_for_the_scan_and_the_requested_products(): void {
		$this->hand_off( array( 501, 504 ) );

		self::assertCount( 1, $this->quote_requests );
		self::assertSame( 'Bearer test-store-bearer', $this->quote_requests[0]['auth'] );
		self::assertSame( array( 'skin_analysis_batch_id' => self::BATCH, 'product_ids' => array( 501, 504 ) ), $this->quote_requests[0]['body'] );
	}

	public function test_a_handoff_without_a_scan_asks_for_no_quote(): void {
		$this->hand_off( array( 501, 502 ), null );

		self::assertSame( array(), $this->quote_requests );
		self::assertFalse( $this->has_routine_coupon() );
	}

	public function test_two_routine_products_earn_the_first_tier(): void {
		self::assertSame( 200, $this->hand_off( array( 501, 502 ) )->get_status() );

		self::assertTrue( $this->has_routine_coupon() );
		self::assertEqualsWithDelta( 4.0, $this->routine_discount(), 0.001 );
		self::assertSame( 'Routine discount', wc_cart_totals_coupon_label( new WC_Coupon( Routine_Offer::COUPON_CODE ), false ) );
	}

	public function test_three_routine_products_earn_the_highest_tier_reached(): void {
		$this->hand_off( array( 501, 502, 503 ) );

		self::assertEqualsWithDelta( 12.0, $this->routine_discount(), 0.001 );
	}

	public function test_only_lines_from_the_routine_are_discounted(): void {
		$this->hand_off( array( 501, 502, 504 ) );
		WC()->cart->add_to_cart( $this->products[501]->get_id(), 2 );
		WC()->cart->add_to_cart( $this->products[503]->get_id() );
		WC()->cart->calculate_totals();

		self::assertEqualsWithDelta( 4.0, $this->routine_discount(), 0.001, 'hand-added lines, and one the scan did not recommend, earn nothing' );
		foreach ( WC()->cart->get_cart() as $item ) {
			$flagged = ! empty( $item[ Routine_Offer::CART_ITEM_FLAG ] );
			self::assertEqualsWithDelta( $flagged ? 18.0 * $item['quantity'] : 20.0 * $item['quantity'], (float) $item['line_total'], 0.001 );
		}
	}

	public function test_quantity_does_not_count_towards_a_tier(): void {
		$this->quote_response['eligible_product_ids'] = array( 501 );
		$request = new WP_REST_Request( 'POST', '/oyster-woocommerce/v1/cart/add' );
		$request->set_param( 'items', array( array( 'product_id' => 501, 'quantity' => 3 ) ) );
		$request->set_param( 'batch_id', self::BATCH );
		$this->controller()->handle_add( $request );
		WC()->cart->calculate_totals();

		self::assertFalse( $this->has_routine_coupon() );
	}

	public function test_dropping_below_the_first_tier_removes_the_discount(): void {
		$this->hand_off( array( 501, 502, 503 ) );
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( $item['product_id'] !== $this->products[501]->get_id() ) {
				WC()->cart->remove_cart_item( $key );
				break;
			}
		}
		self::assertEqualsWithDelta( 4.0, $this->routine_discount(), 0.001 );

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( $item['product_id'] !== $this->products[501]->get_id() ) {
				WC()->cart->remove_cart_item( $key );
			}
		}

		self::assertFalse( $this->has_routine_coupon() );
		self::assertSame( 0.0, (float) WC()->cart->get_discount_total() );
	}

	public function test_a_wheel_reward_in_the_cart_wins_over_the_routine_offer(): void {
		$this->hand_off( array( 501, 502 ) );
		$wheel = new WC_Coupon();
		$wheel->set_code( 'wheel-' . wp_generate_uuid4() );
		$wheel->set_discount_type( 'percent' );
		$wheel->set_amount( 5 );
		$wheel->update_meta_data( Provisioner::META_REWARD, 'reward-1' );
		$wheel->save();
		$this->coupons[] = $wheel;

		self::assertTrue( WC()->cart->apply_coupon( $wheel->get_code() ) );
		WC()->cart->calculate_totals();

		self::assertFalse( $this->has_routine_coupon() );
		self::assertFalse( WC()->cart->apply_coupon( Routine_Offer::COUPON_CODE ) );
		self::assertEqualsWithDelta( 2.0, (float) WC()->cart->get_discount_total(), 0.001 );

		WC()->cart->remove_coupon( $wheel->get_code() );
		WC()->cart->calculate_totals();

		self::assertTrue( $this->has_routine_coupon() );
	}

	public function test_a_failed_quote_still_fills_the_cart_without_an_offer(): void {
		$this->quote_response = null;

		$response = $this->hand_off( array( 501, 502, 503 ) );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 3, $response->get_data()['added'] );
		self::assertFalse( $this->has_routine_coupon() );
		foreach ( WC()->cart->get_cart() as $item ) {
			self::assertArrayNotHasKey( Routine_Offer::CART_ITEM_FLAG, $item );
		}
	}

	public function test_a_malformed_quote_is_treated_as_no_answer(): void {
		$this->quote_response = array( 'tiers' => array( array( 'minItems' => 3, 'percent' => 20 ), array( 'minItems' => 2, 'percent' => 10 ) ), 'eligible_product_ids' => array( 501, 502 ) );

		self::assertSame( 200, $this->hand_off( array( 501, 502 ) )->get_status() );
		self::assertFalse( $this->has_routine_coupon() );
	}

	public function test_a_vendor_without_an_offer_clears_the_one_held_and_flags_nothing(): void {
		$this->hand_off( array( 501, 502 ) );
		self::assertTrue( $this->has_routine_coupon() );

		$this->quote_response = array( 'tiers' => array(), 'eligible_product_ids' => array( 503 ) );
		$this->hand_off( array( 503 ) );

		self::assertNull( WC()->session->get( Routine_Offer::SESSION_TIERS ) );
		self::assertFalse( $this->has_routine_coupon() );
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( $item['product_id'] === $this->products[503]->get_id() ) {
				self::assertArrayNotHasKey( Routine_Offer::CART_ITEM_FLAG, $item );
			}
		}
	}

	public function test_typing_the_code_on_a_cart_with_no_routine_lines_is_rejected(): void {
		WC()->cart->add_to_cart( $this->products[501]->get_id() );
		WC()->cart->add_to_cart( $this->products[502]->get_id() );

		self::assertFalse( WC()->cart->apply_coupon( Routine_Offer::COUPON_CODE ) );
		self::assertFalse( WC()->cart->apply_coupon( strtoupper( Routine_Offer::COUPON_CODE ) ) );

		WC()->session->set( Routine_Offer::SESSION_TIERS, array( array( 'minItems' => 1, 'percent' => 50.0 ) ) );
		self::assertFalse( WC()->cart->apply_coupon( Routine_Offer::COUPON_CODE ), 'tiers alone earn nothing without a routine line' );
		WC()->cart->calculate_totals();
		self::assertSame( 0.0, (float) WC()->cart->get_discount_total() );
		wc_clear_notices();
	}

	public function test_a_rejected_wheel_reward_restores_the_offer_held_before_it(): void {
		$this->hand_off( array( 501, 502 ) );
		$contents = WC()->cart->get_cart_contents();
		$tiers    = WC()->session->get( Routine_Offer::SESSION_TIERS );

		$this->quote_response = array( 'tiers' => array( array( 'minItems' => 1, 'percent' => 50 ) ), 'eligible_product_ids' => array( 503 ) );
		$response = $this->hand_off( array( 503 ), self::BATCH, array( 'reward_id' => 'unknown', 'claim_token' => 'wrong', 'redemption_authority' => 'woocommerce' ) );

		self::assertSame( 409, $response->get_status() );
		self::assertSame( array_keys( $contents ), array_keys( WC()->cart->get_cart_contents() ) );
		self::assertSame( $tiers, WC()->session->get( Routine_Offer::SESSION_TIERS ) );
		self::assertTrue( $this->has_routine_coupon() );
		self::assertEqualsWithDelta( 4.0, $this->routine_discount(), 0.001 );
	}

	public function test_the_discount_reaches_the_order_and_its_report(): void {
		$this->hand_off( array( 501, 502, 504 ) );

		$order = wc_create_order();
		WC()->checkout->create_order_line_items( $order, WC()->cart );
		WC()->checkout->create_order_coupon_lines( $order, WC()->cart );
		$order->set_discount_total( WC()->cart->get_discount_total() );
		$order->set_total( WC()->cart->get_total( 'edit' ) );
		$order->save();

		self::assertSame( array( Routine_Offer::COUPON_CODE ), $order->get_coupon_codes() );
		self::assertEqualsWithDelta( 4.0, (float) $order->get_discount_total(), 0.001 );
		self::assertSame( self::BATCH, $order->get_meta( Order_Attribution::META_BATCH_ID ) );

		$order->calculate_totals();
		self::assertEqualsWithDelta( 4.0, (float) $order->get_discount_total(), 0.001, 'recalculating a placed order keeps the discount on the routine lines' );
		self::assertEqualsWithDelta( 56.0, (float) $order->get_total(), 0.001 );

		( new Order_Attribution( $this->connection, new Client() ) )->report_paid_order( $order->get_id() );

		self::assertCount( 1, $this->order_reports );
		self::assertEqualsWithDelta( 56.0, $this->order_reports[0]['total'], 0.001 );
		$prices = array_column( $this->order_reports[0]['line_items'], 'price', 'woocommerce_product_id' );
		self::assertEqualsWithDelta( 18.0, $prices[ (string) $this->products[501]->get_id() ], 0.001 );
		self::assertEqualsWithDelta( 18.0, $prices[ (string) $this->products[502]->get_id() ], 0.001 );
		self::assertEqualsWithDelta( 20.0, $prices[ (string) $this->products[504]->get_id() ], 0.001 );
		$order->delete( true );
	}
}
