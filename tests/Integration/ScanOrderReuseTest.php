<?php

declare( strict_types=1 );

namespace Oyster\Woo\Tests\Integration;

use Oyster\Woo\Api\Client;
use Oyster\Woo\Checkout\Scan_Payment;
use Oyster\Woo\Support\Connection;
use Oyster\Woo\Support\Scan_Confirmation_Health;
use Oyster\Woo\Support\Scan_Pricing;
use WP_UnitTestCase;

/**
 * The widget asks for a checkout again whenever the shopper reloads or taps Pay twice. One scan must only
 * ever be one charge, so a repeat request for a reference reuses its order, and never opens a second one
 * once the first is paid.
 */
final class ScanOrderReuseTest extends WP_UnitTestCase {

	private const REFERENCE = 'scan-ref-reuse';

	/** @var list<string> */
	private array $confirmations = array();

	private int $confirmation_code = 200;

	public function set_up(): void {
		parent::set_up();

		$this->confirmations     = array();
		$this->confirmation_code = 200;

		( new Connection() )->save(
			array(
				'bearer'    => 'a-test-bearer',
				'vendor_id' => 7,
			)
		);

		add_filter( 'pre_http_request', array( $this, 'answer_request' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'answer_request' ), 10 );
		Scan_Confirmation_Health::clear();
		delete_option( Connection::OPTION_KEY );

		parent::tear_down();
	}

	/**
	 * Confirmations succeed; every other call to Oyster fails, so pricing falls back to the widget's amount.
	 *
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>
	 */
	public function answer_request( $preempt, array $args, string $url ): array {
		$is_confirmation = str_contains( $url, '/external/confirm' );
		if ( $is_confirmation ) {
			$this->confirmations[] = $url;
		}

		return array(
			'response' => array(
				'code'    => $is_confirmation ? $this->confirmation_code : 503,
				'message' => 'x',
			),
			'body'     => wp_json_encode( array( 'data' => array( 'status' => 'success' ) ) ),
			'headers'  => array(),
			'cookies'  => array(),
		);
	}

	public function test_a_repeat_request_reuses_the_unpaid_order(): void {
		$payment = $this->scan_payment();

		$first  = $payment->create_order( $this->request() );
		$second = $payment->create_order( $this->request() );

		$this->assertSame( $first['order_id'], $second['order_id'] );
		$this->assertSame( $first['checkout_url'], $second['checkout_url'] );
	}

	public function test_a_paid_scan_is_never_offered_a_second_checkout(): void {
		$payment = $this->scan_payment();
		$first   = $payment->create_order( $this->request() );
		wc_get_order( $first['order_id'] )->payment_complete();

		$again = $payment->create_order( $this->request() );

		$this->assertTrue( $again['already_paid'] ?? false );
		$this->assertSame( $first['order_id'], $again['order_id'] );
		$this->assertArrayNotHasKey( 'checkout_url', $again );
		$this->assertCount( 1, wc_get_orders( array( 'created_via' => 'oyster-scan', 'limit' => -1 ) ) );
	}

	public function test_a_paid_scan_whose_confirmation_was_lost_is_reported_again(): void {
		$payment = $this->scan_payment();
		$first   = $payment->create_order( $this->request() );

		// Oyster is unreachable when the order is paid, so the store's own confirmation fails.
		$this->confirmation_code = 503;
		$order                   = wc_get_order( $first['order_id'] );
		$order->set_status( 'processing' );
		$order->save();
		$payment->on_paid( $order->get_id() );

		$this->confirmation_code = 200;
		$this->confirmations     = array();

		$payment->create_order( $this->request() );

		$this->assertCount( 1, $this->confirmations );
	}

	private function scan_payment(): Scan_Payment {
		$connection = new Connection();
		$client     = new Client();

		return new Scan_Payment( $connection, $client, new Scan_Pricing( $connection, $client ) );
	}

	/** @return array{reference: string, amount: float, currency: string, email: string, batch_id: string} */
	private function request(): array {
		return array(
			'reference' => self::REFERENCE,
			'amount'    => 5000.0,
			'currency'  => 'NGN',
			'email'     => 'ada@example.com',
			'batch_id'  => 'batch-1',
		);
	}
}
