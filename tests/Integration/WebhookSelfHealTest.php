<?php
/**
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Tests\Integration;

use Oyster\Woo\Api\Client;
use Oyster\Woo\Support\Connection;
use Oyster\Woo\Webhooks\Receiver;
use Oyster\Woo\Webhooks\Registrar;
use Oyster\Woo\Webhooks\Webhook_Secret;
use WP_UnitTestCase;

/**
 * Registration only ever ran at connect, so a store that connected before it existed, or
 * one whose registration was refused, silently received nothing.
 */
final class WebhookSelfHealTest extends WP_UnitTestCase {

	/** @var list<array<string, mixed>> */
	private array $requests = array();

	private int $status = 200;

	public function set_up(): void {
		parent::set_up();

		$this->requests = array();
		$this->status   = 200;
		$this->remote   = null;
		$this->test_succeeds = true;

		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		delete_option( Connection::OPTION_KEY );
		( new Webhook_Secret() )->clear();
		delete_transient( 'oyster_woo_webhook_retry' );

		parent::tear_down();
	}

	/** Our endpoint as Oyster holds it, or null once it is gone. */
	private ?array $remote = null;

	private bool $test_succeeds = true;

	/**
	 * Oyster's endpoint API as the plugin sees it.
	 *
	 * @param mixed                $pre  Short-circuit value.
	 * @param array<string, mixed> $args Request args.
	 * @param string               $url  Request URL.
	 * @return array<string, mixed>
	 */
	public function intercept( $pre, $args, $url ) {
		$method           = $args['method'] ?? 'GET';
		$this->requests[] = array( 'url' => $url, 'method' => $method );
		$body             = json_decode( (string) ( $args['body'] ?? '' ), true );

		if ( 200 !== $this->status ) {
			$data = null;
		} elseif ( str_ends_with( $url, '/test' ) ) {
			$data = array( 'succeeded' => $this->test_succeeds );
		} elseif ( 'GET' === $method ) {
			$data = null === $this->remote ? array() : array( $this->remote );
		} elseif ( 'PATCH' === $method ) {
			$this->remote = array_merge( (array) $this->remote, (array) $body, ! empty( $body['is_active'] ) ? array( 'disabled_reason' => null ) : array() );
			$data         = $this->remote;
		} else {
			$this->remote = array( 'id' => 7, 'events' => $body['events'] ?? array(), 'is_active' => true, 'disabled_reason' => null );
			$data         = array( 'id' => 7, 'secret' => 'a-secret' );
		}

		return array(
			'response' => array( 'code' => $this->status ),
			'body'     => (string) wp_json_encode( null === $data ? array( 'message' => 'refused' ) : array( 'data' => $data ) ),
		);
	}

	/** A store registered earlier, with its endpoint as Oyster now has it. */
	private function registered( array $remote = array(), array $local_events = Registrar::EVENTS ): void {
		$this->connect();
		( new Webhook_Secret() )->save( 7, 'a-secret', Receiver::url(), $local_events );
		$this->remote = array_merge( array( 'id' => 7, 'events' => Registrar::EVENTS, 'is_active' => true, 'disabled_reason' => null ), $remote );
	}

	/** @return list<string> */
	private function calls(): array {
		return array_map( fn ( $r ) => $r['method'] . ' ' . preg_replace( '#^.*/webhook-endpoints#', '', $r['url'] ), $this->requests );
	}

	private function connect(): void {
		( new Connection() )->save( array( 'bearer' => 'a-bearer', 'vendor_id' => 3 ) );
	}

	private function registrar(): Registrar {
		return new Registrar( new Connection(), new Client(), new Webhook_Secret() );
	}

	public function test_the_self_heal_runs_on_admin_init(): void {
		// Without this the method is only ever reached by a test calling it directly, and
		// dropping the hook costs nothing that fails.
		$wired = false;

		foreach ( (array) ( $GLOBALS['wp_filter']['admin_init']->callbacks ?? array() ) as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn = $callback['function'] ?? null;

				if ( is_array( $fn ) && $fn[0] instanceof Registrar && 'ensure_registered' === $fn[1] ) {
					$wired = true;
				}
			}
		}

		$this->assertTrue( $wired, 'ensure_registered is not hooked to admin_init' );
	}

	public function test_a_connected_store_without_a_destination_registers_one(): void {
		$this->connect();

		$this->registrar()->ensure_registered();

		$this->assertTrue( ( new Webhook_Secret() )->is_registered() );
		$this->assertSame( Receiver::url(), ( new Webhook_Secret() )->registered_url() );
		$this->assertCount( 1, $this->requests );
	}

	public function test_a_healthy_endpoint_costs_one_look_an_hour(): void {
		$this->registered();

		$this->registrar()->ensure_registered();
		$this->registrar()->ensure_registered();

		$this->assertSame( array( 'GET ' ), $this->calls() );
	}

	public function test_an_endpoint_registered_before_wheel_wins_existed_subscribes_to_them_once(): void {
		$this->registered( array( 'events' => array( 'scan.completed', 'recommendation.ready' ) ), array() );

		$this->registrar()->ensure_registered();
		$this->registrar()->ensure_registered();

		$this->assertSame( array( 'GET ', 'PATCH /7' ), $this->calls() );
		$this->assertContains( 'wheel.reward_won', $this->remote['events'] );
		$this->assertContains( 'wheel.reward_won', ( new Webhook_Secret() )->events() );
	}

	public function test_an_endpoint_switched_off_for_failing_comes_back_once_a_test_gets_through(): void {
		// Oyster switches an endpoint off after repeated failures, and until it is back on
		// this store hears no wins and its rewards are not offered.
		$this->registered( array( 'is_active' => false, 'disabled_reason' => '10 consecutive delivery failures' ) );

		$this->registrar()->ensure_registered();

		$this->assertSame( array( 'GET ', 'POST /7/test', 'PATCH /7' ), $this->calls() );
		$this->assertTrue( $this->remote['is_active'] );
	}

	public function test_it_stays_off_while_test_deliveries_still_fail(): void {
		$this->registered( array( 'is_active' => false, 'disabled_reason' => '10 consecutive delivery failures' ) );
		$this->test_succeeds = false;

		$this->registrar()->ensure_registered();

		$this->assertSame( array( 'GET ', 'POST /7/test' ), $this->calls() );
		$this->assertFalse( $this->remote['is_active'] );
	}

	public function test_a_pause_the_merchant_chose_is_left_alone(): void {
		$this->registered( array( 'is_active' => false, 'disabled_reason' => null ) );

		$this->registrar()->ensure_registered();

		$this->assertSame( array( 'GET ' ), $this->calls() );
		$this->assertFalse( $this->remote['is_active'] );
	}

	public function test_an_endpoint_deleted_at_oyster_is_registered_again(): void {
		$this->registered();
		$this->remote = null;

		$this->registrar()->ensure_registered();

		$this->assertSame( array( 'GET ', 'POST ' ), $this->calls() );
		$this->assertTrue( ( new Webhook_Secret() )->is_registered() );
		$this->assertSame( 7, $this->remote['id'] );
	}

	public function test_the_daily_heartbeat_heals_the_subscription_before_publishing_capabilities(): void {
		// A store owner may never open the admin after updating, and capabilities published
		// without the subscription would offer rewards the store never hears about.
		$priorities = array();
		foreach ( (array) ( $GLOBALS['wp_filter'][ \Oyster\Woo\Wheel\Capabilities::HOOK ]->callbacks ?? array() ) as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn = $callback['function'] ?? null;
				if ( is_array( $fn ) ) {
					$priorities[ $fn[1] ] = $priority;
				}
			}
		}

		$this->assertArrayHasKey( 'ensure_registered', $priorities );
		$this->assertLessThan( $priorities['refresh'], $priorities['ensure_registered'] );
	}

	public function test_it_does_nothing_for_a_store_that_is_not_connected(): void {
		$this->registrar()->ensure_registered();

		$this->assertSame( array(), $this->requests );
	}

	public function test_a_refusal_is_not_retried_on_every_page_load(): void {
		// Otherwise a store whose registration keeps failing calls the API once per
		// admin request, forever.
		$this->connect();
		$this->status = 403;

		$this->registrar()->ensure_registered();
		$this->registrar()->ensure_registered();
		$this->registrar()->ensure_registered();

		$this->assertCount( 1, $this->requests );
		$this->assertFalse( ( new Webhook_Secret() )->is_registered() );
	}

	public function test_it_tries_again_once_the_backoff_expires(): void {
		$this->connect();
		$this->status = 403;

		$this->registrar()->ensure_registered();
		delete_transient( 'oyster_woo_webhook_retry' );

		$this->status = 200;
		$this->registrar()->ensure_registered();

		$this->assertTrue( ( new Webhook_Secret() )->is_registered() );
		$this->assertCount( 2, $this->requests );
	}
}
