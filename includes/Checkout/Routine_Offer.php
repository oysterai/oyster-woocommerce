<?php
/**
 * Tiered discount for buying several products from a scan's routine.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Checkout;

use Oyster\Woo\Api\Api_Exception;
use Oyster\Woo\Api\Client;
use Oyster\Woo\Support\Connection;
use Oyster\Woo\Wheel\Provisioner;
use WC_Cart;
use WC_Coupon;
use WC_Discounts;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Applied as a virtual coupon so the discount reaches the order, its emails and
 * the paid-order report the same way any coupon does, with nothing stored in
 * the coupon table. Only lines the handoff flagged as part of the routine earn
 * it, and only tiers Oyster quoted for that scan set its rate: a line added by
 * hand, or a code typed in at the cart, gets nothing.
 */
final class Routine_Offer {

	public const COUPON_CODE = 'oyster-routine-discount';

	public const CART_ITEM_FLAG = 'oyster_routine_offer';

	public const ORDER_ITEM_FLAG = '_oyster_routine_offer';

	public const SESSION_TIERS = 'oyster_routine_offer_tiers';

	private bool $syncing = false;

	public function __construct(
		private Connection $connection,
		private Client $client
	) {}

	public function register(): void {
		add_filter( 'woocommerce_get_shop_coupon_data', array( $this, 'coupon_data' ), 10, 2 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'is_valid' ), 10, 3 );
		add_filter( 'woocommerce_coupon_is_valid_for_product', array( $this, 'is_valid_for_product' ), 10, 4 );
		add_filter( 'woocommerce_cart_totals_coupon_label', array( $this, 'label' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'sync_coupon' ), 20 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'stamp_order_item' ), 10, 3 );
	}

	/**
	 * Null when there is no answer to act on; the caller carries on without an offer.
	 *
	 * @param int[] $product_ids Oyster product ids.
	 * @return array{tiers: array<int, array{minItems:int, percent:float}>, eligible_product_ids: int[]}|null
	 */
	public function quote( string $batch_id, array $product_ids ): ?array {
		$bearer = $this->connection->bearer();
		if ( ! $bearer || '' === $batch_id || ! $product_ids ) {
			return null;
		}

		try {
			$response = $this->client->quote_routine_offer( $bearer, $batch_id, $product_ids );
		} catch ( Api_Exception $e ) {
			$this->log( 'routine offer quote failed: ' . $e->user_message() );
			return null;
		}

		$data  = is_array( $response['data'] ?? null ) ? $response['data'] : array();
		$tiers = self::sanitize_tiers( $data['tiers'] ?? null );
		if ( null === $tiers ) {
			$this->log( 'routine offer quote was malformed' );
			return null;
		}

		$eligible = is_array( $data['eligible_product_ids'] ?? null ) ? array_map( 'absint', $data['eligible_product_ids'] ) : array();

		return array(
			'tiers'                => $tiers,
			'eligible_product_ids' => array_values( array_intersect( array_map( 'absint', $product_ids ), $eligible ) ),
		);
	}

	/**
	 * All or nothing: one bad tier means the quote cannot be trusted to rank the others.
	 *
	 * @param mixed $raw
	 * @return array<int, array{minItems:int, percent:float}>|null
	 */
	public static function sanitize_tiers( $raw ): ?array {
		if ( ! is_array( $raw ) || ! array_is_list( $raw ) ) {
			return null;
		}

		$tiers    = array();
		$previous = 0;
		foreach ( $raw as $tier ) {
			$min_items = is_array( $tier ) ? ( $tier['minItems'] ?? null ) : null;
			$percent   = is_array( $tier ) ? ( $tier['percent'] ?? null ) : null;

			if ( ! is_int( $min_items ) || $min_items <= $previous || ! ( is_int( $percent ) || is_float( $percent ) ) || $percent <= 0 || $percent > 100 ) {
				return null;
			}

			$tiers[]  = array(
				'minItems' => $min_items,
				'percent'  => (float) $percent,
			);
			$previous = $min_items;
		}

		return $tiers;
	}

	/**
	 * @param array<int, array{minItems:int, percent:float}> $tiers Ascending by minItems.
	 */
	public static function percent_for( array $tiers, int $routine_products ): float {
		$percent = 0.0;
		foreach ( $tiers as $tier ) {
			if ( $tier['minItems'] <= $routine_products ) {
				$percent = $tier['percent'];
			}
		}

		return $percent;
	}

	/**
	 * Distinct products, not units: three of one cleanser is not a routine.
	 *
	 * @param array<string, array<string, mixed>> $cart_contents
	 */
	public static function count_routine_products( array $cart_contents ): int {
		$products = array();
		foreach ( $cart_contents as $item ) {
			if ( empty( $item[ self::CART_ITEM_FLAG ] ) ) {
				continue;
			}
			$products[ (int) ( ( $item['variation_id'] ?? 0 ) ?: ( $item['product_id'] ?? 0 ) ) ] = true;
		}

		return count( $products );
	}

	/**
	 * @return array<int, array{minItems:int, percent:float}>|null
	 */
	public function session_tiers(): ?array {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}

		$tiers = WC()->session->get( self::SESSION_TIERS );

		return is_array( $tiers ) && $tiers ? $tiers : null;
	}

	/**
	 * @param array<int, array{minItems:int, percent:float}>|null $tiers
	 */
	public function remember_tiers( ?array $tiers ): void {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_TIERS, $tiers ?: null );
		}
	}

	public function percent( WC_Cart $cart ): float {
		$tiers = $this->session_tiers();

		return $tiers ? self::percent_for( $tiers, self::count_routine_products( $cart->get_cart_contents() ) ) : 0.0;
	}

	public function should_apply( WC_Cart $cart ): bool {
		return $this->percent( $cart ) > 0 && ! $this->has_overriding_coupon( $cart );
	}

	/**
	 * A wheel reward reaching the cart means the shopper took that offer instead,
	 * and an exclusive coupon is one the merchant chose not to stack.
	 */
	private function has_overriding_coupon( WC_Cart $cart ): bool {
		foreach ( $cart->get_applied_coupons() as $code ) {
			if ( self::COUPON_CODE === $code ) {
				continue;
			}
			$coupon = new WC_Coupon( $code );
			if ( $coupon->get_meta( Provisioner::META_REWARD ) || $coupon->get_individual_use() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Applied coupons are set directly rather than through apply_coupon(), which
	 * would show the shopper a notice and recalculate totals from inside this hook.
	 */
	public function sync_coupon( $cart ): void {
		if ( $this->syncing || ! $cart instanceof WC_Cart ) {
			return;
		}

		$this->syncing = true;
		try {
			$applied = $cart->get_applied_coupons();
			$present = in_array( self::COUPON_CODE, $applied, true );
			$wanted  = $this->should_apply( $cart );

			if ( $wanted && ! $present ) {
				$cart->set_applied_coupons( array_merge( $applied, array( self::COUPON_CODE ) ) );
			} elseif ( ! $wanted && $present ) {
				$cart->set_applied_coupons( array_values( array_diff( $applied, array( self::COUPON_CODE ) ) ) );
			}
		} finally {
			$this->syncing = false;
		}
	}

	/**
	 * @param mixed $data
	 * @param mixed $code
	 * @return mixed
	 */
	public function coupon_data( $data, $code ) {
		if ( ! self::is_code( $code ) ) {
			return $data;
		}

		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		return array(
			'discount_type'  => 'percent',
			'amount'         => $cart instanceof WC_Cart ? $this->percent( $cart ) : 0,
			'individual_use' => false,
			'description'    => __( 'Routine discount', 'oyster-woocommerce' ),
		);
	}

	/**
	 * Recalculating a placed order applies the coupon without validating it, so
	 * only a cart ever reaches the check below.
	 *
	 * @param mixed $discounts
	 */
	public function is_valid( bool $valid, $coupon, $discounts ): bool {
		if ( ! $coupon instanceof WC_Coupon || ! self::is_code( $coupon->get_code() ) ) {
			return $valid;
		}

		$cart = $discounts instanceof WC_Discounts ? $discounts->get_object() : null;

		return $valid && $cart instanceof WC_Cart && $this->should_apply( $cart );
	}

	/**
	 * Receives cart item values in a cart and the line item when a placed order is recalculated.
	 *
	 * @param mixed $product
	 * @param mixed $coupon
	 * @param mixed $values
	 */
	public function is_valid_for_product( bool $valid, $product, $coupon, $values ): bool {
		if ( ! $coupon instanceof WC_Coupon || ! self::is_code( $coupon->get_code() ) ) {
			return $valid;
		}

		if ( $values instanceof WC_Order_Item_Product ) {
			return 'yes' === $values->get_meta( self::ORDER_ITEM_FLAG );
		}

		return is_array( $values ) && ! empty( $values[ self::CART_ITEM_FLAG ] );
	}

	/**
	 * @param mixed $coupon
	 */
	public function label( string $label, $coupon ): string {
		if ( $coupon instanceof WC_Coupon && self::is_code( $coupon->get_code() ) ) {
			return esc_html__( 'Routine discount', 'oyster-woocommerce' );
		}

		return $label;
	}

	/**
	 * @param mixed $item
	 * @param mixed $cart_item_key
	 * @param mixed $values
	 */
	public function stamp_order_item( $item, $cart_item_key, $values ): void {
		if ( $item instanceof WC_Order_Item_Product && is_array( $values ) && ! empty( $values[ self::CART_ITEM_FLAG ] ) ) {
			$item->update_meta_data( self::ORDER_ITEM_FLAG, 'yes' );
		}
	}

	/**
	 * @param mixed $code
	 */
	private static function is_code( $code ): bool {
		return is_string( $code ) && self::COUPON_CODE === wc_strtolower( wc_format_coupon_code( $code ) );
	}

	private function log( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $message, array( 'source' => 'oyster-woocommerce' ) );
		}
	}
}
