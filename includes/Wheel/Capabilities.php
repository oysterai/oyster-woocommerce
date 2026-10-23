<?php
/** @package Oyster\Woo */
declare( strict_types=1 );
namespace Oyster\Woo\Wheel;
use Oyster\Woo\Api\Client;
use Oyster\Woo\Support\Connection;
use Oyster\Woo\Webhooks\Webhook_Secret;
defined( 'ABSPATH' ) || exit;

final class Capabilities {
	public const HOOK = 'oyster_wheel_heartbeat';
	public function __construct( private Connection $connection, private Client $client, private Webhook_Secret $secret ) {}
	public function register(): void {
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( 'admin_init', array( $this, 'refresh' ), 20 );
		add_action( self::HOOK, array( $this, 'refresh' ) );
	}
	public function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'daily', self::HOOK );
		}
	}
	public function refresh(): void {
		if ( ! $this->connection->is_connected() || ! $this->secret->is_registered() ) {
			return;
		}
		$types = wc_coupons_enabled() ? array( 'percentage_discount', 'fixed_discount' ) : array();
		if ( 'yes' === get_option( 'woocommerce_manage_stock', 'yes' ) ) {
			$types[] = 'free_item';
		}
		if ( ! Storage::supports_transactions() ) {
			$types = array();
		}
		$payload = array(
			'protocol_version' => 1, 'reward_types' => $types,
			'currency' => get_woocommerce_currency(), 'store_host' => strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			'constraints' => array( 'free_item_usage_limit' => 1, 'free_item_max_expiry_days' => 3650, 'free_item_min_order_amount' => 0, 'free_item_combines_with_discounts' => false, 'free_item_requires_expiry' => true, 'free_item_product_restrictions' => false, 'fixed_discount_product_restrictions' => false ),
		);
		$fingerprint = hash( 'sha256', wp_json_encode( $payload ) . $this->connection->bearer() . $this->secret->endpoint_id() );
		$key = 'oyster_wheel_capabilities_' . substr( $fingerprint, 0, 24 );
		if ( get_transient( $key ) ) {
			return;
		}
		try {
			$this->client->request( 'POST', '/api/v1/integrations/woocommerce/wheel/capabilities', array( 'bearer' => $this->connection->bearer(), 'body' => $payload ) );
			set_transient( $key, 1, 20 * HOUR_IN_SECONDS );
		} catch ( \Throwable $e ) {
			set_transient( $key, 1, 5 * MINUTE_IN_SECONDS );
		}
	}
}
