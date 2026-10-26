<?php
declare( strict_types=1 );
namespace Oyster\Woo\Tests\Unit;
use Oyster\Woo\Wheel\Terms;
use PHPUnit\Framework\TestCase;

final class WheelTermsTest extends TestCase {
	private function terms(): array {
		return array( 'reward_id' => 'reward-1', 'code' => 'wheel-code-1', 'terms_hash' => str_repeat( 'a', 64 ), 'email' => 'shopper@example.test', 'expires_at' => '2030-01-01T00:00:00Z', 'usage_limit' => 1, 'type' => 'percentage_discount', 'value' => 10, 'currency' => 'USD', 'min_order_amount' => null, 'restrictions' => array( 'allowed_product_ids' => array(), 'excluded_product_ids' => array(), 'combines_with_discounts' => false ) );
	}
	public function test_valid_discount_and_single_use_gift_terms(): void {
		$terms = $this->terms();
		self::assertTrue( Terms::valid( $terms ) );
		$terms['type'] = 'free_item';
		$terms['product_id'] = 22;
		self::assertTrue( Terms::valid( $terms ) );
		$terms['usage_limit'] = 2;
		self::assertFalse( Terms::valid( $terms ) );
	}
	public function test_rejects_unsupported_or_malformed_terms_without_coercion(): void {
		foreach ( array( 'type' => 'free_shipping', 'usage_limit' => '1', 'value' => 101, 'email' => 'not-an-email', 'terms_hash' => 'bad', 'code' => 'bad/../code' ) as $key => $value ) {
			$terms = $this->terms();
			$terms[ $key ] = $value;
			self::assertFalse( Terms::valid( $terms ), $key );
		}
		$terms = $this->terms();
		$terms['restrictions']['unrecognized'] = true;
		self::assertFalse( Terms::valid( $terms ) );
	}
	public function test_free_delivery_has_no_product_discount_or_product_restrictions(): void {
		$terms = $this->terms();
		$terms['type'] = 'free_delivery';
		$terms['value'] = null;
		$terms['min_order_amount'] = 50;
		self::assertTrue( Terms::valid( $terms ) );
		foreach ( array( 'allowed_product_ids', 'excluded_product_ids' ) as $key ) {
			$restricted = $terms;
			$restricted['restrictions'][ $key ] = array( 22 );
			self::assertFalse( Terms::valid( $restricted ) );
		}
		$terms['value'] = 10;
		self::assertFalse( Terms::valid( $terms ) );
	}
	public function test_gifts_require_an_expiry_and_no_extra_cart_terms(): void {
		$terms = $this->terms();
		$terms['type'] = 'free_item';
		$terms['product_id'] = 22;
		$terms['expires_at'] = null;
		self::assertFalse( Terms::valid( $terms ) );
		$terms['expires_at'] = '2030-01-01T00:00:00Z';
		$terms['min_order_amount'] = 10;
		self::assertFalse( Terms::valid( $terms ) );
	}
	public function test_fixed_cart_discounts_reject_product_restrictions_that_native_checkout_cannot_enforce(): void {
		$terms = $this->terms();
		$terms['type'] = 'fixed_discount';
		self::assertTrue( Terms::valid( $terms ) );
		$terms['restrictions']['allowed_product_ids'] = array( 22 );
		self::assertFalse( Terms::valid( $terms ) );
		$terms['restrictions']['allowed_product_ids'] = array();
		$terms['restrictions']['excluded_product_ids'] = array( 22 );
		self::assertFalse( Terms::valid( $terms ) );
	}
}
