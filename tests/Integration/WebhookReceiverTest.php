<?php
/**
 * The REST route Oyster posts to, and the actions it fires.
 *
 * Integration rather than unit because the whole point is the WordPress
 * machinery: a real REST dispatch, real header canonicalisation, real
 * do_action, and a real add_option claim under a duplicate delivery.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Tests\Integration;

use Oyster\Woo\Webhooks\Receiver;
use Oyster\Woo\Webhooks\Webhook_Secret;
use WP_REST_Request;
use WP_UnitTestCase;

final class WebhookReceiverTest extends WP_UnitTestCase {

	private const SECRET = 'whsec_integration_secret';

	private const ROUTE = '/oyster-woocommerce/v1/webhook';

	private Webhook_Secret $store;

	public function set_up(): void {
		parent::set_up();

		$this->store = new Webhook_Secret();
		$this->store->save( 42, self::SECRET );

		// Fresh server per test. The REST server is a singleton, so without this
		// the route stays bound to an earlier test's Receiver (and its already-read
		// secret), and clearing the secret here would not be seen.
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();

		( new Receiver( $this->store ) )->register();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		$this->store->clear();
		parent::tear_down();
	}

	public function test_a_signed_scan_event_fires_the_action(): void {
		$fired = array();
		add_action(
			'oyster_woocommerce_scan_completed',
			function ( $batch_id, $payload ) use ( &$fired ) {
				$fired[] = array( $batch_id, $payload );
			},
			10,
			2
		);

		$response = $this->deliver( 'scan.completed', 'batch-123' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $fired );
		$this->assertSame( 'batch-123', $fired[0][0] );
		$this->assertSame( 'scan.completed', $fired[0][1]['event'] );
	}

	public function test_a_signed_recommendation_event_fires_its_own_action(): void {
		$fired = array();
		add_action(
			'oyster_woocommerce_recommendations_ready',
			function ( $batch_id ) use ( &$fired ) {
				$fired[] = $batch_id;
			}
		);

		$this->deliver( 'recommendation.ready', 'batch-456' );

		$this->assertSame( array( 'batch-456' ), $fired );
	}

	public function test_a_bad_signature_fires_nothing(): void {
		$fired = 0;
		add_action( 'oyster_woocommerce_scan_completed', function () use ( &$fired ) { ++$fired; } );

		$body    = $this->body( 'scan.completed', 'batch-123' );
		$request = $this->request( $body, 't=' . time() . ',v1=' . str_repeat( 'a', 64 ), 'delivery-1' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 0, $fired );
	}

	/**
	 * Delivery is at-least-once: a retry after a timeout that actually succeeded
	 * arrives again. Without the claim a listener sends two emails.
	 */
	public function test_a_repeated_delivery_id_fires_the_action_once(): void {
		$fired = 0;
		add_action( 'oyster_woocommerce_scan_completed', function () use ( &$fired ) { ++$fired; } );

		$this->deliver( 'scan.completed', 'batch-123', 'delivery-dupe' );
		$second = $this->deliver( 'scan.completed', 'batch-123', 'delivery-dupe' );

		$this->assertSame( 1, $fired );
		$this->assertSame( 200, $second->get_status() );
		$this->assertTrue( $second->get_data()['duplicate'] );
	}

	/**
	 * An event a later Oyster release sends but this version does not know must
	 * be acknowledged, not refused: 4xx would trip the circuit breaker and
	 * silence the events this version DOES handle.
	 */
	public function test_an_unknown_event_is_acknowledged_not_refused(): void {
		$response = $this->deliver( 'something.new', 'batch-123' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'something.new', $response->get_data()['ignored'] );
	}

	public function test_it_refuses_everything_before_an_endpoint_is_registered(): void {
		$this->store->clear();

		$response = $this->deliver( 'scan.completed', 'batch-123' );

		$this->assertSame( 503, $response->get_status() );
	}

	public function test_a_delivery_records_the_last_event_for_the_admin_screen(): void {
		$this->deliver( 'scan.completed', 'batch-123' );

		$fresh = new Webhook_Secret();

		$this->assertSame( 'scan.completed', $fresh->last_event() );
		$this->assertGreaterThan( 0, $fresh->last_event_at() );
	}

	private function deliver( string $event, string $batch_id, string $delivery = null ): \WP_REST_Response {
		$body      = $this->body( $event, $batch_id );
		$timestamp = time();
		$signature = 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, self::SECRET );

		return rest_get_server()->dispatch(
			$this->request( $body, $signature, $delivery ?? 'delivery-' . wp_generate_uuid4() )
		);
	}

	private function body( string $event, string $batch_id ): string {
		return (string) wp_json_encode(
			array(
				'id'         => 'delivery-body-id',
				'event'      => $event,
				'created_at' => gmdate( 'c' ),
				'data'       => array( 'batch_id' => $batch_id, 'vendor_id' => 42 ),
			)
		);
	}

	private function request( string $body, string $signature, string $delivery ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Oyster-Signature', $signature );
		$request->set_header( 'Oyster-Event', 'scan.completed' );
		$request->set_header( 'Oyster-Delivery', $delivery );
		$request->set_body( $body );

		return $request;
	}
}
