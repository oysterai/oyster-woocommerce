<?php
/**
 * "Oyster size / volume" product field.
 *
 * WooCommerce has native weight and dimensions but no volume field, and Oyster
 * models a product's size as a number + unit (e.g. 300 ml). This adds a small
 * merchant-editable size/volume field to the product editor and exposes it to
 * the catalog sync, mirroring the ingredients field.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Catalog;

use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the size as two postmeta values — `_oyster_size_volume` (a number) and
 * `_oyster_size_volume_unit` (a short unit string) — so each maps cleanly onto
 * the backend's `size_volume` / `size_volume_unit` columns. The WooCommerce
 * Product CSV Importer can populate them through `Meta: _oyster_size_volume`
 * and `Meta: _oyster_size_volume_unit` columns.
 */
final class Size_Volume_Field {

	/**
	 * Postmeta keys. Underscore-prefixed so they stay out of the generic Custom
	 * Fields metabox — the dedicated fields below are the only editor for them.
	 */
	public const META_VALUE = '_oyster_size_volume';
	public const META_UNIT  = '_oyster_size_volume_unit';

	public function register(): void {
		add_action( 'woocommerce_product_options_inventory_product_data', array( $this, 'render' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save' ) );
	}

	/**
	 * Render the value + unit inputs inside the Product data > Inventory panel.
	 * The WooCommerce field helpers read the current value from postmeta and
	 * escape output.
	 */
	public function render(): void {
		woocommerce_wp_text_input(
			array(
				'id'                => self::META_VALUE,
				'label'             => __( 'Oyster size / volume', 'oyster-woocommerce' ),
				'description'       => __( 'Product size as a number (e.g. 300). Oyster shows it and factors size into recommendations. Leave blank if not applicable.', 'oyster-woocommerce' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => 'any',
					'min'  => '0',
				),
				'placeholder'       => '300',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => self::META_UNIT,
				'label'       => __( 'Size / volume unit', 'oyster-woocommerce' ),
				'description' => __( 'Unit for the size above, e.g. ml, g, oz.', 'oyster-woocommerce' ),
				'desc_tip'    => true,
				'placeholder' => 'ml',
			)
		);
	}

	/**
	 * Persist the submitted value + unit onto the product object. WooCommerce
	 * verifies the product-editor nonce before firing
	 * `woocommerce_admin_process_product_object`, so we only sanitize here.
	 */
	public function save( WC_Product $product ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by WooCommerce before this hook.
		$raw_value = isset( $_POST[ self::META_VALUE ] ) ? wp_unslash( $_POST[ self::META_VALUE ] ) : '';
		$value     = is_scalar( $raw_value ) ? trim( (string) $raw_value ) : '';

		if ( '' === $value || ! is_numeric( $value ) ) {
			$product->delete_meta_data( self::META_VALUE );
		} else {
			// Store a normalized numeric string (drops stray whitespace/formatting).
			$product->update_meta_data( self::META_VALUE, (string) (float) $value );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
		$raw_unit = isset( $_POST[ self::META_UNIT ] ) ? wp_unslash( $_POST[ self::META_UNIT ] ) : '';
		$unit     = is_scalar( $raw_unit ) ? sanitize_text_field( (string) $raw_unit ) : '';
		// Cap at the backend column width (varchar(10)) so a paste can't fail the sync.
		$unit = '' === trim( $unit ) ? '' : substr( trim( $unit ), 0, 10 );

		if ( '' === $unit ) {
			$product->delete_meta_data( self::META_UNIT );
		} else {
			$product->update_meta_data( self::META_UNIT, $unit );
		}
	}

	/**
	 * The size for a product as a `['value' => float|null, 'unit' => string|null]`
	 * pair. Read by Product_Mapper when building catalog-sync rows. A value with
	 * no unit (or vice versa) is still returned — the backend accepts either
	 * column independently.
	 *
	 * @return array{value: float|null, unit: string|null}
	 */
	public static function get( int $product_id ): array {
		$raw_value = get_post_meta( $product_id, self::META_VALUE, true );
		$value     = is_numeric( $raw_value ) ? (float) $raw_value : null;

		$raw_unit = get_post_meta( $product_id, self::META_UNIT, true );
		$unit     = is_string( $raw_unit ) && '' !== trim( $raw_unit ) ? trim( $raw_unit ) : null;

		return array(
			'value' => $value,
			'unit'  => $unit,
		);
	}
}
