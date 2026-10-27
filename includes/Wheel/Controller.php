<?php
/** @package Oyster\Woo */
declare( strict_types=1 );
namespace Oyster\Woo\Wheel;
use Oyster\Woo\Support\Connection;
use Oyster\Woo\Webhooks\Receiver;
use WP_REST_Request;
use WP_REST_Response;
defined( 'ABSPATH' ) || exit;

final class Controller {
	public function __construct( private Connection $connection, private Provisioner $provisioner ) {}
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( Receiver::WHEEL_REWARD_WON, array( $this, 'issue' ), 10, 2 );
	}
	public function routes(): void {
		register_rest_route( 'oyster/v1', '/wheel/rewards/claim', array( 'methods' => 'POST', 'callback' => array( $this, 'claim' ), 'permission_callback' => '__return_true' ) );
	}
	public function claim( WP_REST_Request $request ): WP_REST_Response {
		$claim = $request->get_json_params();
		if ( ! $this->connection->is_connected() || ! is_array( $claim ) || 'woocommerce' !== ( $claim['redemption_authority'] ?? null ) || ! is_string( $claim['reward_id'] ?? null ) || ! is_string( $claim['claim_token'] ?? null ) ) {
			return new WP_REST_Response( array( 'code' => 'reward_unavailable' ), 404 );
		}
		$order = ( new Gift( $this->connection, $this->provisioner ) )->authenticated_order( $claim['reward_id'], $claim['claim_token'] );
		if ( ! $order || ( ! $order->get_meta( Gift::META_CONFIRMED ) && (int) $order->get_meta( Gift::META_EXPIRY ) <= time() ) || $order->has_status( array( 'cancelled', 'failed', 'refunded' ) ) ) {
			return new WP_REST_Response( array( 'code' => 'reward_unavailable' ), 404 );
		}
		return new WP_REST_Response( array( 'redirect' => add_query_arg( array( 'oyster_wheel_claim' => $claim['reward_id'], 'token' => $claim['claim_token'] ), home_url( '/' ) ) ), 200, array( 'Cache-Control' => 'no-store' ) );
	}
	/**
	 * Oyster announces every win to every listening platform and leaves honouring it to
	 * them. This store makes a code only for rewards issued at its own checkout. Anything
	 * it cannot honour is let go: the event is already signed and acknowledged, and an
	 * error here would only be retried.
	 */
	public function issue( string $batch_id, array $payload ): void {
		$data = is_array( $payload['data'] ?? null ) ? $payload['data'] : array();
		if ( 'woocommerce' !== ( $data['redemption_authority'] ?? null ) || $this->connection->vendor_id() !== (int) ( $data['vendor_id'] ?? 0 ) ) {
			return;
		}
		$terms = array_diff_key( $data, array( 'redemption_authority' => true, 'vendor_id' => true ) );
		if ( ! Terms::valid( $terms ) ) {
			return;
		}
		try {
			$this->provisioner->provision( $terms );
		} catch ( \InvalidArgumentException $e ) {
			return;
		}
	}
}
