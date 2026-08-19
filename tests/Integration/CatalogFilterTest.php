<?php

declare( strict_types=1 );

namespace Oyster\Woo\Tests\Integration;

use Oyster\Woo\Support\Catalog_Filter;
use WC_Product_Simple;
use WC_Product_Variation;
use WP_UnitTestCase;

/**
 * Which products are in scope for sync.
 *
 * Real taxonomy terms and a real `has_term()`, because the whole rule is a
 * taxonomy question — and because getting it wrong in the permissive direction
 * pushes a merchant's entire catalogue to Oyster when they asked for one
 * category.
 */
final class CatalogFilterTest extends WP_UnitTestCase {

	private function product( string $category = '' ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'Cleanser' );
		$product->set_regular_price( '1665' );
		$product->save();

		if ( '' !== $category ) {
			wp_set_object_terms( $product->get_id(), $category, 'product_cat' );
		}

		return wc_get_product( $product->get_id() );
	}

	private function filter( string $mode, array $categories = array() ): void {
		$term_ids = array();
		foreach ( $categories as $name ) {
			$term       = term_exists( $name, 'product_cat' ) ?: wp_insert_term( $name, 'product_cat' );
			$term_ids[] = (int) $term['term_id'];
		}

		update_option(
			Catalog_Filter::OPTION_KEY,
			array( 'mode' => $mode, 'category_ids' => $term_ids, 'tag_ids' => array() )
		);
	}

	/**
	 * An empty allow-list means nothing is in scope. That is the safe default —
	 * the alternative reading, "no restrictions", would sync a whole catalogue
	 * the moment someone saved the settings page without choosing anything.
	 */
	public function test_an_empty_allow_list_excludes_everything(): void {
		$this->filter( Catalog_Filter::MODE_ALLOW );

		$this->assertFalse( Catalog_Filter::is_eligible( $this->product( 'Cleansers' ) ) );
	}

	public function test_an_allow_list_includes_only_what_it_names(): void {
		$this->filter( Catalog_Filter::MODE_ALLOW, array( 'Cleansers' ) );

		$this->assertTrue( Catalog_Filter::is_eligible( $this->product( 'Cleansers' ) ) );
		$this->assertFalse( Catalog_Filter::is_eligible( $this->product( 'Sunscreens' ) ) );
		$this->assertFalse( Catalog_Filter::is_eligible( $this->product() ) );
	}

	public function test_a_deny_list_excludes_only_what_it_names(): void {
		$this->filter( Catalog_Filter::MODE_DENY, array( 'Cleansers' ) );

		$this->assertFalse( Catalog_Filter::is_eligible( $this->product( 'Cleansers' ) ) );
		$this->assertTrue( Catalog_Filter::is_eligible( $this->product( 'Sunscreens' ) ) );
		$this->assertTrue( Catalog_Filter::is_eligible( $this->product() ) );
	}

	/**
	 * Variations carry no terms of their own, so one checked directly would
	 * always miss an allow-list and never sync.
	 */
	public function test_a_variation_is_judged_by_its_parent(): void {
		$this->filter( Catalog_Filter::MODE_ALLOW, array( 'Cleansers' ) );

		$parent = $this->product( 'Cleansers' );

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '1665' );
		$variation->save();

		$this->assertTrue( Catalog_Filter::is_eligible( wc_get_product( $variation->get_id() ) ) );
	}
}
