<?php

declare( strict_types=1 );

namespace Oyster\Woo\Tests\Unit;

use Oyster\Woo\Support\Url_Guard;
use PHPUnit\Framework\TestCase;

/**
 * The allow-list that decides where this plugin will send a bearer token, load a
 * storefront script from, and point an admin's browser at.
 *
 * These cases were verified once with a throwaway script when the check was
 * written, which left nothing to stop a later edit quietly reopening the hole.
 * The domain-confusion cases in particular are the whole point: a substring
 * check passes every one of them.
 */
final class UrlGuardTest extends TestCase {

	/** Each constant is defined once per process; PHP has no undefine. */
	private static int $counter = 0;

	private function resolveWith( string $value, string $default = 'https://api.oysterskin.com' ): string {
		$constant = 'OYSTER_WOO_TEST_URL_' . ( ++self::$counter );
		define( $constant, $value );

		return Url_Guard::resolve( $constant, $default );
	}

	public function test_an_undefined_constant_uses_the_default(): void {
		$this->assertSame(
			'https://api.oysterskin.com',
			Url_Guard::resolve( 'OYSTER_WOO_DEFINITELY_NOT_DEFINED', 'https://api.oysterskin.com' )
		);
	}

	/**
	 * @dataProvider allowedHosts
	 */
	public function test_oyster_hosts_are_accepted_over_https( string $url ): void {
		$this->assertSame( $url, $this->resolveWith( $url ) );
	}

	/** @return array<string, array{string}> */
	public static function allowedHosts(): array {
		return array(
			'root domain'          => array( 'https://oysterskin.com' ),
			'subdomain'            => array( 'https://api.oysterskin.com' ),
			'deep subdomain'       => array( 'https://api.sandbox.oysterskin.com' ),
			'second root domain'   => array( 'https://oysterskin.ai' ),
			'third root domain'    => array( 'https://dash.oyster.skin' ),
		);
	}

	/**
	 * The reason the check is a suffix match with the dot boundary enforced,
	 * and not `str_contains`. Every one of these contains an allowed root
	 * domain as a substring.
	 *
	 * @dataProvider domainConfusion
	 */
	public function test_lookalike_hosts_are_rejected( string $url ): void {
		$this->assertSame(
			'https://api.oysterskin.com',
			$this->resolveWith( $url ),
			$url . ' must not be treated as Oyster infrastructure'
		);
	}

	/** @return array<string, array{string}> */
	public static function domainConfusion(): array {
		return array(
			'prefixed root'        => array( 'https://notoysterskin.com' ),
			'suffixed root'        => array( 'https://oysterskin.com.evil.example' ),
			'tld swap'             => array( 'https://oysterskin.com.ai' ),
			'hyphenated'           => array( 'https://evil-oysterskin.com' ),
			'root as a path'       => array( 'https://evil.example/oysterskin.com' ),
			'root in userinfo'     => array( 'https://oysterskin.com@evil.example' ),
			'unrelated host'       => array( 'https://example.com' ),
		);
	}

	/**
	 * http is for a local dev tunnel only. Anything else over http would be a
	 * plausible production redirect target, which is the thing to refuse.
	 *
	 * @dataProvider loopbackHosts
	 */
	public function test_http_is_accepted_only_on_loopback( string $url ): void {
		$this->assertSame( $url, $this->resolveWith( $url ) );
	}

	/** @return array<string, array{string}> */
	public static function loopbackHosts(): array {
		return array(
			'localhost'        => array( 'http://localhost:8000' ),
			'ipv4 loopback'    => array( 'http://127.0.0.1:8000' ),
			// parse_url keeps the brackets on an IPv6 literal, so the host is
			// "[::1]" and not "::1" — a real bug this once hid.
			'ipv6 loopback'    => array( 'http://[::1]:8000' ),
		);
	}

	public function test_http_is_rejected_off_loopback(): void {
		$this->assertSame( 'https://api.oysterskin.com', $this->resolveWith( 'http://oysterskin.com' ) );
		$this->assertSame( 'https://api.oysterskin.com', $this->resolveWith( 'http://evil.example' ) );
	}

	/** @dataProvider malformed */
	public function test_malformed_values_fall_back( string $url ): void {
		$this->assertSame( 'https://api.oysterskin.com', $this->resolveWith( $url ) );
	}

	/** @return array<string, array{string}> */
	public static function malformed(): array {
		return array(
			'empty'         => array( '' ),
			'no scheme'     => array( 'oysterskin.com' ),
			'scheme only'   => array( 'https://' ),
			'not a url'     => array( 'not a url at all' ),
			'javascript'    => array( 'javascript:alert(1)' ),
			'data uri'      => array( 'data:text/html,<script>' ),
			'ftp'           => array( 'ftp://oysterskin.com' ),
		);
	}

	public function test_a_trailing_slash_is_normalised_away(): void {
		$this->assertSame( 'https://api.oysterskin.com', $this->resolveWith( 'https://api.oysterskin.com/' ) );
	}

	/** Case in the host is not significant; an uppercased host is still Oyster's. */
	public function test_host_matching_is_case_insensitive(): void {
		$this->assertSame( 'https://API.OysterSkin.com', $this->resolveWith( 'https://API.OysterSkin.com' ) );
	}
}
