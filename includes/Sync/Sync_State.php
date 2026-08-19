<?php
/**
 * Per-product Oyster sync state stored as post meta.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around two post-meta keys that record whether a WooCommerce
 * product has been successfully pushed to Oyster and what Oyster id it was
 * assigned. The meta is written after each successful upsert and cleared when
 * the product is deleted from Oyster.
 *
 * The `woocommerce_product_id` the backend uses as its lookup key is the
 * WooCommerce parent-product id. For simple products that's just the post id;
 * for variable products it's the parent, and each variation carries its own
 * `woocommerce_variation_id`. Both the parent and any variation end up as
 * separate Oyster product rows, so we store state on the parent post only —
 * the Products-list column shows per-parent status (which is what the merchant
 * sees in the list). Variation-level Oyster ids are not stored separately.
 */
final class Sync_State {

	/** Oyster product_id for the most recent successful sync (string). */
	public const META_OYSTER_ID  = '_oyster_product_id';

	/** Unix timestamp (int) of the most recent successful sync. */
	public const META_SYNCED_AT  = '_oyster_synced_at';

	/** Why the most recent sync attempt rejected this product (string). */
	public const META_ERROR      = '_oyster_sync_error';

	/** Unix timestamp (int) of the most recent failed attempt. */
	public const META_FAILED_AT  = '_oyster_failed_at';

	/**
	 * Persist the Oyster product_id and a timestamp for a WooCommerce product
	 * (identified by its parent/product post id — not a variation id).
	 *
	 * @param int    $woo_product_id WooCommerce parent product post id.
	 * @param string $oyster_id      Oyster product id (from the backend response).
	 * @param int    $synced_at      Unix timestamp of the sync.
	 */
	public static function mark_synced( int $woo_product_id, string $oyster_id, int $synced_at ): void {
		update_post_meta( $woo_product_id, self::META_OYSTER_ID, $oyster_id );
		update_post_meta( $woo_product_id, self::META_SYNCED_AT, $synced_at );

		// A product that just synced is no longer failing. Left behind, an old
		// reason would keep a fixed product looking broken until it happened to
		// fail again.
		delete_post_meta( $woo_product_id, self::META_ERROR );
		delete_post_meta( $woo_product_id, self::META_FAILED_AT );
	}

	/**
	 * Record why this product did not sync.
	 *
	 * The previous Oyster id is deliberately left in place: a product that
	 * synced last week and failed today is still live in Oyster with its old
	 * data, and saying otherwise would send the merchant looking for a product
	 * that is actually there.
	 *
	 * @param string $reason Message from the sync attempt, as shown to the merchant.
	 */
	public static function mark_failed( int $woo_product_id, string $reason, int $failed_at ): void {
		$reason = trim( $reason );

		update_post_meta(
			$woo_product_id,
			self::META_ERROR,
			'' !== $reason ? $reason : __( 'Oyster rejected this product without saying why.', 'oyster-woocommerce' )
		);
		update_post_meta( $woo_product_id, self::META_FAILED_AT, $failed_at );
	}

	/**
	 * Clear sync state when the product is removed from Oyster.
	 */
	public static function clear( int $woo_product_id ): void {
		delete_post_meta( $woo_product_id, self::META_OYSTER_ID );
		delete_post_meta( $woo_product_id, self::META_SYNCED_AT );
		delete_post_meta( $woo_product_id, self::META_ERROR );
		delete_post_meta( $woo_product_id, self::META_FAILED_AT );
	}

	/**
	 * @return array{oyster_id: string|null, synced_at: int|null, error: string|null, failed_at: int|null}
	 */
	public static function get( int $woo_product_id ): array {
		$oyster_id = get_post_meta( $woo_product_id, self::META_OYSTER_ID, true );
		$synced_at = get_post_meta( $woo_product_id, self::META_SYNCED_AT, true );
		$error     = get_post_meta( $woo_product_id, self::META_ERROR, true );
		$failed_at = get_post_meta( $woo_product_id, self::META_FAILED_AT, true );

		return array(
			'oyster_id' => is_string( $oyster_id ) && '' !== $oyster_id ? $oyster_id : null,
			'synced_at' => is_numeric( $synced_at ) ? (int) $synced_at : null,
			'error'     => is_string( $error ) && '' !== $error ? $error : null,
			'failed_at' => is_numeric( $failed_at ) ? (int) $failed_at : null,
		);
	}

	/**
	 * The three states a merchant can filter and sort by.
	 *
	 * A product that failed reports FAILED even when it synced successfully
	 * before: the older copy in Oyster is stale, and "synced" would hide the
	 * thing they need to act on.
	 */
	public const STATUS_SYNCED = 'synced';

	public const STATUS_FAILED = 'failed';

	public const STATUS_PENDING = 'pending';

	public static function status( int $woo_product_id ): string {
		$state = self::get( $woo_product_id );

		if ( null !== $state['error'] ) {
			return self::STATUS_FAILED;
		}

		return null !== $state['oyster_id'] ? self::STATUS_SYNCED : self::STATUS_PENDING;
	}

	/**
	 * Whether a product has a recorded Oyster sync.
	 */
	public static function is_synced( int $woo_product_id ): bool {
		return null !== self::get( $woo_product_id )['oyster_id'];
	}
}
