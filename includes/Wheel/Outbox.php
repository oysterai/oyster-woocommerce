<?php
/** @package Oyster\Woo */
declare( strict_types=1 );
namespace Oyster\Woo\Wheel;
defined( 'ABSPATH' ) || exit;

final class Outbox {
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'oyster_wheel_redemptions';
	}
	public static function install(): void {
		if ( 1 === (int) get_option( 'oyster_wheel_storage_version' ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			vendor_id bigint(20) unsigned NOT NULL,
			reward_id varchar(100) NOT NULL,
			order_id bigint(20) unsigned NOT NULL,
			payload longtext NOT NULL,
			attempts int unsigned NOT NULL DEFAULT 0,
			next_at bigint(20) unsigned NOT NULL DEFAULT 0,
			sent_at bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY reward_order (vendor_id,reward_id,order_id),
			KEY pending (vendor_id,sent_at,next_at)
		) ENGINE=InnoDB {$charset};" );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
			update_option( 'oyster_wheel_storage_version', 1, false );
		}
	}
}
