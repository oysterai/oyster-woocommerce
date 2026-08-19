<?php
/**
 * Catalog sync: pushes WooCommerce products to Oyster.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Sync;

use Oyster\Woo\Api\Api_Exception;
use Oyster\Woo\Api\Client;
use Oyster\Woo\Support\Catalog_Filter;
use Oyster\Woo\Support\Connection;
use Oyster\Woo\Sync\Sync_State;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Two sync paths, both landing on the same bulk-upsert/delete endpoints:
 *
 *   - Incremental: WooCommerce product hooks (see Product_Hooks) enqueue an
 *     Action Scheduler job per changed/removed product, so a page save never
 *     blocks on an HTTP call to Oyster.
 *   - Full import: a merchant-triggered, self-chaining Action Scheduler job
 *     that pages through the catalog (see run_import_batch) — each page is
 *     its own bounded AS task rather than one long-running request.
 *
 * Action Scheduler ships bundled with WooCommerce, so `as_*()` is available
 * once WooCommerce is active; the function_exists guards are defensive only.
 *
 * Both paths gate on Catalog_Filter::is_eligible() before mapping a product
 * to rows — a vendor may scope sync to specific categories/tags, so "changed"
 * or "every published product" doesn't necessarily mean "in scope."
 */
final class Catalog_Sync {

	public const HOOK_SYNC_PRODUCT = 'oyster_woo_sync_product';

	public const HOOK_DELETE_PRODUCT = 'oyster_woo_delete_product';

	public const HOOK_IMPORT_BATCH = 'oyster_woo_import_batch';

	public const ACTION_GROUP = 'oyster-woocommerce';

	public const STATUS_OPTION = 'oyster_woocommerce_sync_status';

	/** Products per import page (each may expand into several variation rows). */
	private const IMPORT_PAGE_SIZE = 20;

	/** Rows per bulk-upsert call — stays under the backend's 250-row cap. */
	private const UPSERT_CHUNK_SIZE = 100;

	public function __construct(
		private Connection $connection,
		private Client $client
	) {}

	public function register(): void {
		add_action( self::HOOK_SYNC_PRODUCT, array( $this, 'sync_product' ) );
		add_action( self::HOOK_DELETE_PRODUCT, array( $this, 'delete_product' ) );
		add_action( self::HOOK_IMPORT_BATCH, array( $this, 'run_import_batch' ) );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Enqueue (called from Product_Hooks / the admin Catalog screen)
	 * -----------------------------------------------------------------------
	 */

	public function enqueue_sync( int $product_id ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		as_enqueue_async_action( self::HOOK_SYNC_PRODUCT, array( 'product_id' => $product_id ), self::ACTION_GROUP );
	}

	public function enqueue_delete( int $product_id ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		as_enqueue_async_action( self::HOOK_DELETE_PRODUCT, array( 'product_id' => $product_id ), self::ACTION_GROUP );
	}

	/**
	 * Kick off a full catalog import, unless one is already running (checked
	 * via the AS queue itself, not just our status flag, so a stuck/crashed
	 * run can't permanently block re-triggering).
	 */
	public function enqueue_full_import(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}
		if ( as_next_scheduled_action( self::HOOK_IMPORT_BATCH, null, self::ACTION_GROUP ) ) {
			return;
		}

		$this->update_status(
			array(
				'full_import_running'    => true,
				'full_import_started_at' => time(),
				'full_import_totals'     => array(
					'created' => 0,
					'updated' => 0,
					'claimed' => 0,
					'failed'  => 0,
				),
			)
		);
		as_enqueue_async_action( self::HOOK_IMPORT_BATCH, array( 'page' => 1 ), self::ACTION_GROUP );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Action Scheduler callbacks
	 * -----------------------------------------------------------------------
	 */

	public function sync_product( int $product_id ): void {
		if ( ! $this->connection->is_connected() || ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product || ! Catalog_Filter::is_eligible( $product ) ) {
			return;
		}

		$rows = Product_Mapper::to_rows( $product );
		if ( ! $rows ) {
			// Eligible but unsendable — a missing price, usually. Recorded
			// against the product rather than dropped, so the products list can
			// say why it never reached Oyster instead of showing it as simply
			// not synced yet.
			$reason = Product_Mapper::skip_reason( $product );
			if ( null !== $reason ) {
				Sync_State::mark_failed( $product_id, $reason, time() );
			}

			return;
		}

		$this->upsert_rows( $rows );
	}

	public function delete_product( int $product_id ): void {
		$bearer = $this->connection->bearer();
		if ( ! $bearer ) {
			return;
		}

		try {
			$this->client->delete_products( $bearer, array( (string) $product_id ) );
			Sync_State::clear( $product_id );
		} catch ( Api_Exception $e ) {
			$this->log( 'delete_product failed: ' . $e->user_message() );
		}
	}

	/**
	 * Processes one page of the catalog, then either enqueues the next page
	 * (self-chaining — keeps each AS task bounded regardless of catalog size)
	 * or marks the import complete.
	 */
	public function run_import_batch( int $page ): void {
		if ( ! $this->connection->is_connected() || ! function_exists( 'wc_get_products' ) ) {
			$this->update_status( array( 'full_import_running' => false ) );
			return;
		}

		$products = wc_get_products(
			array(
				'status'  => 'publish',
				'type'    => array( 'simple', 'variable' ),
				'limit'   => self::IMPORT_PAGE_SIZE,
				'page'    => $page,
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		$rows = array();
		foreach ( $products as $product ) {
			if ( ! $product instanceof WC_Product || ! Catalog_Filter::is_eligible( $product ) ) {
				continue;
			}

			$product_rows = Product_Mapper::to_rows( $product );

			if ( ! $product_rows ) {
				$reason = Product_Mapper::skip_reason( $product );
				if ( null !== $reason ) {
					Sync_State::mark_failed( $product->get_id(), $reason, time() );
				}

				continue;
			}

			$rows = array_merge( $rows, $product_rows );
		}

		$page_result = $rows ? $this->upsert_rows( $rows ) : array(
			'created' => 0,
			'updated' => 0,
			'claimed' => 0,
			'failed'  => 0,
		);

		$totals = $this->status()['full_import_totals'] ?? array(
			'created' => 0,
			'updated' => 0,
			'claimed' => 0,
			'failed'  => 0,
		);
		foreach ( array( 'created', 'updated', 'claimed', 'failed' ) as $key ) {
			$totals[ $key ] = (int) ( $totals[ $key ] ?? 0 ) + (int) ( $page_result[ $key ] ?? 0 );
		}

		if ( count( $products ) === self::IMPORT_PAGE_SIZE ) {
			$this->update_status( array( 'full_import_totals' => $totals ) );
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( self::HOOK_IMPORT_BATCH, array( 'page' => $page + 1 ), self::ACTION_GROUP );
			}
			return;
		}

		$this->update_status(
			array(
				'full_import_running' => false,
				'full_import_totals'  => $totals,
				'last_synced_at'      => time(),
			)
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Shared upsert + status
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Attach Oyster's per-row rejection reasons to the products they belong to.
	 *
	 * Oyster keys a failure by the WooCommerce ids it was sent, so a rejected
	 * variation is recorded against its parent product — that is the row the
	 * merchant sees in the products list and the one they would open to fix it.
	 *
	 * @param array<int, array<string, mixed>> $failed_items
	 */
	private function record_failures( array $failed_items, int $failed_at ): void {
		foreach ( $failed_items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$parent = (int) ( $item['woocommerce_product_id'] ?? 0 );
			if ( $parent <= 0 ) {
				continue;
			}

			$reason = Sync_Error_Message::humanize( (string) ( $item['error'] ?? '' ) );

			// Name the variation when there is one: a variable product with one
			// bad variation otherwise reads as though the whole product failed.
			$variation = (string) ( $item['woocommerce_variation_id'] ?? '' );
			if ( '' !== $variation ) {
				/* translators: 1: variation id, 2: error message */
				$reason = sprintf( __( 'Variation %1$s: %2$s', 'oyster-woocommerce' ), $variation, $reason );
			}

			Sync_State::mark_failed( $parent, $reason, $failed_at );
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @return array{created:int,updated:int,claimed:int,failed:int}
	 */
	private function upsert_rows( array $rows ): array {
		$totals = array(
			'created' => 0,
			'updated' => 0,
			'claimed' => 0,
			'failed'  => 0,
		);

		$bearer = $this->connection->bearer();
		if ( ! $bearer ) {
			$totals['failed'] = count( $rows );
			return $totals;
		}

		// Map unit-id → parent product post-id so we can write per-product meta
		// after the backend confirms a successful upsert. The unit id mirrors the
		// backend's key in the `results` map: variation id when set, else product id.
		$unit_to_parent = array();
		foreach ( $rows as $row ) {
			$unit = $row['woocommerce_variation_id'] ?? null;
			$unit = ( is_string( $unit ) && '' !== $unit ) ? $unit : ( (string) $row['woocommerce_product_id'] );
			$unit_to_parent[ $unit ] = (int) $row['woocommerce_product_id'];
		}

		$synced_at = time();

		foreach ( array_chunk( $rows, self::UPSERT_CHUNK_SIZE ) as $chunk ) {
			try {
				$result  = $this->client->bulk_upsert_products( $bearer, $chunk );
				$data    = is_array( $result['data'] ?? null ) ? $result['data'] : array();
				$results = is_array( $data['results'] ?? null ) ? $data['results'] : array();

				foreach ( array( 'created', 'updated', 'claimed', 'failed' ) as $key ) {
					$totals[ $key ] += (int) ( $data[ $key ] ?? 0 );
				}

				// Persist per-product sync state for each successfully upserted unit.
				foreach ( $results as $unit_id => $unit_result ) {
					if ( ! is_array( $unit_result ) ) {
						continue;
					}
					$oyster_id = (string) ( $unit_result['product_id'] ?? '' );
					if ( '' === $oyster_id ) {
						continue;
					}
					$woo_product_id = $unit_to_parent[ (string) $unit_id ] ?? null;
					if ( null !== $woo_product_id ) {
						Sync_State::mark_synced( $woo_product_id, $oyster_id, $synced_at );
					}
				}

				// Oyster reports which rows it rejected and why. A count alone
				// tells a merchant something is wrong without telling them what
				// to fix, so the reason is stored against the product itself.
				$this->record_failures(
					is_array( $data['failed_items'] ?? null ) ? $data['failed_items'] : array(),
					$synced_at
				);
			} catch ( Api_Exception $e ) {
				$totals['failed'] += count( $chunk );
				$this->log( 'bulk_upsert_products failed: ' . $e->user_message() );

				// The whole request failed, so nothing in it has a per-row
				// reason. Every product in the chunk carries the request's error
				// instead — otherwise a failed batch leaves no trace on any of
				// the products it was carrying.
				foreach ( $chunk as $row ) {
					$parent = (int) ( $row['woocommerce_product_id'] ?? 0 );
					if ( $parent > 0 ) {
						Sync_State::mark_failed( $parent, $e->user_message(), $synced_at );
					}
				}
			}
		}

		$this->update_status(
			array(
				'last_synced_at' => $synced_at,
				'last_result'    => $totals,
			)
		);

		return $totals;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function status(): array {
		$stored = get_option( self::STATUS_OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * @param array<string, mixed> $partial
	 */
	private function update_status( array $partial ): void {
		update_option( self::STATUS_OPTION, array_merge( $this->status(), $partial ) );
	}

	private function log( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $message, array( 'source' => 'oyster-woocommerce' ) );
		}
	}
}
