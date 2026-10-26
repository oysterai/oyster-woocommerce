<?php
/** @package Oyster\Woo */
declare( strict_types=1 );
namespace Oyster\Woo\Wheel;
use Oyster\Woo\Api\Client;
use Oyster\Woo\Support\Connection;
use WC_Coupon;
use WC_Order;
defined( 'ABSPATH' ) || exit;

final class Redemptions {
	public const HOOK = 'oyster_wheel_report_redemptions';
	public const META_NEEDED = '_oyster_wheel_report_needed';
	public function __construct( private Connection $connection, private Client $client ) {}
	public function register(): void {
		foreach ( array( 'woocommerce_payment_complete', 'woocommerce_order_status_processing', 'woocommerce_order_status_completed' ) as $hook ) {
			add_action( $hook, array( $this, 'on_paid' ), 30 );
		}
		add_action( 'woocommerce_checkout_create_order_coupon_item', array( $this, 'stamp_coupon' ), 10, 4 );
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( self::HOOK, array( $this, 'flush' ) );
	}
	public function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::HOOK );
		}
	}
	public function stamp_coupon( $item, $code, $coupon, $order ): void {
		if ( $coupon instanceof WC_Coupon && $coupon->get_meta( Provisioner::META_REWARD ) ) {
			$item->add_meta_data( Provisioner::META_REWARD, $coupon->get_meta( Provisioner::META_REWARD ), true );
			$item->add_meta_data( Provisioner::META_VENDOR, $coupon->get_meta( Provisioner::META_VENDOR ), true );
			$order->update_meta_data( self::META_NEEDED, 'yes' );
		}
	}
	public function on_paid( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! $order->is_paid() ) {
			return;
		}
		$rewards = array();
		if ( $order->get_meta( Provisioner::META_REWARD ) ) {
			$rewards[] = array( 'id' => $order->get_meta( Provisioner::META_REWARD ), 'vendor' => $order->get_meta( Provisioner::META_VENDOR ), 'discount' => 0 );
		}
		// A free delivery coupon discounts nothing itself; its saving is the delivery charge it replaced.
		$delivery_saving = 0.0;
		foreach ( $order->get_items( 'shipping' ) as $shipping ) {
			$delivery_saving += (float) $shipping->get_meta( Free_Delivery::META_SAVING );
		}
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			$coupon = new WC_Coupon( $item->get_code() );
			$id = $item->get_meta( Provisioner::META_REWARD ) ?: $coupon->get_meta( Provisioner::META_REWARD );
			$vendor = $item->get_meta( Provisioner::META_VENDOR ) ?: $coupon->get_meta( Provisioner::META_VENDOR );
			if ( $id ) {
				$discount = (float) $item->get_discount();
				if ( $coupon->get_free_shipping() ) {
					$discount += $delivery_saving;
					$delivery_saving = 0.0;
				}
				$rewards[] = array( 'id' => $id, 'vendor' => $vendor, 'discount' => $discount );
			}
		}
		Outbox::install();
		global $wpdb;
		$table = Outbox::table();
		$queued = true;
		$added = false;
		foreach ( $rewards as $reward ) {
			$paid = $order->get_date_paid() ?: $order->get_date_modified();
			$payload = array(
				'reward_id' => $reward['id'], 'order_id' => (string) $order_id,
				'discount_amount' => $reward['discount'], 'currency' => $order->get_currency(),
				'completed_at' => $paid ? $paid->date( DATE_ATOM ) : gmdate( DATE_ATOM ),
			);
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (vendor_id,reward_id,order_id,payload) VALUES (%d,%s,%d,%s)", (int) $reward['vendor'], $reward['id'], $order_id, wp_json_encode( $payload ) ) );
			$queued = false !== $inserted && $queued;
			$added = $added || $inserted > 0;
		}
		if ( $rewards && $queued ) {
			$order->update_meta_data( self::META_NEEDED, 'no' );
			$order->save();
		}
		// Later status changes on a reported order find nothing new to send.
		if ( ! $added ) {
			return;
		}
		// A retry already waiting on an earlier failure's backoff must not hold this report back.
		$next = wp_next_scheduled( self::HOOK, array( 'retry' ) );
		if ( ! $next || $next > time() ) {
			wp_schedule_single_event( time(), self::HOOK, array( 'retry' ) );
		}
		// WP-Cron only runs on a page load, and the next one on a quiet store may be hours
		// away. Start it now; the request is non-blocking so payment does not wait on it.
		if ( ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
			spawn_cron();
		}
	}
	public function flush(): void {
		if ( ! $this->connection->is_connected() ) {
			return;
		}
		foreach ( wc_get_orders( array( 'status' => wc_get_is_paid_statuses(), 'limit' => 25, 'meta_key' => self::META_NEEDED, 'meta_value' => 'yes' ) ) as $order ) {
			$this->on_paid( $order->get_id() );
		}
		global $wpdb;
		$table = Outbox::table();
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE vendor_id = %d AND sent_at = 0 AND next_at <= %d ORDER BY id LIMIT 25", $this->connection->vendor_id(), time() ) );
		foreach ( $ids as $id ) {
			try {
				Lock::run( 'report-' . $id, function () use ( $wpdb, $table, $id ): void {
					$record = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND sent_at = 0", $id ), ARRAY_A );
					if ( ! $record ) {
						return;
					}
					try {
						$response = $this->client->request( 'POST', '/api/v1/integrations/woocommerce/wheel/redemptions', array( 'bearer' => $this->connection->bearer(), 'body' => json_decode( $record['payload'], true ) ) );
						if ( empty( $response['success'] ) ) {
							throw new \RuntimeException( 'redemption_unconfirmed' );
						}
						$wpdb->update( $table, array( 'sent_at' => time() ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
					} catch ( \Throwable $e ) {
						$attempts = (int) $record['attempts'] + 1;
						$next_at = time() + min( HOUR_IN_SECONDS, 30 * ( 2 ** min( $attempts, 7 ) ) );
						$wpdb->update( $table, array( 'attempts' => $attempts, 'next_at' => $next_at ), array( 'id' => $id ), array( '%d', '%d' ), array( '%d' ) );
						wp_schedule_single_event( $next_at, self::HOOK, array( 'retry' ) );
					}
				} );
			} catch ( \RuntimeException $e ) {
				continue;
			}
		}
		if ( count( $ids ) === 25 && ! wp_next_scheduled( self::HOOK, array( 'retry' ) ) ) {
			wp_schedule_single_event( time() + 10, self::HOOK, array( 'retry' ) );
		}
	}
}
