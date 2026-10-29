<?php
/**
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Tests\Integration;

use Oyster\Woo\Support\Url_Guard;
use WP_UnitTestCase;

/**
 * A rejected wp-config address falls back to production. Without this notice
 * the only trace is a log line, so a store pointed at a local or staging
 * service quietly talks to the live one instead.
 */
final class UrlGuardNoticeTest extends WP_UnitTestCase {

	private function render( string ...$constants ): string {
		ob_start();
		Url_Guard::render_rejection_notice( ...$constants );

		return (string) ob_get_clean();
	}

	public function test_it_names_a_rejected_constant_and_its_value(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		define( 'OYSTER_WOO_TEST_NOTICE_REJECTED', 'https://tunnel.example.com/widget.js' );

		$output = $this->render( 'OYSTER_WOO_TEST_NOTICE_REJECTED' );

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 'OYSTER_WOO_TEST_NOTICE_REJECTED', $output );
		$this->assertStringContainsString( 'https://tunnel.example.com/widget.js', $output );
	}

	public function test_it_stays_quiet_for_allowed_and_unset_constants(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		define( 'OYSTER_WOO_TEST_NOTICE_ALLOWED', 'http://localhost:4183/widget.js' );

		$this->assertSame( '', $this->render( 'OYSTER_WOO_TEST_NOTICE_ALLOWED', 'OYSTER_WOO_TEST_NOTICE_NEVER_DEFINED' ) );
	}

	public function test_it_stays_quiet_for_someone_who_could_not_act_on_it(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		define( 'OYSTER_WOO_TEST_NOTICE_HIDDEN', 'https://evil.example' );

		$this->assertSame( '', $this->render( 'OYSTER_WOO_TEST_NOTICE_HIDDEN' ) );
	}

	public function test_it_escapes_the_value_it_prints(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		define( 'OYSTER_WOO_TEST_NOTICE_ESCAPED', 'https://x.example/"><script>alert(1)</script>' );

		$this->assertStringNotContainsString( '<script>', $this->render( 'OYSTER_WOO_TEST_NOTICE_ESCAPED' ) );
	}
}
