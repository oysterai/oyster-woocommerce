<?php
/** @package Oyster\Woo */
declare( strict_types=1 );
namespace Oyster\Woo\Wheel;
defined( 'ABSPATH' ) || exit;

final class Free_Delivery {
	public const META_SAVING = '_oyster_wheel_delivery_saving';
	public function register(): void {
		add_filter( 'woocommerce_package_rates', array( $this, 'prefer_free_rates' ), 100 );
	}

	/** Every zone that offers delivery must honor the coupon without extra spend rules. */
	public static function available(): bool {
		$zones = \WC_Shipping_Zones::get_zones();
		$zones[] = array( 'zone_id' => 0 );
		$delivery_available = false;
		foreach ( $zones as $zone ) {
			$delivery = false;
			$coupon_shipping = false;
			foreach ( ( new \WC_Shipping_Zone( (int) $zone['zone_id'] ) )->get_shipping_methods( true ) as $method ) {
				if ( 'local_pickup' !== $method->id ) {
					$delivery = true;
				}
				if ( 'free_shipping' === $method->id && in_array( $method->get_option( 'requires' ), array( 'coupon', 'either' ), true ) ) {
					$coupon_shipping = true;
				}
			}
			if ( $delivery && ! $coupon_shipping ) {
				return false;
			}
			$delivery_available = $delivery_available || $delivery;
		}
		return $delivery_available;
	}

	public function prefer_free_rates( array $rates ): array {
		if ( ! WC()->cart ) {
			return $rates;
		}
		$has_reward = false;
		foreach ( WC()->cart->get_coupons() as $coupon ) {
			$terms = $coupon->get_meta( Provisioner::META_TERMS );
			if ( is_array( $terms ) && 'free_delivery' === ( $terms['type'] ?? '' ) && $coupon->get_meta( Provisioner::META_REWARD )
				&& $coupon->get_free_shipping() && true === ( new \WC_Discounts( WC()->cart ) )->is_coupon_valid( $coupon ) ) {
				$has_reward = true;
				break;
			}
		}
		if ( ! $has_reward ) {
			return $rates;
		}
		$free = array_filter( $rates, static fn( $rate ) => 'free_shipping' === $rate->get_method_id() && 0.0 === (float) $rate->get_cost() );
		if ( ! $free ) {
			// Preserve unavailable-destination behavior; never invent a delivery service.
			return $rates;
		}
		// The shopper's pick among the hidden paid rates is unknown, so the saving
		// reported for the reward is the least they would have paid for delivery.
		$paid = array();
		foreach ( $rates as $rate ) {
			if ( ! in_array( $rate->get_method_id(), array( 'free_shipping', 'local_pickup', 'pickup_location' ), true ) && (float) $rate->get_cost() > 0 ) {
				$paid[] = (float) $rate->get_cost();
			}
		}
		if ( $paid ) {
			// Rate meta is copied onto the order's shipping line at checkout.
			foreach ( $free as $rate ) {
				$rate->add_meta_data( self::META_SAVING, wc_format_decimal( min( $paid ), wc_get_price_decimals() ) );
			}
		}
		return $free;
	}
}
