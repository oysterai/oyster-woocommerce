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

		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		delete_option( Connection::OPTION_KEY );
		( new Webhook_Secret() )->clear();
		delete_transient( 'oyster_woo_webhook_retry' );

		parent::tear_down();
	}

	/**
	 * @param mixed                $pre  Short-circuit value.
	 * @param array<string, mixed> $args Request args.
	 * @param string               $url  Request URL.
	 * @return array<string, mixed>
	 */
	public function intercept( $pre, $args, $url ) {
		$this->requests[] = array( 'url' => $url, 'method' => $args['method'] ?? 'GET' );

		return array(
			'response' => array( 'code' => $this->status ),
			'body'     => (string) wp_json_encode(
				200 === $this->status
					? array( 'data' => array( 'id' => 7, 'secret' => 'a-secret' ) )
					: array( 'message' => 'refused' )
			),
		);
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

	public function test_it_does_nothing_for_a_store_that_already_has_one(): void {
		$this->connect();
		( new Webhook_Secret() )->save( 7, 'a-secret', Receiver::url() );

		$this->registrar()->ensure_registered();

		$this->assertSame( array(), $this->requests );
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
