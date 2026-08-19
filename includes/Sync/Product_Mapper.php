<?php
/**
 * Maps WooCommerce products/variations to bulk-upsert catalog rows.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Sync;

use Oyster\Woo\Catalog\Ingredients_Field;
use Oyster\Woo\Catalog\Size_Volume_Field;
use Oyster\Woo\Catalog\Skin_Type_Attribute;
use WC_Product;
use WC_Product_Variation;

defined( 'ABSPATH' ) || exit;

/**
 * One Oyster catalog row per purchasable WooCommerce unit — mirrors how the
 * backend models a purchasable unit: a simple product is one row with
 * `woocommerce_variation_id: null`; a variable product expands into one row
 * per variation, each carrying both ids. The parent product itself is never
 * sent as a row for a variable product — only its variations are
 * purchasable.
 */
final class Product_Mapper {

	/**
	 * @return array<int, array<string, mixed>> Zero rows for a variable
	 *         product with no variations, or a product/variation that can't
	 *         resolve a valid name+price (both required by the backend).
	 */
	public static function to_rows( WC_Product $product ): array {
		if ( $product->is_type( 'variable' ) ) {
			$rows = array();
			foreach ( $product->get_children() as $variation_id ) {
				$variation = function_exists( 'wc_get_product' ) ? wc_get_product( $variation_id ) : null;
				if ( ! $variation instanceof WC_Product_Variation ) {
					continue;
				}
				$row = self::build_row( $variation );
				if ( null !== $row ) {
					$rows[] = $row;
				}
			}
			return $rows;
		}

		$row = self::build_row( $product );
		return null !== $row ? array( $row ) : array();
	}

	/**
	 * Why this unit cannot be sent, or null when it can.
	 *
	 * Oyster requires a name and a price on every row and rejects the whole
	 * batch when one is missing, so these are caught here instead. Skipping is
	 * the right behaviour; doing it silently was not, because a product with no
	 * price is the likeliest reason a merchant's product never appears in Oyster
	 * and the products list had no way to say so.
	 */
	private static function row_problem( WC_Product $unit ): ?string {
		$price = $unit->get_price();
		if ( '' === $price || null === $price || ! is_numeric( $price ) ) {
			return __( 'This product has no price. Oyster needs a price to list it.', 'oyster-woocommerce' );
		}

		if ( '' === trim( (string) $unit->get_name() ) ) {
			return __( 'This product has no name.', 'oyster-woocommerce' );
		}

		return null;
	}

	/**
	 * Why a product produced no rows to sync, or null when it produced some.
	 *
	 * A variable product is reported on its variations, since that is where the
	 * price actually lives and where the merchant has to go to fix it.
	 */
	public static function skip_reason( WC_Product $product ): ?string {
		if ( ! $product->is_type( 'variable' ) ) {
			return self::row_problem( $product );
		}

		$children = $product->get_children();
		if ( ! $children ) {
			return __( 'This product has no variations to sync.', 'oyster-woocommerce' );
		}

		$reasons = array();
		foreach ( $children as $variation_id ) {
			$variation = function_exists( 'wc_get_product' ) ? wc_get_product( $variation_id ) : null;
			if ( ! $variation instanceof WC_Product_Variation ) {
				continue;
			}

			$problem = self::row_problem( $variation );
			if ( null === $problem ) {
				return null; // At least one variation is syncable.
			}

			$reasons[ $problem ] = true;
		}

		return $reasons
			? implode( ' ', array_keys( $reasons ) )
			: __( 'This product has no variations to sync.', 'oyster-woocommerce' );
	}

	/**
	 * @return array<string, mixed>|null Null when the unit cannot be sent;
	 *         {@see row_problem()} says why.
	 */
	private static function build_row( WC_Product $unit ): ?array {
		if ( null !== self::row_problem( $unit ) ) {
			return null;
		}

		$price = $unit->get_price();
		$name  = $unit->get_name();

		$is_variation = $unit instanceof WC_Product_Variation;
		$product_id   = $is_variation ? $unit->get_parent_id() : $unit->get_id();
		$variation_id = $is_variation ? $unit->get_id() : null;

		$description = self::description( $unit, $is_variation ? $product_id : null );
		$image_url   = self::image_url( $unit, $is_variation ? $product_id : null );
		$tags        = self::tags( $is_variation ? $product_id : $unit->get_id() );

		// Ingredients, skin types, size/volume and category live on the parent
		// product (their fields and taxonomies are set at product level), so both
		// simple products and variations read them from $product_id.
		$ingredients = Ingredients_Field::get( $product_id );
		$skin_types  = Skin_Type_Attribute::get_for_product( $product_id );
		$size        = Size_Volume_Field::get( $product_id );
		$category    = self::category( $product_id );
		$brand       = self::brand( $unit, $product_id );

		// Weight is native to WooCommerce; a variation inherits its parent's
		// weight when it has none of its own (WC_Product::get_weight handles that).
		$weight      = $unit->get_weight();
		$has_weight  = '' !== $weight && null !== $weight && is_numeric( $weight );
		$weight_unit = function_exists( 'get_option' ) ? (string) get_option( 'woocommerce_weight_unit' ) : '';

		$row = array(
			'woocommerce_product_id'   => (string) $product_id,
			'woocommerce_variation_id' => null !== $variation_id ? (string) $variation_id : null,
			'name'                     => $name,
			'description'              => '' !== $description ? $description : null,
			'brand'                    => $brand,
			'category'                 => $category,
			'sku'                      => $unit->get_sku() ?: null,
			'price'                    => (float) $price,
			'currency'                 => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : null,
			'stock_level'              => self::stock_level( $unit ),
			'weight'                   => $has_weight ? (float) $weight : null,
			'weight_unit'              => $has_weight && '' !== $weight_unit ? $weight_unit : null,
			'size_volume'              => $size['value'],
			'size_volume_unit'         => $size['unit'],
			'image_url'                => $image_url,
			'tags'                     => $tags,
			'ingredients'              => ! empty( $ingredients ) ? $ingredients : null,
			'skin_types'               => ! empty( $skin_types ) ? $skin_types : null,
		);

		// Preserve explicit nulls (nullable fields) but drop the key entirely
		// only when absent — array_filter with this callback keeps 0/0.0/'0'
		// (a genuinely free or out-of-stock product) and only strips null.
		return array_filter( $row, static fn( $value ) => null !== $value );
	}

	private static function description( WC_Product $unit, ?int $parent_id ): string {
		$text = (string) ( $unit->get_description() ?: $unit->get_short_description() );

		if ( '' === trim( $text ) && null !== $parent_id ) {
			$parent = function_exists( 'wc_get_product' ) ? wc_get_product( $parent_id ) : null;
			if ( $parent ) {
				$text = (string) ( $parent->get_description() ?: $parent->get_short_description() );
			}
		}

		return trim( wp_strip_all_tags( $text ) );
	}

	private static function image_url( WC_Product $unit, ?int $parent_id ): ?string {
		$image_id = $unit->get_image_id();
		if ( ! $image_id && null !== $parent_id ) {
			$parent   = function_exists( 'wc_get_product' ) ? wc_get_product( $parent_id ) : null;
			$image_id = $parent ? $parent->get_image_id() : 0;
		}

		if ( ! $image_id ) {
			return null;
		}

		$url = wp_get_attachment_image_url( (int) $image_id, 'full' );
		return is_string( $url ) && '' !== $url ? $url : null;
	}

	/**
	 * Brand name for a product. Defaults to WooCommerce's native brand taxonomy
	 * (`product_brand`, core since WooCommerce 9.6); the first assigned brand
	 * wins when several are set. The long-standing filter still applies on top,
	 * so stores on older WooCommerce or a third-party brand plugin can override.
	 */
	private static function brand( WC_Product $unit, int $product_id ): ?string {
		$brand = null;

		if ( taxonomy_exists( 'product_brand' ) ) {
			$terms = wp_get_post_terms( $product_id, 'product_brand', array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				$brand = (string) $terms[0];
			}
		}

		/**
		 * Filter the brand name attributed to a synced product. Defaults to the
		 * native `product_brand` taxonomy; stores using a different brand plugin
		 * (e.g. Perfect WooCommerce Brands) can hook this to override it.
		 *
		 * @param string|null $brand
		 * @param WC_Product  $unit The product or variation being synced.
		 */
		$brand = apply_filters( 'oyster_woocommerce_product_brand', $brand, $unit );

		return is_string( $brand ) && '' !== trim( $brand ) ? trim( $brand ) : null;
	}

	/**
	 * Primary category name for a product. WooCommerce products can carry
	 * several `product_cat` terms; Oyster stores a single category, so we send
	 * the most specific assigned term (the deepest in the tree), skipping the
	 * default "Uncategorized". The backend resolves the name to its taxonomy.
	 */
	private static function category( int $product_id ): ?string {
		$terms = wp_get_post_terms( $product_id, 'product_cat' );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}

		$default_id = function_exists( 'get_option' ) ? (int) get_option( 'default_product_cat' ) : 0;
		$best       = null;
		foreach ( $terms as $term ) {
			if ( 'uncategorized' === $term->slug || ( $default_id && (int) $term->term_id === $default_id ) ) {
				continue;
			}
			// Deeper term (larger parent chain) is more specific — prefer it.
			if ( null === $best || count( get_ancestors( $term->term_id, 'product_cat' ) ) > count( get_ancestors( $best->term_id, 'product_cat' ) ) ) {
				$best = $term;
			}
		}

		return $best ? (string) $best->name : null;
	}

	private static function tags( int $product_id ): ?string {
		$terms = wp_get_post_terms( $product_id, 'product_tag', array( 'fields' => 'names' ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}

		return implode( ', ', $terms );
	}

	/**
	 * Null (not tracked — the backend's `stock_level` is nullable) when the
	 * product doesn't manage stock at this level; 0 when out of stock without
	 * a tracked quantity; otherwise the tracked quantity, floored at 0 (a
	 * negative WooCommerce quantity — e.g. backorders — has no useful meaning
	 * for the widget's recommendation pool).
	 */
	private static function stock_level( WC_Product $unit ): ?int {
		if ( $unit->managing_stock() ) {
			$qty = $unit->get_stock_quantity();
			return null !== $qty ? max( 0, (int) $qty ) : null;
		}

		return $unit->is_in_stock() ? null : 0;
	}
}
