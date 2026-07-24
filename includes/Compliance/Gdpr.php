<?php
/**
 * Personal-data export/erasure for Oyster's order attribution meta.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Compliance;

use Oyster\Woo\Checkout\Order_Attribution;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's connection settings (business name, public key, primary
 * color, ...) are store *configuration*, not a data subject's personal data
 * — they aren't in scope for WordPress's personal-data export/erase tools.
 * The only personal-data-adjacent fields this plugin adds anywhere are the
 * three order-meta keys Order_Attribution stamps onto a WooCommerce order
 * (batch id, routine id, widget attribution id) — WooCommerce's own order
 * data is already covered by its own privacy handling, so this class only
 * needs to surface/remove the extra fields we bolted on.
 *
 * Registered via WordPress core's own privacy-tools hooks (Tools > Export/
 * Erase Personal Data), not any WooCommerce-specific extension point — core
 * hooks are stable public API, and both `wp_privacy_personal_data_exporters`
 * and `_erasers` fire from admin-ajax.php requests, which are `is_admin()`.
 */
final class Gdpr {

	/** Orders processed per exporter/eraser page — matches WooCommerce's own convention. */
	private const PAGE_SIZE = 10;

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * @param array<string, mixed> $exporters
	 * @return array<string, mixed>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['oyster-woocommerce'] = array(
			'exporter_friendly_name' => __( 'Oyster for WooCommerce', 'oyster-woocommerce' ),
			'callback'               => array( $this, 'export_data' ),
		);

		return $exporters;
	}

	/**
	 * @param array<string, mixed> $erasers
	 * @return array<string, mixed>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['oyster-woocommerce'] = array(
			'eraser_friendly_name' => __( 'Oyster for WooCommerce', 'oyster-woocommerce' ),
			'callback'             => array( $this, 'erase_data' ),
		);

		return $erasers;
	}

	/**
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export_data( string $email, int $page = 1 ): array {
		$orders = $this->find_orders( $email, max( 1, $page ) );

		$data = array();
		foreach ( $orders as $order ) {
			$batch_id = $order->get_meta( Order_Attribution::META_BATCH_ID );
			if ( ! $batch_id ) {
				continue;
			}

			$fields = array(
				array(
					'name'  => __( 'Order', 'oyster-woocommerce' ),
					'value' => $order->get_order_number(),
				),
				array(
					'name'  => __( 'Skin-scan batch id', 'oyster-woocommerce' ),
					'value' => $batch_id,
				),
			);

			$routine_id = $order->get_meta( Order_Attribution::META_ROUTINE_ID );
			if ( $routine_id ) {
				$fields[] = array(
					'name'  => __( 'Routine id', 'oyster-woocommerce' ),
					'value' => $routine_id,
				);
			}

			$attribution_id = $order->get_meta( Order_Attribution::META_ATTRIBUTION_ID );
			if ( $attribution_id ) {
				$fields[] = array(
					'name'  => __( 'Widget attribution id', 'oyster-woocommerce' ),
					'value' => $attribution_id,
				);
			}

			$data[] = array(
				'group_id'    => 'oyster-woocommerce-orders',
				'group_label' => __( 'Oyster skincare scan attribution', 'oyster-woocommerce' ),
				'item_id'     => 'oyster-woocommerce-order-' . $order->get_id(),
				'data'        => $fields,
			);
		}

		return array(
			'data' => $data,
			'done' => count( $orders ) < self::PAGE_SIZE,
		);
	}

	/**
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public function erase_data( string $email, int $page = 1 ): array {
		$orders = $this->find_orders( $email, max( 1, $page ) );

		$removed = false;
		foreach ( $orders as $order ) {
			$changed = false;
			foreach (
				array(
					Order_Attribution::META_BATCH_ID,
					Order_Attribution::META_ROUTINE_ID,
					Order_Attribution::META_ATTRIBUTION_ID,
				) as $meta_key
			) {
				if ( $order->get_meta( $meta_key ) ) {
					$order->delete_meta_data( $meta_key );
					$changed = true;
				}
			}

			if ( $changed ) {
				$order->save();
				$removed = true;
			}
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $orders ) < self::PAGE_SIZE,
		);
	}

	/**
	 * @return WC_Order[]
	 */
	private function find_orders( string $email, int $page ): array {
		if ( '' === $email || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => self::PAGE_SIZE,
				'page'          => $page,
				'meta_key'      => Order_Attribution::META_BATCH_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare'  => 'EXISTS',
				'return'        => 'objects',
			)
		);

		return is_array( $orders ) ? $orders : array();
	}
}
