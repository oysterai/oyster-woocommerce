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
 * A clinic taking widget bookings at this store's checkout: the order reads as what the shopper is paying
 * for, and paying it books the appointment with Oyster rather than unblocking a scan.
 */
final class BookingPaymentTest extends WP_UnitTestCase {

	private const REFERENCE = 'APT20261022BOOKINGREF1';

	/** @var list<string> */
	private array $confirmations = array();

	public function set_up(): void {
		parent::set_up();

		$this->confirmations = array();

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
	 * Confirmations succeed; any other call to Oyster fails, as pricing does when it is unreachable.
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
				'code'    => $is_confirmation ? 200 : 503,
				'message' => 'x',
			),
			'body'     => wp_json_encode( array( 'data' => array( 'status' => 'confirmed' ) ) ),
			'headers'  => array(),
			'cookies'  => array(),
		);
	}

	public function test_a_booking_order_itemises_what_the_shopper_pays_for(): void {
		$result = $this->scan_payment()->create_order( $this->booking() );
		$order  = wc_get_order( $result['order_id'] );

		$names = array_values( array_map( static fn ( $item ) => $item->get_name(), $order->get_items() ) );

		$this->assertSame( array( 'Consultation', 'Skin scan' ), $names );
		$this->assertEqualsWithDelta( 23000.0, (float) $order->get_total(), 0.001 );
		$this->assertSame( Scan_Payment::PURPOSE_APPOINTMENT, $order->get_meta( Scan_Payment::ORDER_META_PURPOSE ) );
		$this->assertNotEmpty( $result['checkout_url'] );
	}

	public function test_a_booking_is_not_raised_against_the_scan_product(): void {
		$result  = $this->scan_payment()->create_order( $this->booking() );
		$order   = wc_get_order( $result['order_id'] );
		$product = array_values( $order->get_items() )[0]->get_product_id();

		$this->assertSame( (int) get_option( Scan_Payment::BOOKING_PRODUCT_OPTION ), $product );
	}

	public function test_a_breakdown_that_does_not_add_up_falls_back_to_one_line(): void {
		$request = $this->booking();

		$request['line_items'] = array(
			array(
				'kind'   => 'appointment',
				'name'   => 'Consultation',
				'amount' => 100.0,
			),
		);

		$order = wc_get_order( $this->scan_payment()->create_order( $request )['order_id'] );

		$this->assertCount( 1, $order->get_items() );
		$this->assertEqualsWithDelta( 23000.0, (float) $order->get_total(), 0.001 );
	}

	public function test_paying_a_booking_confirms_the_booking_not_a_scan(): void {
		$payment = $this->scan_payment();
		$order   = wc_get_order( $payment->create_order( $this->booking() )['order_id'] );

		$payment->on_paid( $order->get_id() );

		$this->assertCount( 1, $this->confirmations );
		$this->assertStringContainsString(
			'/consultation/appointments/reservations/' . self::REFERENCE . '/external/confirm',
			$this->confirmations[0]
		);
	}

	public function test_a_scan_order_still_confirms_the_scan(): void {
		$payment = $this->scan_payment();
		$request = $this->booking();
		unset( $request['purpose'], $request['line_items'], $request['appointment'] );

		$order = wc_get_order( $payment->create_order( $request )['order_id'] );
		$payment->on_paid( $order->get_id() );

		$this->assertStringContainsString( '/scan-payments/' . self::REFERENCE . '/external/confirm', $this->confirmations[0] );
	}

	public function test_a_paid_booking_is_never_offered_a_second_checkout(): void {
		$payment = $this->scan_payment();
		$first   = $payment->create_order( $this->booking() );
		wc_get_order( $first['order_id'] )->payment_complete();

		$again = $payment->create_order( $this->booking() );

		$this->assertTrue( $again['already_paid'] ?? false );
		$this->assertSame( $first['order_id'], $again['order_id'] );
	}

	public function test_the_return_link_does_not_ask_the_widget_to_resume_a_scan(): void {
		$order = wc_get_order( $this->scan_payment()->create_order( $this->booking() )['order_id'] );

		$this->assertStringNotContainsString( Scan_Payment::RETURN_REFERENCE_PARAM, $order->get_checkout_order_received_url() );
	}

	private function scan_payment(): Scan_Payment {
		$connection = new Connection();
		$client     = new Client();

		return new Scan_Payment( $connection, $client, new Scan_Pricing( $connection, $client ) );
	}

	/** @return array<string, mixed> */
	private function booking(): array {
		return array(
			'purpose'     => Scan_Payment::PURPOSE_APPOINTMENT,
			'reference'   => self::REFERENCE,
			'amount'      => 23000.0,
			'currency'    => 'NGN',
			'email'       => 'ada@example.com',
			'batch_id'    => '',
			'line_items'  => array(
				array(
					'kind'   => 'appointment',
					'name'   => 'Consultation',
					'amount' => 20000.0,
				),
				array(
					'kind'   => 'scan',
					'name'   => 'Skin scan',
					'amount' => 3000.0,
				),
			),
			'appointment' => array(
				'type'      => 'Consultation',
				'date'      => '2026-10-23',
				'startTime' => '08:30',
			),
		);
	}
}
