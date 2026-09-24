<?php
/**
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Tests\Integration;

use Oyster\Woo\Api\Companion_Access;
use Oyster\Woo\Support\Connection;
use WP_UnitTestCase;

final class CompanionAccessTest extends WP_UnitTestCase {

	private int $status = 200;

	private string $body = '{"data":{"ok":true}}';

	/** @var list<array{url:string,auth:string}> */
	private array $requests = array();

	public function set_up(): void {
		parent::set_up();

		$this->requests = array();
		$this->status   = 200;
		$this->body     = '{"data":{"ok":true}}';

		add_filter( 'pre_http_request', array( $this, 'answer' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'answer' ), 10 );
		delete_option( Connection::OPTION_KEY );

		parent::tear_down();
	}

	/**
	 * @param mixed                $preempt Short-circuit value.
	 * @param array<string, mixed> $args    Request args.
	 * @param string               $url     Request URL.
	 * @return array<string, mixed>
	 */
	public function answer( $preempt, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'auth' => (string) ( $args['headers']['Authorization'] ?? '' ),
		);

		return array(
			'response' => array( 'code' => $this->status ),
			'body'     => $this->body,
		);
	}

	private function connect(): void {
		( new Connection() )->save(
			array(
				'bearer'    => 'a-test-bearer',
				'vendor_id' => 7,
			)
		);
	}

	public function test_it_reads_the_api_as_this_store(): void {
		$this->connect();

		$result = apply_filters( Companion_Access::FILTER, null, '/api/v1/skin/delivery/abc' );

		$this->assertSame( array( 'ok' => true ), $result['data'] );
		// The path is passed through whole. The base URL is the host only, so a caller
		// omitting the version prefix gets a 404 from the API rather than an error here.
		$this->assertStringEndsWith( '/api/v1/skin/delivery/abc', $this->requests[0]['url'] );
		$this->assertSame( 'Bearer a-test-bearer', $this->requests[0]['auth'] );
	}

	public function test_it_refuses_before_the_store_is_connected(): void {
		$result = apply_filters( Companion_Access::FILTER, null, '/api/v1/skin/delivery/abc' );

		$this->assertWPError( $result );
		$this->assertSame( 'oyster_woocommerce_not_connected', $result->get_error_code() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_it_reports_the_status_so_a_caller_can_tell_a_rate_limit_from_a_refusal(): void {
		$this->connect();
		$this->status = 429;
		$this->body   = '{"message":"Too many requests"}';

		$result = apply_filters( Companion_Access::FILTER, null, '/api/v1/skin/delivery/abc' );

		$this->assertWPError( $result );
		$this->assertSame( 429, $result->get_error_data()['status'] );
	}

	public function test_one_instance_sees_what_another_wrote(): void {
		$held = new Connection();
		$this->assertFalse( $held->is_connected() );

		$this->connect();

		$this->assertTrue( $held->is_connected() );
	}

	public function test_it_needs_a_path(): void {
		$this->connect();

		$result = apply_filters( Companion_Access::FILTER, null, '' );

		$this->assertWPError( $result );
		$this->assertSame( array(), $this->requests );
	}
}
