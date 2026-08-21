<?php

declare( strict_types=1 );

namespace Oyster\Woo\Tests\Unit;

use Oyster\Woo\Api\Api_Exception;
use PHPUnit\Framework\TestCase;

/**
 * Telling a refused credential apart from every other failure.
 *
 * The distinction decides what a merchant is told to do about it: reloading
 * fixes an API that was briefly unreachable, and does nothing at all for a
 * credential Oyster will keep refusing.
 */
final class ApiExceptionTest extends TestCase {

	public function test_unauthorised_and_forbidden_are_refusals(): void {
		foreach ( array( 401, 403 ) as $status ) {
			$this->assertTrue(
				( new Api_Exception( $status ) )->denies_access(),
				$status . ' is Oyster refusing the credential'
			);
		}
	}

	/**
	 * Everything else is Oyster failing to answer, including a transport error,
	 * which carries status 0 rather than an HTTP code.
	 */
	public function test_other_failures_are_not_refusals(): void {
		foreach ( array( 0, 404, 422, 429, 500, 502, 503 ) as $status ) {
			$this->assertFalse(
				( new Api_Exception( $status ) )->denies_access(),
				$status . ' is not a refused credential'
			);
		}
	}

	public function test_a_transport_error_still_reports_itself_as_one(): void {
		$this->assertTrue( ( new Api_Exception( 0 ) )->is_transport_error() );
		$this->assertFalse( ( new Api_Exception( 403 ) )->is_transport_error() );
	}
}
