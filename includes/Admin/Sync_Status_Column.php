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
 * Adds a compact "Oyster" column to WooCommerce > Products (edit.php?post_type=product),
 * and a filter beside Woo's own so the list can be narrowed to one sync state.
 *
 * Three states, not two. A product Oyster rejected is neither synced nor
 * untouched, and the reason it gave is shown here rather than left in a log —
 * a merchant seeing "3 failed" on the Catalog screen has no way to find which
 * three, or to know that the fix is a missing price on one of them.
 */
final class Sync_Status_Column {

	/** Query string key the filter reads and writes. */
	private const QUERY_VAR = 'oyster_sync_status';

	/** The two product list screens this attaches to. */
	private const SCREENS = array( 'edit-product', 'woocommerce_page_product-list' );

	public function register(): void {
		// Classic product list table (HPOS disabled or legacy list).
		add_filter( 'manage_product_posts_columns', array( $this, 'add_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_cell' ), 10, 2 );

		// HPOS product list table (WooCommerce > Products when HPOS enabled).
		add_filter( 'woocommerce_product_list_table_columns', array( $this, 'add_column' ) );
		add_action( 'woocommerce_product_list_table_custom_column', array( $this, 'render_cell' ), 10, 2 );

		// Filter control + the query it drives. `restrict_manage_posts` is the
		// classic list and is what the great majority of stores render.
		add_action( 'restrict_manage_posts', array( $this, 'render_filter' ) );
		// Named by analogy with the two column hooks above, for WooCommerce's own
		// product list table. If the hook is absent the action simply never runs
		// and that screen shows no filter — the column still works, and the
		// classic list is unaffected.
		add_action( 'woocommerce_product_list_table_restrict_manage_products', array( $this, 'render_filter' ) );
		add_filter( 'request', array( $this, 'apply_filter_to_query' ) );

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

		// A rejection outranks an earlier success: the copy in Oyster is stale,
		// and showing a tick would hide the thing the merchant needs to fix.
		if ( null !== $state['error'] ) {
			$when = null !== $state['failed_at']
				/* translators: %s: human-readable time ago */
				? sprintf( __( 'Failed %s ago', 'oyster-woocommerce' ), human_time_diff( $state['failed_at'] ) )
				: __( 'Failed to sync', 'oyster-woocommerce' );

			$tooltip = $when . ' — ' . $state['error'];

			printf(
				'<span class="oyster-sync-cell oyster-sync--fail" title="%s" aria-label="%s">✕</span>',
				esc_attr( $tooltip ),
				esc_attr( $tooltip )
			);

			return;
		}

		if ( null === $state['oyster_id'] ) {
			echo '<span class="oyster-sync-cell oyster-sync--no" aria-label="' . esc_attr__( 'Not synced to Oyster', 'oyster-woocommerce' ) . '">—</span>';
			return;
		}

		$ago = human_time_diff( (int) $state['synced_at'] );
		/* translators: %s: human-readable time ago */
		$tooltip = sprintf( __( 'Synced to Oyster %s ago', 'oyster-woocommerce' ), $ago );

		echo '<span class="oyster-sync-cell oyster-sync--ok" title="' . esc_attr( $tooltip ) . '" aria-label="' . esc_attr( $tooltip ) . '">✓</span>';
	}

	/**
	 * The filter control, rendered beside WooCommerce's own product filters.
	 */
	public function render_filter(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, self::SCREENS, true ) ) {
			return;
		}

		// Read-only screen state driving a list filter, exactly as Woo's own
		// category and type filters are read.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';

		$options = array(
			''                          => __( 'Oyster sync — all', 'oyster-woocommerce' ),
			Sync_State::STATUS_SYNCED   => __( 'Synced to Oyster', 'oyster-woocommerce' ),
			Sync_State::STATUS_FAILED   => __( 'Failed to sync', 'oyster-woocommerce' ),
			Sync_State::STATUS_PENDING  => __( 'Not synced yet', 'oyster-woocommerce' ),
		);

		printf( '<select name="%s" id="%s">', esc_attr( self::QUERY_VAR ), esc_attr( self::QUERY_VAR ) );
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Translate the chosen state into a meta query.
	 *
	 * Hooked on `request` rather than `pre_get_posts` so it applies to the one
	 * list-table query and not to every product query the page happens to run.
	 *
	 * "Synced" deliberately excludes anything carrying an error, so the three
	 * options partition the catalogue instead of overlapping.
	 *
	 * @param array<string, mixed> $query_vars
	 * @return array<string, mixed>
	 */
	public function apply_filter_to_query( array $query_vars ): array {
		if ( ! is_admin() ) {
			return $query_vars;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';

		if ( '' === $requested ) {
			return $query_vars;
		}

		$has_id    = array( 'key' => Sync_State::META_OYSTER_ID, 'compare' => 'EXISTS' );
		$no_id     = array( 'key' => Sync_State::META_OYSTER_ID, 'compare' => 'NOT EXISTS' );
		$has_error = array( 'key' => Sync_State::META_ERROR, 'compare' => 'EXISTS' );
		$no_error  = array( 'key' => Sync_State::META_ERROR, 'compare' => 'NOT EXISTS' );

		switch ( $requested ) {
			case Sync_State::STATUS_SYNCED:
				$clauses = array( $has_id, $no_error );
				break;
			case Sync_State::STATUS_FAILED:
				$clauses = array( $has_error );
				break;
			case Sync_State::STATUS_PENDING:
				$clauses = array( $no_id, $no_error );
				break;
			default:
				return $query_vars;
		}

		// Merged, never assigned over. WooCommerce puts its own stock and
		// product-type filters in here, and replacing the array would silently
		// drop whichever of those the merchant had also selected.
		$existing = isset( $query_vars['meta_query'] ) && is_array( $query_vars['meta_query'] )
			? $query_vars['meta_query']
			: array();

		$query_vars['meta_query'] = array_merge(
			$existing,
			$clauses,
			array( 'relation' => 'AND' )
		);

		return $query_vars;
	}

	public function inline_styles(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, self::SCREENS, true ) ) {
			return;
		}
		echo '<style>
.column-oyster_sync{width:3rem;text-align:center}
.oyster-sync-cell{font-size:1rem;line-height:1}
.oyster-sync--ok{color:#00a32a}
.oyster-sync--fail{color:#d63638}
.oyster-sync--no{color:#aaa}
</style>';
	}
}
