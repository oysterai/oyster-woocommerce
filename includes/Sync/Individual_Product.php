<?php
/**
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Sync;

use WC_Product;
use WC_Product_Variation;

defined( 'ABSPATH' ) || exit;

/**
 * Oyster recommends single items a shopper buys and uses. Bundles, grouped and external
 * products and gift cards match a merchant's category or tag filter all the same.
 */
final class Individual_Product {

	private const TYPES = array( 'simple', 'variable' );

	/** WooCommerce Gift Cards marks its cards as ordinary simple products with this meta. */
	private const GIFT_CARD_META = '_gift_card';

	public static function is_individual( WC_Product $product ): bool {
		$parent = $product;
		if ( $product instanceof WC_Product_Variation && function_exists( 'wc_get_product' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( ! $parent instanceof WC_Product ) {
				return false;
			}
		}

		// The save hooks also fire for drafts and private products, which the full import never sends.
		return 'publish' === $parent->get_status()
			&& in_array( $parent->get_type(), self::TYPES, true )
			&& 'yes' !== $parent->get_meta( self::GIFT_CARD_META );
	}
}
