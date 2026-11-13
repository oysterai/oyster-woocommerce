<?php
declare( strict_types=1 );
namespace Oyster\Woo\Tests\Unit;

use Oyster\Woo\Checkout\Routine_Offer;
use PHPUnit\Framework\TestCase;

final class RoutineOfferTiersTest extends TestCase {
	private const TIERS = array(
		array( 'minItems' => 2, 'percent' => 10.0 ),
		array( 'minItems' => 3, 'percent' => 20.0 ),
	);

	public function test_the_highest_tier_reached_wins(): void {
		self::assertSame( 0.0, Routine_Offer::percent_for( self::TIERS, 0 ) );
		self::assertSame( 0.0, Routine_Offer::percent_for( self::TIERS, 1 ) );
		self::assertSame( 10.0, Routine_Offer::percent_for( self::TIERS, 2 ) );
		self::assertSame( 20.0, Routine_Offer::percent_for( self::TIERS, 3 ) );
		self::assertSame( 20.0, Routine_Offer::percent_for( self::TIERS, 7 ) );
		self::assertSame( 0.0, Routine_Offer::percent_for( array(), 5 ) );
	}

	public function test_distinct_flagged_products_are_counted_and_quantity_is_ignored(): void {
		$flag = Routine_Offer::CART_ITEM_FLAG;
		$cart = array(
			'a' => array( 'product_id' => 10, 'variation_id' => 0, 'quantity' => 5, $flag => true ),
			'b' => array( 'product_id' => 11, 'variation_id' => 21, 'quantity' => 1, $flag => true ),
			'c' => array( 'product_id' => 11, 'variation_id' => 22, 'quantity' => 1, $flag => true ),
			'd' => array( 'product_id' => 10, 'variation_id' => 0, 'quantity' => 1, $flag => true ),
			'e' => array( 'product_id' => 12, 'variation_id' => 0, 'quantity' => 1 ),
		);

		self::assertSame( 3, Routine_Offer::count_routine_products( $cart ) );
	}

	public function test_well_formed_tiers_are_kept_and_an_empty_list_means_no_offer(): void {
		self::assertSame( self::TIERS, Routine_Offer::sanitize_tiers( array( array( 'minItems' => 2, 'percent' => 10 ), array( 'minItems' => 3, 'percent' => 20 ) ) ) );
		self::assertSame( array( array( 'minItems' => 2, 'percent' => 12.5 ) ), Routine_Offer::sanitize_tiers( array( array( 'minItems' => 2, 'percent' => 12.5 ) ) ) );
		self::assertSame( array(), Routine_Offer::sanitize_tiers( array() ) );
	}

	public function test_a_malformed_quote_is_rejected_whole(): void {
		$cases = array(
			'missing'          => null,
			'not a list'       => array( 'a' => array( 'minItems' => 2, 'percent' => 10 ) ),
			'not increasing'   => array( array( 'minItems' => 3, 'percent' => 20 ), array( 'minItems' => 2, 'percent' => 10 ) ),
			'duplicate'        => array( array( 'minItems' => 2, 'percent' => 10 ), array( 'minItems' => 2, 'percent' => 20 ) ),
			'string count'     => array( array( 'minItems' => '2', 'percent' => 10 ) ),
			'zero count'       => array( array( 'minItems' => 0, 'percent' => 10 ) ),
			'zero percent'     => array( array( 'minItems' => 2, 'percent' => 0 ) ),
			'over 100 percent' => array( array( 'minItems' => 2, 'percent' => 101 ) ),
			'string percent'   => array( array( 'minItems' => 2, 'percent' => '10' ) ),
		);

		foreach ( $cases as $name => $raw ) {
			self::assertNull( Routine_Offer::sanitize_tiers( $raw ), $name );
		}
	}
}
