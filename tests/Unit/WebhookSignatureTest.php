<?php
/**
 * The one check standing between a public REST route and this site's hooks.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Tests\Unit;

use Oyster\Woo\Webhooks\Signature;
use PHPUnit\Framework\TestCase;

final class WebhookSignatureTest extends TestCase {

	private const SECRET = 'whsec_test_secret';

	private const NOW = 1758499200;

	public function test_it_accepts_a_correctly_signed_delivery(): void {
		$body = '{"id":"01JD","event":"scan.completed"}';

		$this->assertTrue(
			Signature::is_valid( $this->sign( $body, self::NOW ), $body, self::SECRET, self::NOW )
		);
	}

	public function test_it_rejects_a_tampered_body(): void {
		$header = $this->sign( '{"batch_id":"mine"}', self::NOW );

		$this->assertFalse(
			Signature::is_valid( $header, '{"batch_id":"theirs"}', self::SECRET, self::NOW )
		);
	}

	public function test_it_rejects_a_signature_from_another_secret(): void {
		$body   = '{"event":"scan.completed"}';
		$header = 't=' . self::NOW . ',v1=' . hash_hmac( 'sha256', self::NOW . '.' . $body, 'whsec_someone_else' );

		$this->assertFalse( Signature::is_valid( $header, $body, self::SECRET, self::NOW ) );
	}

	/**
	 * The timestamp is inside the signed material, so moving it invalidates the
	 * digest. A replayer cannot simply restate it to get back inside the window.
	 */
	public function test_it_rejects_a_replay_with_a_restated_timestamp(): void {
		$body     = '{"event":"scan.completed"}';
		$captured = $this->sign( $body, self::NOW );
		$digest   = explode( 'v1=', $captured )[1];

		$replayed = 't=' . ( self::NOW + 10000 ) . ',v1=' . $digest;

		$this->assertFalse( Signature::is_valid( $replayed, $body, self::SECRET, self::NOW + 10000 ) );
	}

	public function test_it_rejects_a_delivery_older_than_the_tolerance(): void {
		$body = '{"event":"scan.completed"}';
		$old  = self::NOW - ( Signature::TOLERANCE_SECONDS + 1 );

		$this->assertFalse(
			Signature::is_valid( $this->sign( $body, $old ), $body, self::SECRET, self::NOW )
		);
	}

	/** Clock skew cuts both ways, so a future timestamp is bounded too. */
	public function test_it_rejects_a_delivery_too_far_in_the_future(): void {
		$body   = '{"event":"scan.completed"}';
		$future = self::NOW + ( Signature::TOLERANCE_SECONDS + 1 );

		$this->assertFalse(
			Signature::is_valid( $this->sign( $body, $future ), $body, self::SECRET, self::NOW )
		);
	}

	public function test_it_accepts_a_delivery_at_the_edge_of_the_tolerance(): void {
		$body = '{"event":"scan.completed"}';
		$edge = self::NOW - Signature::TOLERANCE_SECONDS;

		$this->assertTrue(
			Signature::is_valid( $this->sign( $body, $edge ), $body, self::SECRET, self::NOW )
		);
	}

	/**
	 * @dataProvider malformedHeaders
	 */
	public function test_it_rejects_malformed_headers( string $header ): void {
		$this->assertFalse( Signature::is_valid( $header, '{}', self::SECRET, self::NOW ) );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function malformedHeaders(): array {
		return array(
			'empty'                => array( '' ),
			'no timestamp'         => array( 'v1=deadbeef' ),
			'no digest'            => array( 't=1758499200' ),
			'non-numeric t'        => array( 't=abc,v1=deadbeef' ),
			// (int) '' is 0, which would otherwise be compared against the clock.
			'blank t'              => array( 't=,v1=deadbeef' ),
			'negative t'           => array( 't=-1758499200,v1=deadbeef' ),
			'digest only'          => array( 'deadbeef' ),
			'wrong version key'    => array( 't=1758499200,v2=deadbeef' ),
		);
	}

	/** A store with no secret yet must not accept anything at all. */
	public function test_it_rejects_everything_when_no_secret_is_stored(): void {
		$body = '{"event":"scan.completed"}';

		$this->assertFalse(
			Signature::is_valid( 't=' . self::NOW . ',v1=' . hash_hmac( 'sha256', self::NOW . '.' . $body, '' ), $body, '', self::NOW )
		);
	}

	private function sign( string $body, int $timestamp ): string {
		return 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, self::SECRET );
	}
}
