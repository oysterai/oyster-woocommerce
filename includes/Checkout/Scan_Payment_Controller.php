<?php
/**
 * Storefront REST endpoint the widget uses to start a scan or booking payment.
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
 * Raises the order a shopper pays to run their scan or book an appointment, and hands back where to pay.
 *
 * Public (`permission_callback: __return_true`) for the same reason the cart-add
 * route is: a shopper paying for their own scan has no account here, and the
 * route creates a pending order in their own session exactly as any "Add to
 * cart" form does.
 *
 * A forged request achieves nothing useful. The reference has to be one Oyster
 * issued to this store, and an order raised against a made-up one can never be
 * confirmed — nothing is charged, no scan is unblocked, and the worst case is a
 * pending order the merchant can bin.
 *
 * What is deliberately NOT here: anything that completes a payment. That is sent
 * from PHP when the order genuinely reaches a paid state, with a credential that
 * never reaches the page — see {@see Scan_Payment}.
 */
final class Scan_Payment_Controller {

	private const NAMESPACE = 'oyster-woocommerce/v1';

	public function __construct(
		private Scan_Payment $payments
	) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/scan-payment/create',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_create' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'reference'   => array( 'required' => true ),
					'amount'      => array( 'required' => true ),
					'currency'    => array( 'required' => false ),
					'email'       => array( 'required' => false ),
					'batch_id'    => array( 'required' => false ),
					'purpose'     => array( 'required' => false ),
					'line_items'  => array( 'required' => false ),
					'appointment' => array( 'required' => false ),
				),
			)
		);
	}

	public function handle_create( WP_REST_Request $request ): WP_REST_Response {
		$reference = sanitize_text_field( (string) $request->get_param( 'reference' ) );
		$amount    = (float) $request->get_param( 'amount' );

		if ( '' === $reference ) {
			return new WP_REST_Response( array( 'error' => 'reference_required' ), 400 );
		}

		// A zero or negative charge would produce an order that can never be
		// paid, leaving the shopper on a checkout with nothing to do.
		if ( $amount <= 0 ) {
			return new WP_REST_Response( array( 'error' => 'invalid_amount' ), 400 );
		}

		$result = $this->payments->create_order(
			array(
				'reference'   => $reference,
				'amount'      => $amount,
				'currency'    => sanitize_text_field( (string) $request->get_param( 'currency' ) ),
				'email'       => sanitize_email( (string) $request->get_param( 'email' ) ),
				'batch_id'    => sanitize_text_field( (string) $request->get_param( 'batch_id' ) ),
				'purpose'     => Scan_Payment::PURPOSE_APPOINTMENT === $request->get_param( 'purpose' )
					? Scan_Payment::PURPOSE_APPOINTMENT
					: Scan_Payment::PURPOSE_SCAN,
				'line_items'  => self::line_items( $request->get_param( 'line_items' ) ),
				'appointment' => self::appointment( $request->get_param( 'appointment' ) ),
			)
		);

		if ( isset( $result['error'] ) ) {
			$status = array(
				'not_connected'          => 409,
				'woocommerce_unavailable' => 503,
				'product_unavailable'    => 500,
				'order_failed'           => 500,
			);

			return new WP_REST_Response( $result, $status[ $result['error'] ] ?? 500 );
		}

		return new WP_REST_Response( $result );
	}

	/**
	 * Names reach the merchant's order screen and the shopper's receipt, so they are treated as text.
	 *
	 * @param mixed $value
	 * @return list<array{kind: string, name: string, amount: float}>
	 */
	private static function line_items( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$lines = array();
		foreach ( $value as $line ) {
			if ( ! is_array( $line ) || ! is_numeric( $line['amount'] ?? null ) ) {
				continue;
			}

			$lines[] = array(
				'kind'   => sanitize_key( (string) ( $line['kind'] ?? '' ) ),
				'name'   => sanitize_text_field( (string) ( $line['name'] ?? '' ) ),
				'amount' => (float) $line['amount'],
			);
		}

		return $lines;
	}

	/**
	 * @param mixed $value
	 * @return array{type: string, date: string, startTime: string}|null
	 */
	private static function appointment( $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}

		return array(
			'type'      => sanitize_text_field( (string) ( $value['type'] ?? '' ) ),
			'date'      => sanitize_text_field( (string) ( $value['date'] ?? '' ) ),
			'startTime' => sanitize_text_field( (string) ( $value['startTime'] ?? '' ) ),
		);
	}
}
