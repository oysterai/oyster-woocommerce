<?php
/**
 * Adds an "Oyster" column to the WooCommerce Products list table showing
 * per-product sync state.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Admin;

use Oyster\Woo\Sync\Sync_State;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a compact "Oyster" column to WooCommerce > Products (edit.php?post_type=product).
 * Shows a green checkmark with a "synced X ago" tooltip when a product has been
 * pushed to Oyster, or a dash when it hasn't. Merchants can spot at a glance
 * which products are live in Oyster without opening each one.
 */
final class Sync_Status_Column {

	public function register(): void {
		// Classic product list table (HPOS disabled or legacy list).
		add_filter( 'manage_product_posts_columns', array( $this, 'add_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_cell' ), 10, 2 );

		// HPOS product list table (WooCommerce > Products when HPOS enabled).
		add_filter( 'woocommerce_product_list_table_columns', array( $this, 'add_column' ) );
		add_action( 'woocommerce_product_list_table_custom_column', array( $this, 'render_cell' ), 10, 2 );

		add_action( 'admin_head', array( $this, 'inline_styles' ) );
	}

	/**
	 * Append the Oyster column after the stock column if present, otherwise at
	 * the end (before the actions/date columns).
	 *
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function add_column( array $columns ): array {
		$insert_after = 'product_tag';
		$new          = array( 'oyster_sync' => '<span title="' . esc_attr__( 'Oyster sync status', 'oyster-woocommerce' ) . '">&#x1F9AA;</span>' );

		if ( array_key_exists( $insert_after, $columns ) ) {
			$pos    = array_search( $insert_after, array_keys( $columns ), true );
			$before = array_slice( $columns, 0, $pos + 1, true );
			$after  = array_slice( $columns, $pos + 1, null, true );
			return array_merge( $before, $new, $after );
		}

		return array_merge( $columns, $new );
	}

	/**
	 * @param string     $column     Column key.
	 * @param int|object $product_or_id Post id (classic list) or product object (HPOS list).
	 */
	public function render_cell( string $column, $product_or_id ): void {
		if ( 'oyster_sync' !== $column ) {
			return;
		}

		$product_id = is_object( $product_or_id ) && method_exists( $product_or_id, 'get_id' )
			? (int) $product_or_id->get_id()
			: (int) $product_or_id;

		$state = Sync_State::get( $product_id );

		if ( null === $state['oyster_id'] ) {
			echo '<span class="oyster-sync-cell oyster-sync--no" aria-label="' . esc_attr__( 'Not synced to Oyster', 'oyster-woocommerce' ) . '">—</span>';
			return;
		}

		$ago = human_time_diff( (int) $state['synced_at'] );
		/* translators: %s: human-readable time ago */
		$tooltip = sprintf( __( 'Synced to Oyster %s ago', 'oyster-woocommerce' ), $ago );

		echo '<span class="oyster-sync-cell oyster-sync--ok" title="' . esc_attr( $tooltip ) . '" aria-label="' . esc_attr( $tooltip ) . '">✓</span>';
	}

	public function inline_styles(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'edit-product', 'woocommerce_page_product-list' ), true ) ) {
			return;
		}
		echo '<style>
.column-oyster_sync{width:3rem;text-align:center}
.oyster-sync-cell{font-size:1rem;line-height:1}
.oyster-sync--ok{color:#00a32a}
.oyster-sync--no{color:#aaa}
</style>';
	}
}
