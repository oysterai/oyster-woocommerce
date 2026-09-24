<?php
/**
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Tests\Integration;

use WP_UnitTestCase;

use function Oyster\Woo\requirement_notice;

final class RequirementNoticeTest extends WP_UnitTestCase {

	public function test_it_tells_someone_who_can_install_a_plugin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		requirement_notice( 'WooCommerce is not active.' );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'WooCommerce is not active.', $output );
		$this->assertStringContainsString( 'notice-error', $output );
	}

	public function test_it_stays_quiet_for_someone_who_could_not_act_on_it(): void {
		// admin_notices renders on every admin screen, so without this a subscriber
		// carries a permanent error they have no way to clear.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		ob_start();
		requirement_notice( 'WooCommerce is not active.' );

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_it_escapes_what_it_prints(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		requirement_notice( '<script>alert(1)</script>' );

		$this->assertStringNotContainsString( '<script>', (string) ob_get_clean() );
	}
}
