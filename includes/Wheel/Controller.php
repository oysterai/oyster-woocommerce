<?php
/** @package Oyster\Woo */
declare( strict_types=1 );
namespace Oyster\Woo\Wheel;
use Oyster\Woo\Support\Connection;
use Oyster\Woo\Webhooks\Signature;
use Oyster\Woo\Webhooks\Webhook_Secret;
use WP_REST_Request;
use WP_REST_Response;
defined( 'ABSPATH' ) || exit;

final class Controller {
	public function __construct( private Connection $connection, private Webhook_Secret $secret, private Provisioner $provisioner ) {}
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}
	public function routes(): void {
		register_rest_route( 'oyster/v1', '/wheel/rewards/provision', array( 'methods' => 'POST', 'callback' => array( $this, 'provision' ), 'permission_callback' => '__return_true' ) );
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
	public function provision( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->connection->is_connected() || ! $this->secret->is_registered() ) {
			return new WP_REST_Response( array( 'code' => 'not_connected' ), 503 );
		}
		if ( ! Signature::is_valid( (string) $request->get_header( 'Oyster-Signature' ), $request->get_body(), $this->secret->secret() ?? '' ) ) {
			return new WP_REST_Response( array( 'code' => 'invalid_signature' ), 401 );
		}
		$terms = $request->get_json_params();
		if ( ! is_array( $terms ) || ! Terms::valid( $terms ) ) {
			return new WP_REST_Response( array( 'code' => 'invalid_reward_terms' ), 422 );
		}
		try {
			return new WP_REST_Response( array( 'success' => true, 'data' => $this->provisioner->provision( $terms ) ) );
		} catch ( \InvalidArgumentException $e ) {
			return new WP_REST_Response( array( 'code' => 'reward_unavailable' ), 422 );
		} catch ( \Throwable $e ) {
			// A timeout or an ambiguous database failure is retryable, never a new grant.
			return new WP_REST_Response( array( 'code' => 'reward_pending' ), 503 );
		}
	}
}
