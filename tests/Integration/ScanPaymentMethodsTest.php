<?php

declare( strict_types=1 );

namespace Oyster\Woo\Tests\Integration;

use Oyster\Woo\Support\Scan_Payment_Methods;
use WP_UnitTestCase;

/**
 * The merchant's choice of scan payment methods, against a real option store and
 * WooCommerce's real gateway registry.
 *
 * The unit suite pins the decision itself. What it cannot reach is either end of
 * it: what a stored choice looks like coming back out of the options table, and
 * whether the ids being matched are the ones WooCommerce actually uses.
 */
final class ScanPaymentMethodsTest extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( 'woocommerce_cod_settings' );
		$this->reload_gateways();

		parent::tear_down();
	}

	public function test_a_choice_round_trips(): void {
		update_option( Scan_Payment_Methods::OPTION_KEY, array( 'cod', 'bacs' ) );

		$this->assertSame( array( 'cod', 'bacs' ), Scan_Payment_Methods::chosen() );
	}

	public function test_a_store_that_has_never_chosen_offers_everything(): void {
		$this->assertSame( array(), Scan_Payment_Methods::chosen() );
	}

	/**
	 * An option holds whatever was last written to it, including by something
	 * that is not this plugin. Anything unreadable has to mean "no choice made"
	 * rather than a checkout with no way to pay.
	 */
	public function test_an_unreadable_option_reads_as_no_choice(): void {
		foreach ( array( 'cod', 42, false ) as $stored ) {
			update_option( Scan_Payment_Methods::OPTION_KEY, $stored );

			$this->assertSame( array(), Scan_Payment_Methods::chosen() );
		}
	}

	public function test_an_enabled_method_is_offered_with_the_title_shoppers_see(): void {
		update_option(
			'woocommerce_cod_settings',
			array(
				'enabled' => 'yes',
				'title'   => 'Pay on delivery',
			)
		);
		$this->reload_gateways();

		$enabled = Scan_Payment_Methods::enabled_gateways();

		$this->assertArrayHasKey( 'cod', $enabled );
		$this->assertSame( 'Pay on delivery', $enabled['cod'] );
	}

	public function test_a_disabled_method_is_not_offered(): void {
		update_option( 'woocommerce_cod_settings', array( 'enabled' => 'no' ) );
		$this->reload_gateways();

		$this->assertArrayNotHasKey( 'cod', Scan_Payment_Methods::enabled_gateways() );
	}

	/**
	 * The registry is built once per request and caches each gateway's settings,
	 * so a settings change made mid-test is invisible until it is rebuilt.
	 */
	private function reload_gateways(): void {
		WC()->payment_gateways()->init();
	}
}
