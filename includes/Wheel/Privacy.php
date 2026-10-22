<?php
/** @package Oyster\Woo */
declare( strict_types=1 );
namespace Oyster\Woo\Wheel;
defined( 'ABSPATH' ) || exit;

final class Privacy {
	private const PAGE_SIZE = 100;

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_erasers', function ( array $erasers ): array {
			$erasers['oyster-wheel-rewards'] = array(
				'eraser_friendly_name' => __( 'Oyster wheel rewards', 'oyster-woocommerce' ),
				'callback' => array( $this, 'erase_data' ),
			);
			return $erasers;
		} );
	}

	public function erase_data( string $email, int $page = 1 ): array {
		global $wpdb;
		// Keep the grant rows as tombstones, so pagination stays stable and an
		// issuance retry cannot recreate a reward after personal-data erasure.
		$keys = '' === trim( $email ) ? array() : $wpdb->get_col( $wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id LIMIT %d OFFSET %d",
			$wpdb->esc_like( 'oyster_wheel_grant_' ) . '%', self::PAGE_SIZE, ( max( 1, $page ) - 1 ) * self::PAGE_SIZE
		) );
		$removed = false;
		foreach ( $keys as $key ) {
			$removed = Lock::run( $key, function () use ( $wpdb, $key, $email ): bool {
				$grant = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) ) );
				if ( ! is_array( $grant ) || 0 !== strcasecmp( trim( $email ), $grant['terms']['email'] ?? '' ) ) {
					return false;
				}
				if ( 'free_item' === $grant['terms']['type'] ) {
					Lock::run( 'gift-' . $grant['result']['external_id'], function () use ( $grant ): void {
						$order = wc_get_order( (int) $grant['result']['external_id'] );
						if ( $order ) {
							$order->delete_meta_data( Provisioner::META_TERMS );
							if ( ! $order->is_paid() ) {
								wc_release_stock_for_order( $order );
								$order->set_status( 'cancelled' );
							}
							$order->save();
						}
					} );
				} else {
					( new \WC_Coupon( (int) $grant['result']['external_id'] ) )->delete( true );
				}
				$grant['terms']['email'] = '';
				$grant['terms']['code'] = '';
				$grant['result']['redemption_url'] = null;
				$grant['result']['status'] = 'revoked';
				$grant['erased'] = true;
				if ( false === $wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $grant ) ), array( 'option_name' => $key ) ) ) {
					throw new \RuntimeException( 'reward_erasure_failed' );
				}
				wp_cache_delete( $key, 'options' );
				return true;
			} ) || $removed;
		}
		return array( 'items_removed' => $removed, 'items_retained' => false, 'messages' => array(), 'done' => count( $keys ) < self::PAGE_SIZE );
	}
}
