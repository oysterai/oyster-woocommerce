<?php
/**
 * Merchant-controlled catalog sync scope (which products are eligible to sync).
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Support;

use WC_Product;
use WC_Product_Variation;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Not every WooCommerce catalog is all skincare — a general store might sell
 * apparel, gift cards, or unrelated add-ons alongside the products Oyster
 * should actually recommend. This lets a vendor scope sync to specific
 * product categories and/or tags, either as an allow-list (only these sync)
 * or a deny-list (everything except these syncs). Categories and tags are
 * combined with OR within whichever list is active — a product is a match if
 * it carries ANY of the selected terms, from either taxonomy.
 */
final class Catalog_Filter {

	public const OPTION_KEY = 'oyster_woocommerce_catalog_filter';

	public const MODE_ALL   = 'all';
	public const MODE_ALLOW = 'allow';
	public const MODE_DENY  = 'deny';

	/**
	 * @return array{mode:string, category_ids:int[], tag_ids:int[]}
	 */
	public static function defaults(): array {
		return array(
			'mode'         => self::MODE_ALL,
			'category_ids' => array(),
			'tag_ids'      => array(),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Sanitize a raw settings submission. Used as the Settings API
	 * sanitize_callback, so it receives untrusted input. Term ids that don't
	 * exist in the given taxonomy (e.g. a category deleted after being
	 * selected) are silently dropped rather than left dangling.
	 *
	 * @param mixed $input
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();

		$mode = (string) ( $input['mode'] ?? self::MODE_ALL );
		if ( ! in_array( $mode, array( self::MODE_ALL, self::MODE_ALLOW, self::MODE_DENY ), true ) ) {
			$mode = self::MODE_ALL;
		}

		$category_ids = self::sanitize_term_ids( $input['category_ids'] ?? array(), 'product_cat' );
		$tag_ids      = self::sanitize_term_ids( $input['tag_ids'] ?? array(), 'product_tag' );

		// An allow-list with nothing selected would silently stop syncing
		// every product with no obvious explanation — refuse that instead.
		if ( self::MODE_ALLOW === $mode && ! $category_ids && ! $tag_ids ) {
			$mode = self::MODE_ALL;

			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error(
					self::OPTION_KEY,
					'oyster_woo_catalog_filter_empty_allow',
					__( '"Only sync selected categories/tags" was chosen with nothing selected — reverted to syncing all products. Pick at least one category or tag to restrict sync.', 'oyster-woocommerce' )
				);
			}
		}

		return array(
			'mode'         => $mode,
			'category_ids' => $category_ids,
			'tag_ids'      => $tag_ids,
		);
	}

	/**
	 * @param mixed $raw
	 * @return int[]
	 */
	private static function sanitize_term_ids( $raw, string $taxonomy ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$ids = array_filter( array_unique( array_map( 'absint', $raw ) ) );

		$valid = array_filter(
			$ids,
			static function ( $id ) use ( $taxonomy ) {
				return get_term( $id, $taxonomy ) instanceof WP_Term;
			}
		);

		return array_values( $valid );
	}

	/**
	 * Whether a product is in scope for sync under the current filter.
	 * Variations don't carry their own category/tag terms — WooCommerce
	 * assigns product_cat/product_tag only to the parent product post — so a
	 * variation is checked against its parent.
	 */
	public static function is_eligible( WC_Product $product ): bool {
		$settings = self::get();
		if ( self::MODE_ALL === $settings['mode'] ) {
			return true;
		}

		$post_id = $product instanceof WC_Product_Variation ? $product->get_parent_id() : $product->get_id();

		$matches = self::matches_any( $post_id, $settings['category_ids'], 'product_cat' )
			|| self::matches_any( $post_id, $settings['tag_ids'], 'product_tag' );

		return self::MODE_ALLOW === $settings['mode'] ? $matches : ! $matches;
	}

	/**
	 * @param int[] $term_ids
	 */
	private static function matches_any( int $post_id, array $term_ids, string $taxonomy ): bool {
		if ( ! $term_ids ) {
			return false;
		}

		return (bool) has_term( $term_ids, $taxonomy, $post_id );
	}
}
