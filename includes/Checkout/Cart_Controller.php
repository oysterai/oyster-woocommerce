<?php
/**
 * Storefront cart-add REST endpoint — the widget's checkout handoff.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Checkout;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The storefront loader never resolves Oyster product ids or touches
 * Oyster's API directly — that would mean exposing the vendor bearer to the
 * browser. Instead the loader POSTs the widget's checkout payload here; this
 * endpoint (running server-side, holding the bearer) hands it to Cart_Filler,
 * which resolves Oyster ids to WooCommerce ids and adds purchasable/in-stock
 * items to the *visitor's own* cart session via the standard WC cart API. The
 * response tells the loader where to send the shopper next.
 *
 * Public on purpose (`permission_callback: __return_true`) — it only ever
 * mutates the requesting visitor's own cart session, exactly like any native
 * WooCommerce "Add to cart" form. Forged batch/routine ids carry no risk:
 * the backend validates them server-side against a real scan before
 * attributing anything.
 */
final class Cart_Controller {

	private const NAMESPACE = 'oyster-woocommerce/v1';

	public function __construct(
		private Cart_Filler $filler
	) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'template_redirect', array( $this, 'maybe_show_error_notice' ) );
	}

	/**
	 * Both handoffs redirect here with `?oyster_checkout_error=1` when they
	 * can't fill the cart — the loader on a network error, non-2xx or
	 * malformed response (see oyster-loader.js's redirectToFallback()), and
	 * Email_Handoff when nothing in the link was addable. Surfaces a real
	 * WooCommerce notice on arrival instead of leaving the shopper on a page
	 * that gives no indication anything went wrong.
	 */
	public function maybe_show_error_notice(): void {
		if ( ! isset( $_GET['oyster_checkout_error'] ) || ! function_exists( 'wc_add_notice' ) ) {
			return;
		}

		wc_add_notice(
			__( "We couldn't automatically add your recommended items to your cart. Please add them manually below.", 'oyster-woocommerce' ),
			'error'
		);
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
		$items = Cart_Filler::sanitize_items( $request->get_param( 'items' ) );
		if ( ! $items ) {
			return new WP_REST_Response( array( 'error' => 'no_items' ), 400 );
		}

		$result = $this->filler->fill( $items, $this->sanitize_attribution( $request ) );

		if ( isset( $result['error'] ) ) {
			$status = array(
				'not_connected'    => 409,
				'cart_unavailable' => 503,
				'resolve_failed'   => 502,
			);

			return new WP_REST_Response( $result, $status[ $result['error'] ] ?? 500 );
		}

		$added   = $result['added'];
		$skipped = $result['skipped'];

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
}
