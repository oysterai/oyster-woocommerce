<?php
/**
 * Storefront cart-add REST endpoint — the widget's checkout handoff.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Checkout;

use Oyster\Woo\Api\Api_Exception;
use Oyster\Woo\Api\Client;
use Oyster\Woo\Support\Connection;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The WooCommerce counterpart of Shopify's `/cart/add.js` + cart-attributes
 * handoff. Unlike Shopify, the storefront loader never resolves Oyster
 * product ids or touches skin-ai-api directly — that would mean exposing the
 * vendor bearer to the browser. Instead the loader POSTs the widget's
 * checkout payload here; this endpoint (running server-side, holding the
 * bearer) resolves Oyster ids to WooCommerce ids, adds purchasable/in-stock
 * items to the *visitor's own* cart session via the standard WC cart API, and
 * returns a redirect URL.
 *
 * Public on purpose (`permission_callback: __return_true`) — it only ever
 * mutates the requesting visitor's own cart session, exactly like any native
 * WooCommerce "Add to cart" form. Forged batch/routine ids carry no risk:
 * RecordWooCommerceOrder validates them server-side against a real scan
 * before attributing anything.
 */
final class Cart_Controller {

	private const NAMESPACE = 'oyster-woocommerce/v1';

	public function __construct(
		private Connection $connection,
		private Client $client
	) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/cart/add',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_add' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'items'                 => array( 'required' => true ),
					'batch_id'              => array( 'required' => false ),
					'routine_id'            => array( 'required' => false ),
					'widget_attribution_id' => array( 'required' => false ),
				),
			)
		);
	}

	public function handle_add( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->connection->is_connected() ) {
			return new WP_REST_Response( array( 'error' => 'not_connected' ), 409 );
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return new WP_REST_Response( array( 'error' => 'cart_unavailable' ), 503 );
		}

		$items = $this->sanitize_items( $request->get_param( 'items' ) );
		if ( ! $items ) {
			return new WP_REST_Response( array( 'error' => 'no_items' ), 400 );
		}

		$bearer = $this->connection->bearer();
		if ( ! $bearer ) {
			return new WP_REST_Response( array( 'error' => 'not_connected' ), 409 );
		}

		try {
			$resolved = $this->client->resolve_variants( $bearer, array_column( $items, 'product_id' ) );
		} catch ( Api_Exception $e ) {
			$this->log( 'resolve_variants failed: ' . $e->user_message() );
			return new WP_REST_Response( array( 'error' => 'resolve_failed' ), 502 );
		}

		$mapping     = is_array( $resolved['data'] ?? null ) ? $resolved['data'] : array();
		$attribution = $this->sanitize_attribution( $request );

		$added   = 0;
		$skipped = 0;

		foreach ( $items as $item ) {
			$entry = $mapping[ (string) $item['product_id'] ] ?? null;
			if ( ! is_array( $entry ) || empty( $entry['woocommerce_product_id'] ) ) {
				++$skipped;
				continue;
			}

			$product_id   = absint( $entry['woocommerce_product_id'] );
			$variation_id = ! empty( $entry['woocommerce_variation_id'] ) ? absint( $entry['woocommerce_variation_id'] ) : 0;

			$product = wc_get_product( $variation_id ?: $product_id );
			if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				++$skipped;
				continue;
			}

			$cart_item_key = WC()->cart->add_to_cart(
				$product_id,
				$item['quantity'],
				$variation_id,
				array(),
				array( 'oyster_attribution' => $attribution )
			);

			if ( $cart_item_key ) {
				++$added;
			} else {
				++$skipped;
			}
		}

		if ( 0 === $added ) {
			// Nothing addable — either not yet synced or everything's sold
			// out. Send the shopper to /cart so they can pick for themselves;
			// there's nothing useful to check out.
			return new WP_REST_Response(
				array(
					'added'    => 0,
					'skipped'  => $skipped,
					'redirect' => wc_get_cart_url(),
				)
			);
		}

		return new WP_REST_Response(
			array(
				'added'    => $added,
				'skipped'  => $skipped,
				'redirect' => wc_get_checkout_url(),
			)
		);
	}

	/**
	 * @param mixed $raw
	 * @return array<int, array{product_id:int, quantity:int}>
	 */
	private function sanitize_items( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$items = array();
		foreach ( $raw as $entry ) {
			$product_id = is_array( $entry ) && isset( $entry['product_id'] ) ? absint( $entry['product_id'] ) : 0;
			if ( $product_id <= 0 ) {
				continue;
			}

			$quantity = is_array( $entry ) && isset( $entry['quantity'] ) ? max( 1, absint( $entry['quantity'] ) ) : 1;
			$items[]  = array(
				'product_id' => $product_id,
				'quantity'   => $quantity,
			);
		}

		return $items;
	}

	/**
	 * @return array<string, string>
	 */
	private function sanitize_attribution( WP_REST_Request $request ): array {
		$fields = array(
			'batch_id'              => $request->get_param( 'batch_id' ),
			'routine_id'            => $request->get_param( 'routine_id' ),
			'widget_attribution_id' => $request->get_param( 'widget_attribution_id' ),
		);

		$sanitized = array();
		foreach ( $fields as $key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			$sanitized[ $key ] = sanitize_text_field( (string) $value );
		}

		return $sanitized;
	}

	private function log( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $message, array( 'source' => 'oyster-woocommerce' ) );
		}
	}
}
