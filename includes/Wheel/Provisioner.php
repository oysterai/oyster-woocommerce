<?php
/** @package Oyster\Woo */
declare( strict_types=1 );
namespace Oyster\Woo\Wheel;

use Oyster\Woo\Support\Connection;
use WC_Coupon;
defined( 'ABSPATH' ) || exit;

final class Provisioner {
	public const META_REWARD = '_oyster_wheel_reward';
	public const META_TERMS = '_oyster_wheel_terms';
	public const META_VENDOR = '_oyster_wheel_vendor';
	public function __construct( private Connection $connection ) {}

	public function key( string $reward_id ): string {
		return 'oyster_wheel_grant_' . hash( 'sha256', $this->connection->vendor_id() . ':' . $reward_id );
	}

	public function grant( string $reward_id ): ?array {
		global $wpdb;
		// A persistent object cache can retain an option written by a rolled-back request.
		$value = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $this->key( $reward_id ) ) ) );
		return is_array( $value ) ? $value : null;
	}

	public function provision( array $terms ): array {
		return Lock::run( $this->key( $terms['reward_id'] ), function () use ( $terms ): array {
			$existing = $this->grant( $terms['reward_id'] );
			if ( $existing ) {
				if ( ! empty( $existing['erased'] ) ) {
					throw new \InvalidArgumentException( 'reward_unavailable' );
				}
				if ( ! hash_equals( $existing['terms']['terms_hash'], $terms['terms_hash'] ) || ! hash_equals( $existing['terms']['code'], $terms['code'] ) ) {
					throw new \InvalidArgumentException( 'reward_terms_changed' );
				}
				return $existing['result'];
			}
			if ( ! Terms::valid( $terms ) || ! Storage::supports_transactions() || ( ! empty( $terms['expires_at'] ) && strtotime( $terms['expires_at'] ) <= time() ) ) {
				throw new \InvalidArgumentException( 'reward_unavailable' );
			}
			if ( ! empty( $terms['currency'] ) && strtoupper( $terms['currency'] ) !== get_woocommerce_currency() ) {
				throw new \InvalidArgumentException( 'reward_unavailable' );
			}
			global $wpdb;
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException( 'reward_storage_unavailable' );
			}
			try {
				if ( 'free_item' === $terms['type'] ) {
					$result = ( new Gift( $this->connection, $this ) )->reserve( $terms );
				} else {
					$result = $this->coupon( $terms );
				}
				if ( false === $wpdb->insert( $wpdb->options, array( 'option_name' => $this->key( $terms['reward_id'] ), 'option_value' => maybe_serialize( array( 'terms' => $terms, 'result' => $result ) ), 'autoload' => 'no' ), array( '%s', '%s', '%s' ) ) ) {
					throw new \RuntimeException( 'reward_save_failed' );
				}
				if ( false === $wpdb->query( 'COMMIT' ) ) {
					throw new \RuntimeException( 'reward_commit_unconfirmed' );
				}
				return $result;
			} catch ( \Throwable $e ) {
				$wpdb->query( 'ROLLBACK' );
				wp_cache_delete( $this->key( $terms['reward_id'] ), 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				throw $e;
			}
		} );
	}

	private function coupon( array $terms ): array {
		if ( ! wc_coupons_enabled() || ( 'free_delivery' === $terms['type'] && ! Free_Delivery::available() ) ) {
			throw new \InvalidArgumentException( 'reward_unavailable' );
		}
		if ( \WC_Data_Store::load( 'coupon' )->get_ids_by_code( $terms['code'] ) ) {
			throw new \InvalidArgumentException( 'coupon_code_conflict' );
		}
		$coupon = new WC_Coupon();
		foreach ( array_merge( $terms['restrictions']['allowed_product_ids'], $terms['restrictions']['excluded_product_ids'] ) as $id ) {
			if ( ! wc_get_product( (int) $id ) ) {
				throw new \InvalidArgumentException( 'reward_unavailable' );
			}
		}
		$coupon->set_code( $terms['code'] );
		$coupon->set_discount_type( 'percentage_discount' === $terms['type'] ? 'percent' : 'fixed_cart' );
		$coupon->set_amount( 'free_delivery' === $terms['type'] ? 0 : $terms['value'] );
		$coupon->set_free_shipping( 'free_delivery' === $terms['type'] );
		$coupon->set_usage_limit( $terms['usage_limit'] );
		$coupon->set_usage_limit_per_user( $terms['usage_limit'] );
		$coupon->set_email_restrictions( array( strtolower( $terms['email'] ) ) );
		$coupon->set_individual_use( ! $terms['restrictions']['combines_with_discounts'] );
		$coupon->set_product_ids( array_map( 'intval', $terms['restrictions']['allowed_product_ids'] ) );
		$coupon->set_excluded_product_ids( array_map( 'intval', $terms['restrictions']['excluded_product_ids'] ) );
		$coupon->set_minimum_amount( $terms['min_order_amount'] ?? 0 );
		$coupon->set_date_expires( ! empty( $terms['expires_at'] ) ? strtotime( $terms['expires_at'] ) : null );
		$coupon->set_description( 'Oyster wheel reward' );
		$coupon->update_meta_data( self::META_REWARD, $terms['reward_id'] );
		$coupon->update_meta_data( self::META_TERMS, $terms );
		$coupon->update_meta_data( self::META_VENDOR, $this->connection->vendor_id() );
		if ( ! $coupon->save() ) {
			throw new \RuntimeException( 'coupon_save_failed' );
		}
		return array( 'status' => 'ready', 'external_id' => (string) $coupon->get_id(), 'redemption_url' => null );
	}

	public function apply( $claim ): bool {
		if ( ! is_array( $claim ) || 'woocommerce' !== ( $claim['redemption_authority'] ?? '' ) || ! is_string( $claim['reward_id'] ?? null ) || ! is_string( $claim['claim_token'] ?? null ) ) {
			return false;
		}
		$grant = $this->grant( $claim['reward_id'] );
		if ( ! $grant || ! empty( $grant['erased'] ) || ! hash_equals( $grant['terms']['code'], $claim['claim_token'] ) || 'free_item' === $grant['terms']['type'] ) {
			return false;
		}
		$coupon = new WC_Coupon( (int) $grant['result']['external_id'] );
		if ( $claim['reward_id'] !== $coupon->get_meta( self::META_REWARD ) || $this->connection->vendor_id() !== (int) $coupon->get_meta( self::META_VENDOR ) ) {
			return false;
		}
		wc_load_cart();
		if ( ! WC()->cart || ! WC()->customer ) {
			return false;
		}
		$email = strtolower( $grant['terms']['email'] );
		$guest = ! is_user_logged_in() && ! WC()->customer->get_id();
		if ( ! $guest && strtolower( wp_get_current_user()->user_email ) !== $email ) {
			return false;
		}
		$previous_email = WC()->customer->get_billing_email();
		$applied = false;
		try {
			// Native coupon validation runs before checkout collects a guest's email.
			if ( $guest ) {
				WC()->customer->set_billing_email( $email );
			}
			$applied = WC()->cart->has_discount( $coupon->get_code() ) || WC()->cart->apply_coupon( $coupon->get_code() );
			return $applied;
		} finally {
			if ( $guest ) {
				WC()->customer->set_billing_email( $applied ? $email : $previous_email );
				WC()->customer->save();
			}
		}
	}
}
