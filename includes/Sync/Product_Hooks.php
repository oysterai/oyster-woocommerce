<?php
/**
 * WooCommerce product lifecycle hooks -> catalog sync.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Registers on the WooCommerce hooks that fire when a product changes, and
 * enqueues the corresponding Catalog_Sync Action Scheduler job. Never calls
 * the API inline — a product save (admin, REST, WP-CLI import, ...) must
 * never block on an outbound HTTP call.
 */
final class Product_Hooks {

	public function __construct( private Catalog_Sync $sync ) {}

	public function register(): void {
		// Fires on every simple/variable product save, including creation —
		// covers admin edits, REST API writes, and programmatic imports alike.
		add_action( 'woocommerce_new_product', array( $this, 'on_product_saved' ) );
		add_action( 'woocommerce_update_product', array( $this, 'on_product_saved' ) );

		add_action( 'woocommerce_new_product_variation', array( $this, 'on_variation_saved' ) );
		add_action( 'woocommerce_update_product_variation', array( $this, 'on_variation_saved' ) );

		// Trash and permanent delete both need to archive — a merchant may
		// empty the trash without ever having relied on the trash step.
		add_action( 'wp_trash_post', array( $this, 'on_post_removed' ) );
		add_action( 'before_delete_post', array( $this, 'on_post_removed' ) );
	}

	public function on_product_saved( int $product_id ): void {
		$this->sync->enqueue_sync( $product_id );
	}

	/**
	 * A variation changed; re-sync its parent so the whole variation set
	 * refreshes together (Product_Mapper expands a variable product into one
	 * row per variation from a single parent fetch).
	 */
	public function on_variation_saved( int $variation_id ): void {
		$parent_id = $this->parent_product_id( $variation_id );
		if ( $parent_id ) {
			$this->sync->enqueue_sync( $parent_id );
		}
	}

	public function on_post_removed( int $post_id ): void {
		$post_type = get_post_type( $post_id );

		if ( 'product' === $post_type ) {
			$this->sync->enqueue_delete( $post_id );
			return;
		}

		if ( 'product_variation' === $post_type ) {
			// A single variation was trashed/deleted while its parent product
			// survives. There's no variation-level delete on the backend —
			// deletion is product-level only, by design. Re-syncing the parent
			// refreshes its remaining variations, but the removed variation's
			// Oyster row isn't pruned until the parent product itself is later
			// deleted. Acceptable for v1; revisit if stale single-variation
			// rows turn out to be a real merchant complaint.
			$parent_id = $this->parent_product_id( $post_id );
			if ( $parent_id ) {
				$this->sync->enqueue_sync( $parent_id );
			}
		}
	}

	private function parent_product_id( int $variation_id ): int {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return 0;
		}

		$variation = wc_get_product( $variation_id );
		if ( ! $variation ) {
			return 0;
		}

		return (int) $variation->get_parent_id();
	}
}
