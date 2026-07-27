<?php
/**
 * "Oyster ingredients" product field.
 *
 * Adds a merchant-editable ingredient list to the WooCommerce product editor
 * and exposes it to the catalog sync. Ingredients are the strongest signal
 * Oyster uses to match products to a shopper's skin, mirroring the same
 * "Oyster ingredients" field Oyster's Shopify integration exposes.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Catalog;

use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the ingredient list as newline-separated text in `_oyster_ingredients`
 * postmeta (one ingredient per line). The catalog sync reads it via get() and
 * sends it as an array; the WooCommerce Product CSV Importer can populate it
 * through a `Meta: _oyster_ingredients` column.
 */
final class Ingredients_Field {

	/**
	 * Postmeta key. Underscore-prefixed so it stays out of the generic Custom
	 * Fields metabox — the dedicated field below is the only editor for it.
	 */
	public const META_KEY = '_oyster_ingredients';

	public function register(): void {
		add_action( 'woocommerce_product_options_inventory_product_data', array( $this, 'render' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save' ) );
	}

	/**
	 * Render the textarea inside the Product data > Inventory panel. Uses the
	 * WooCommerce field helper, which reads the current value from postmeta and
	 * escapes output.
	 */
	public function render(): void {
		woocommerce_wp_textarea_input(
			array(
				'id'          => self::META_KEY,
				'label'       => __( 'Oyster ingredients', 'oyster-woocommerce' ),
				'description' => __( 'One ingredient per line. Oyster reads this list to recommend the product to the shoppers it suits and to steer others away from ingredients that don\'t.', 'oyster-woocommerce' ),
				'desc_tip'    => true,
				'rows'        => 6,
				'placeholder' => "Aqua\nGlycerin\nNiacinamide",
			)
		);
	}

	/**
	 * Persist the submitted value onto the product object. WooCommerce verifies
	 * the product-editor nonce before firing `woocommerce_admin_process_product_object`,
	 * so we only sanitize here.
	 */
	public function save( WC_Product $product ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by WooCommerce before this hook.
		if ( ! isset( $_POST[ self::META_KEY ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
		$value = sanitize_textarea_field( wp_unslash( $_POST[ self::META_KEY ] ) );

		if ( '' === trim( $value ) ) {
			$product->delete_meta_data( self::META_KEY );
			return;
		}

		$product->update_meta_data( self::META_KEY, $value );
	}

	/**
	 * The ingredient list for a product, one entry per non-empty line. Read by
	 * Product_Mapper when building catalog-sync rows.
	 *
	 * @return array<int, string>
	 */
	public static function get( int $product_id ): array {
		$raw = get_post_meta( $product_id, self::META_KEY, true );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}

		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		if ( ! is_array( $lines ) ) {
			return array();
		}

		$lines = array_map( 'trim', $lines );

		return array_values( array_filter( $lines, static fn( string $line ): bool => '' !== $line ) );
	}
}
