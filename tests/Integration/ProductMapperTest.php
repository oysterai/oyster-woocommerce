<?php

declare( strict_types=1 );

namespace Oyster\Woo\Tests\Integration;

use Oyster\Woo\Sync\Product_Mapper;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_UnitTestCase;

/**
 * Mapping real WooCommerce products into the rows a sync sends.
 *
 * Needs the genuine article: variations inherit price, weight and description
 * from their parent through WooCommerce's own accessors, and a hand-built
 * double would encode our assumptions about that rather than test them.
 */
final class ProductMapperTest extends WP_UnitTestCase {

	private function simple( array $props = array() ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( $props['name'] ?? 'Gentle Hydrating Cleanser' );
		$product->set_regular_price( $props['price'] ?? '1665' );
		$product->save();

		return $product;
	}

	public function test_a_simple_product_maps_to_one_row(): void {
		$product = $this->simple();

		$rows = Product_Mapper::to_rows( $product );

		$this->assertCount( 1, $rows );
		$this->assertSame( (string) $product->get_id(), $rows[0]['woocommerce_product_id'] );
		$this->assertNull( $rows[0]['woocommerce_variation_id'] );
		$this->assertSame( 'Gentle Hydrating Cleanser', $rows[0]['name'] );
		$this->assertSame( 1665.0, $rows[0]['price'] );
	}

	/**
	 * The backend rejects the whole batch without a price, so an unpriced
	 * product is dropped before it is sent — and must say why, or it is
	 * indistinguishable from one that was simply never attempted.
	 */
	public function test_an_unpriced_product_is_skipped_with_a_reason(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'No price yet' );
		$product->save();

		$this->assertSame( array(), Product_Mapper::to_rows( $product ) );
		$this->assertStringContainsString( 'price', strtolower( (string) Product_Mapper::skip_reason( $product ) ) );
	}

	public function test_a_priced_product_has_no_skip_reason(): void {
		$this->assertNull( Product_Mapper::skip_reason( $this->simple() ) );
	}

	/**
	 * Each purchasable unit is its own row, keyed by variation id — the parent
	 * of a variable product is not itself sellable.
	 */
	public function test_a_variable_product_maps_one_row_per_variation(): void {
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Cleanser' );
		$parent->save();

		$ids = array();
		foreach ( array( '1000', '2000' ) as $price ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_regular_price( $price );
			$ids[] = $variation->save();
		}

		$rows = Product_Mapper::to_rows( wc_get_product( $parent->get_id() ) );

		$this->assertCount( 2, $rows );
		foreach ( $rows as $row ) {
			$this->assertSame( (string) $parent->get_id(), $row['woocommerce_product_id'] );
			$this->assertContains( (int) $row['woocommerce_variation_id'], $ids );
		}
	}

	public function test_a_variable_product_with_no_variations_says_so(): void {
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Nothing to sell' );
		$parent->save();

		$product = wc_get_product( $parent->get_id() );

		$this->assertSame( array(), Product_Mapper::to_rows( $product ) );
		$this->assertStringContainsString( 'variation', strtolower( (string) Product_Mapper::skip_reason( $product ) ) );
	}

	/**
	 * Category and tags live on the parent post — WooCommerce assigns those
	 * taxonomies to the product, never to a variation — so a variation has to
	 * read them from its parent or every variable product syncs uncategorised.
	 */
	public function test_a_variation_inherits_its_parents_taxonomy(): void {
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Cleanser' );
		$parent->save();

		wp_set_object_terms( $parent->get_id(), 'Cleansers', 'product_cat' );

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '1665' );
		$variation->save();

		$rows = Product_Mapper::to_rows( wc_get_product( $parent->get_id() ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'Cleansers', $rows[0]['category'] );
	}

	/** Rows carry the store's currency, not a hardcoded one. */
	public function test_rows_carry_the_store_currency(): void {
		update_option( 'woocommerce_currency', 'KES' );

		$rows = Product_Mapper::to_rows( $this->simple() );

		$this->assertSame( 'KES', $rows[0]['currency'] );
	}
}
