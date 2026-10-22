<?php

declare( strict_types=1 );

namespace Oyster\Woo\Tests\Integration;

use Oyster\Woo\Sync\Individual_Product;
use Oyster\Woo\Sync\Product_Mapper;
use WC_Product_External;
use WC_Product_Grouped;
use WC_Product_Simple;
use WP_UnitTestCase;

/**
 * Only single items reach Oyster, whatever the merchant's category or tag filter
 * matches. The save hooks used to send anything that matched, unlike the full import.
 */
final class IndividualProductTest extends WP_UnitTestCase {

	private function simple( string $price = '1665', string $status = 'publish' ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'Gentle Hydrating Cleanser' );
		$product->set_regular_price( $price );
		$product->set_status( $status );
		$product->save();

		return $product;
	}

	public function test_a_published_simple_product_is_individual(): void {
		$this->assertTrue( Individual_Product::is_individual( $this->simple() ) );
	}

	public function test_grouped_and_external_products_are_not(): void {
		$grouped = new WC_Product_Grouped();
		$grouped->set_name( 'Morning set' );
		$grouped->save();

		$external = new WC_Product_External();
		$external->set_name( 'Partner serum' );
		$external->set_regular_price( '900' );
		$external->save();

		$this->assertFalse( Individual_Product::is_individual( $grouped ) );
		$this->assertFalse( Individual_Product::is_individual( $external ) );
	}

	public function test_a_gift_card_is_not(): void {
		$card = $this->simple( '5000' );
		$card->update_meta_data( '_gift_card', 'yes' );
		$card->save();

		$this->assertFalse( Individual_Product::is_individual( $card ) );
	}

	public function test_a_draft_or_private_product_is_not(): void {
		$this->assertFalse( Individual_Product::is_individual( $this->simple( '1665', 'draft' ) ) );
		$this->assertFalse( Individual_Product::is_individual( $this->simple( '1665', 'private' ) ) );
	}

	public function test_a_free_product_maps_to_no_row(): void {
		$this->assertSame( array(), Product_Mapper::to_rows( $this->simple( '0' ) ) );
	}
}
