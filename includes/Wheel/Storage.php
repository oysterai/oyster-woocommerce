<?php
/** @package Oyster\Woo */
declare( strict_types=1 );
namespace Oyster\Woo\Wheel;
use Automattic\WooCommerce\Utilities\OrderUtil;
defined( 'ABSPATH' ) || exit;

final class Storage {
	/** A grant and its native reward must commit together, including on legacy stores. */
	public static function supports_transactions(): bool {
		global $wpdb;
		$tables = array( $wpdb->options, $wpdb->posts, $wpdb->postmeta, $wpdb->prefix . 'woocommerce_order_items', $wpdb->prefix . 'woocommerce_order_itemmeta', $wpdb->prefix . 'wc_reserved_stock', Outbox::table() );
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			foreach ( array( 'wc_orders', 'wc_order_addresses', 'wc_order_operational_data', 'wc_orders_meta' ) as $suffix ) {
				$tables[] = $wpdb->prefix . $suffix;
			}
		}
		$placeholders = implode( ',', array_fill( 0, count( $tables ), '%s' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SHOW TABLE STATUS WHERE Name IN ({$placeholders})", $tables ), ARRAY_A );
		if ( ! is_array( $rows ) || count( $rows ) !== count( $tables ) ) {
			return false;
		}
		foreach ( $rows as $row ) {
			if ( 'innodb' !== strtolower( (string) $row['Engine'] ) ) {
				return false;
			}
		}
		return true;
	}
}
