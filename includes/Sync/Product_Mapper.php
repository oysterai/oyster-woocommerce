<?php
/**
 * Maps WooCommerce products/variations to bulk-upsert catalog rows.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Sync;

use Oyster\Woo\Catalog\Ingredients_Field;
use Oyster\Woo\Catalog\Skin_Type_Attribute;
use WC_Product;
use WC_Product_Variation;

defined( 'ABSPATH' ) || exit;

/**
 * One Oyster catalog row per purchasable WooCommerce unit — mirrors the
 * backend's modelling (see BulkUpsertWooCommerceCatalog in skin-ai-api): a
 * simple product is one row with `woocommerce_variation_id: null`; a variable
 * product expands into one row per variation, each carrying both ids. The
 * parent product itself is never sent as a row for a variable product — only
 * its variations are purchasable.
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
	 * @return array<string, mixed>|null Null when the unit has no resolvable
	 *         name or price — sending it would fail the backend's `required`
	 *         validation for the whole batch, so we skip it here instead.
	 */
	private static function build_row( WC_Product $unit ): ?array {
		$price = $unit->get_price();
		if ( '' === $price || null === $price || ! is_numeric( $price ) ) {
			return null;
		}

		$name = $unit->get_name();
		if ( '' === trim( (string) $name ) ) {
			return null;
		}

		$is_variation = $unit instanceof WC_Product_Variation;
		$product_id   = $is_variation ? $unit->get_parent_id() : $unit->get_id();
		$variation_id = $is_variation ? $unit->get_id() : null;

		$description = self::description( $unit, $is_variation ? $product_id : null );
		$image_url   = self::image_url( $unit, $is_variation ? $product_id : null );
		$tags        = self::tags( $is_variation ? $product_id : $unit->get_id() );

		// Ingredients and skin types live on the parent product (the ingredient
		// field and the "Skin Type" attribute are set at product level), so both
		// simple products and variations read them from $product_id.
		$ingredients = Ingredients_Field::get( $product_id );
		$skin_types  = Skin_Type_Attribute::get_for_product( $product_id );

		$row = array(
			'woocommerce_product_id'   => (string) $product_id,
			'woocommerce_variation_id' => null !== $variation_id ? (string) $variation_id : null,
			'name'                     => $name,
			'description'              => '' !== $description ? $description : null,
			/**
			 * Filter the brand name attributed to a synced product. Core
			 * WooCommerce has no brand taxonomy — stores using a brand plugin
			 * (e.g. Perfect WooCommerce Brands) can hook this to surface it.
			 *
			 * @param string|null $brand
			 * @param WC_Product  $unit The product or variation being synced.
			 */
			'brand'                    => apply_filters( 'oyster_woocommerce_product_brand', null, $unit ),
			'sku'                      => $unit->get_sku() ?: null,
			'price'                    => (float) $price,
			'currency'                 => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : null,
			'stock_level'              => self::stock_level( $unit ),
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
