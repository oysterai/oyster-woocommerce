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
 * Telling Oyster what happened to a scan payment, when the telling goes wrong.
 *
 * A shopper who pays for a scan the store cannot report has been charged for
 * nothing, and the order shows no sign of it: it is paid and complete. So every
 * way that call can fail is covered here, because all of them used to end in a
 * silent `return`.
 *
 * Oyster's response is faked at the HTTP boundary rather than by substituting a
 * client, which keeps the real request-building and the real error mapping in
 * the path under test.
 */
final class ScanConfirmationTest extends WP_UnitTestCase {

	private const REFERENCE = 'scan-ref-123';

	/** @var list<array{url: string}> */
	private array $requests = array();

	/** @var int|string Status to answer confirmations with, or 'error' for a transport failure. */
	private int|string $answer = 200;

	public function set_up(): void {
		parent::set_up();

		$this->requests = array();
		$this->answer   = 200;

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
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>|\WP_Error|false
	 */
	public function answer_request( $preempt, array $args, string $url ) {
		if ( ! str_contains( $url, '/external/confirm' ) ) {
			return $preempt;
		}

		$this->requests[] = array( 'url' => $url );

		if ( 'error' === $this->answer ) {
			return new \WP_Error( 'http_request_failed', 'Connection timed out' );
		}

		return array(
			'response' => array(
				'code'    => $this->answer,
				'message' => 'x',
			),
			'body'     => wp_json_encode( array( 'data' => array( 'status' => 'success' ) ) ),
			'headers'  => array(),
			'cookies'  => array(),
		);
	}

	private function scan_payment(): Scan_Payment {
		$connection = new Connection();
		$client     = new Client();

		return new Scan_Payment( $connection, $client, new Scan_Pricing( $connection, $client ) );
	}

	private function scan_order(): \WC_Order {
		$order = wc_create_order();
		$order->update_meta_data( Scan_Payment::ORDER_META_REFERENCE, self::REFERENCE );
		$order->save();

		return $order;
	}

	private function notes_for( int $order_id ): string {
		return implode(
			' ',
			wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order_id ) ), 'content' )
		);
	}

	// ── A failed payment is not the last word ───────────────────────────────

	public function test_a_shopper_who_pays_after_a_failure_still_gets_their_scan(): void {
		$payment = $this->scan_payment();
		$order   = $this->scan_order();

		$payment->on_not_paid( $order->get_id() );
		$payment->on_paid( $order->get_id() );

		$this->assertCount( 2, $this->requests, 'the success after a failure must be reported' );
	}

	public function test_a_settled_payment_is_never_reported_twice(): void {
		$payment = $this->scan_payment();
		$order   = $this->scan_order();

		// WooCommerce fires several of these for one transition.
		$payment->on_paid( $order->get_id() );
		$payment->on_paid( $order->get_id() );
		$payment->on_not_paid( $order->get_id() );

		$this->assertCount( 1, $this->requests );
	}

	public function test_the_same_failure_is_not_reported_repeatedly(): void {
		$payment = $this->scan_payment();
		$order   = $this->scan_order();

		$payment->on_not_paid( $order->get_id() );
		$payment->on_not_paid( $order->get_id() );

		$this->assertCount( 1, $this->requests );
	}

	// ── Nothing fails silently ──────────────────────────────────────────────

	public function test_an_unconnected_store_says_so_on_the_order_and_across_wp_admin(): void {
		delete_option( Connection::OPTION_KEY );

		$order = $this->scan_order();
		$this->scan_payment()->on_paid( $order->get_id() );

		$this->assertSame( array(), $this->requests );
		$this->assertTrue( Scan_Confirmation_Health::is_blocked() );
		$this->assertStringContainsString( 'not connected', $this->notes_for( $order->get_id() ) );
	}

	public function test_a_refused_credential_is_raised_rather_than_retried(): void {
		$this->answer = 401;

		$order = $this->scan_order();
		$this->scan_payment()->on_paid( $order->get_id() );

		$this->assertTrue( Scan_Confirmation_Health::is_blocked() );
		$this->assertStringContainsString( 'Reconnect', $this->notes_for( $order->get_id() ) );
		$this->assertFalse(
			wp_next_scheduled( Scan_Payment::RETRY_HOOK, array( $order->get_id(), 'success' ) ),
			'a refused credential does not recover on its own, so retrying loops forever'
		);
	}

	public function test_an_outage_is_retried_without_alarming_the_merchant(): void {
		$this->answer = 'error';

		$order = $this->scan_order();
		$this->scan_payment()->on_paid( $order->get_id() );

		$this->assertNotFalse(
			wp_next_scheduled( Scan_Payment::RETRY_HOOK, array( $order->get_id(), 'success' ) )
		);
		$this->assertFalse(
			Scan_Confirmation_Health::is_blocked(),
			'a banner raised on every blip is one a merchant stops reading'
		);
	}

	public function test_retrying_stops_rather_than_looping_forever(): void {
		$this->answer = 500;

		$order   = $this->scan_order();
		$id      = $order->get_id();
		$payment = $this->scan_payment();

		for ( $attempt = 0; $attempt < 8; $attempt++ ) {
			wp_clear_scheduled_hook( Scan_Payment::RETRY_HOOK, array( $id, 'success' ) );
			$payment->retry_confirmation( $id, 'success' );
		}

		$this->assertFalse( wp_next_scheduled( Scan_Payment::RETRY_HOOK, array( $id, 'success' ) ) );
		$this->assertStringContainsString( 'Gave up', $this->notes_for( $id ) );
	}

	public function test_a_recovered_store_clears_the_warning(): void {
		$this->answer = 403;

		$order   = $this->scan_order();
		$payment = $this->scan_payment();
		$payment->on_paid( $order->get_id() );
		$this->assertTrue( Scan_Confirmation_Health::is_blocked() );

		$this->answer = 200;
		$payment->retry_confirmation( $order->get_id(), 'success' );

		$this->assertFalse( Scan_Confirmation_Health::is_blocked() );
	}

	public function test_an_ordinary_store_order_is_left_alone(): void {
		$order = wc_create_order();
		$order->save();

		$this->scan_payment()->on_paid( $order->get_id() );

		$this->assertSame( array(), $this->requests );
		$this->assertFalse( Scan_Confirmation_Health::is_blocked() );
	}
}
