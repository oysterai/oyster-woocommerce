<?php
/** @package Oyster\Woo */
declare( strict_types=1 );
namespace Oyster\Woo\Wheel;
defined( 'ABSPATH' ) || exit;

final class Terms {
	/** Validate without coercing signed terms into a different reward. */
	public static function valid( array $terms ): bool {
		if ( ! is_string( $terms['reward_id'] ?? null ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,100}$/D', $terms['reward_id'] )
			|| ! is_string( $terms['code'] ?? null ) || ! preg_match( '/^[a-zA-Z0-9_-]{8,100}$/D', $terms['code'] )
			|| ! is_string( $terms['terms_hash'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $terms['terms_hash'] )
			|| ! is_string( $terms['currency'] ?? null ) || ! preg_match( '/^[A-Z]{3}$/D', $terms['currency'] )
			|| ! filter_var( $terms['email'] ?? '', FILTER_VALIDATE_EMAIL )
			|| ! is_int( $terms['usage_limit'] ?? null ) || $terms['usage_limit'] < 1 || $terms['usage_limit'] > 100
			|| ! in_array( $terms['type'] ?? '', array( 'percentage_discount', 'fixed_discount', 'free_item' ), true ) ) {
			return false;
		}
		if ( null !== ( $terms['expires_at'] ?? null ) && ( ! is_string( $terms['expires_at'] ) || false === strtotime( $terms['expires_at'] ) ) ) {
			return false;
		}
		$restrictions = $terms['restrictions'] ?? null;
		if ( ! is_array( $restrictions ) || array_diff( array_keys( $restrictions ), array( 'allowed_product_ids', 'excluded_product_ids', 'combines_with_discounts' ) ) ) {
			return false;
		}
		foreach ( array( 'allowed_product_ids', 'excluded_product_ids' ) as $key ) {
			if ( ! is_array( $restrictions[ $key ] ?? null ) ) {
				return false;
			}
			foreach ( $restrictions[ $key ] as $id ) {
				if ( ! self::product_id( $id ) ) {
					return false;
				}
			}
		}
		if ( ! is_bool( $restrictions['combines_with_discounts'] ?? null ) || ( isset( $terms['min_order_amount'] ) && ( ! is_numeric( $terms['min_order_amount'] ) || $terms['min_order_amount'] < 0 ) ) ) {
			return false;
		}
		if ( 'free_item' === $terms['type'] ) {
			return 1 === $terms['usage_limit'] && ! empty( $terms['expires_at'] ) && empty( $terms['min_order_amount'] )
				&& empty( $restrictions['allowed_product_ids'] ) && empty( $restrictions['excluded_product_ids'] ) && ! $restrictions['combines_with_discounts']
				&& self::product_id( $terms['product_id'] ?? null )
				&& ( ! isset( $terms['variant_id'] ) || self::product_id( $terms['variant_id'] ) );
		}
		if ( 'fixed_discount' === $terms['type'] && ( $restrictions['allowed_product_ids'] || $restrictions['excluded_product_ids'] ) ) {
			return false;
		}
		return is_numeric( $terms['value'] ?? null ) && $terms['value'] > 0
			&& ( 'percentage_discount' !== $terms['type'] || $terms['value'] <= 100 );
	}
	private static function product_id( $id ): bool {
		return ( is_int( $id ) || is_string( $id ) ) && ctype_digit( (string) $id ) && (int) $id > 0;
	}
}
