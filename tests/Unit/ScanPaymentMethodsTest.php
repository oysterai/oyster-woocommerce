<?php

declare( strict_types=1 );

namespace Oyster\Woo\Tests\Unit;

use Oyster\Woo\Support\Scan_Payment_Methods;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Which payment methods a shopper is offered when paying for a scan.
 *
 * The decision matters twice over. Too permissive and an offline method — cash
 * on delivery, cheque — settles the order without money, releasing a scan the
 * merchant is then billed for. Too strict and a shopper is stranded mid-scan on
 * a checkout with nothing to pay by. Both halves are pinned here.
 */
final class ScanPaymentMethodsTest extends TestCase {

	/** @return array<string, stdClass> */
	private function gateways( string ...$ids ): array {
		$gateways = array();

		foreach ( $ids as $id ) {
			$gateways[ $id ] = new stdClass();
		}

		return $gateways;
	}

	/** The default, and what every store did before this setting existed. */
	public function test_choosing_nothing_offers_every_method(): void {
		$gateways = $this->gateways( 'stripe', 'cod', 'cheque' );

		$this->assertSame( $gateways, Scan_Payment_Methods::restrict( $gateways, array() ) );
	}

	public function test_only_the_chosen_methods_survive(): void {
		$restricted = Scan_Payment_Methods::restrict(
			$this->gateways( 'stripe', 'cod', 'cheque' ),
			array( 'stripe' )
		);

		$this->assertSame( array( 'stripe' ), array_keys( $restricted ) );
	}

	/**
	 * The checkout decides the order methods appear in, not the order they were
	 * ticked in — a merchant's tick order is not a preference about layout.
	 */
	public function test_the_checkouts_own_order_is_kept(): void {
		$restricted = Scan_Payment_Methods::restrict(
			$this->gateways( 'stripe', 'cod', 'paypal' ),
			array( 'paypal', 'stripe' )
		);

		$this->assertSame( array( 'stripe', 'paypal' ), array_keys( $restricted ) );
	}

	/** Chosen but unavailable for this order — it cannot be conjured back. */
	public function test_a_method_the_checkout_is_not_offering_stays_absent(): void {
		$restricted = Scan_Payment_Methods::restrict(
			$this->gateways( 'cod' ),
			array( 'stripe', 'cod' )
		);

		$this->assertSame( array( 'cod' ), array_keys( $restricted ) );
	}

	/**
	 * A merchant who disables the only method they picked has left the store
	 * unable to take scan payments. Falling back to every method would quietly
	 * reinstate the ones they ruled out — the admin screen warns instead.
	 */
	public function test_a_choice_matching_nothing_offers_nothing(): void {
		$this->assertSame(
			array(),
			Scan_Payment_Methods::restrict( $this->gateways( 'cod' ), array( 'stripe' ) )
		);
	}

	public function test_only_methods_the_checkout_offers_can_be_saved(): void {
		$saved = Scan_Payment_Methods::sanitize(
			array( 'stripe', 'not-a-gateway' ),
			array( 'stripe', 'cod' )
		);

		$this->assertSame( array( 'stripe' ), $saved );
	}

	public function test_duplicates_collapse_and_the_result_is_a_list(): void {
		$saved = Scan_Payment_Methods::sanitize(
			array( 'cod', 'cod', 'stripe' ),
			array( 'stripe', 'cod' )
		);

		$this->assertSame( array( 'cod', 'stripe' ), $saved );
	}

	/** Unticking everything posts no field at all — that is "offer them all". */
	public function test_an_empty_submission_saves_an_empty_choice(): void {
		$this->assertSame( array(), Scan_Payment_Methods::sanitize( array(), array( 'stripe' ) ) );
	}

	/** The submission is a form post, so it arrives as whatever a browser sent. */
	public function test_a_non_array_submission_is_survivable(): void {
		foreach ( array( 'stripe', 42, null, true ) as $input ) {
			$this->assertSame( array(), Scan_Payment_Methods::sanitize( $input, array( 'stripe' ) ) );
		}
	}

	/**
	 * What is stored is always a subset of the ids the checkout itself offers.
	 * That intersection is the guard, not the sanitiser — these ids are matched
	 * against live gateways, so anything that is not one of them is nothing.
	 */
	public function test_what_is_stored_is_a_subset_of_the_checkouts_own_ids(): void {
		$enabled = array( 'stripe', 'cod' );

		foreach ( array( 'cod"', '../../etc/passwd', 'STRIPE', '<script>alert(1)</script>' ) as $bad ) {
			$this->assertSame(
				array(),
				Scan_Payment_Methods::sanitize( array( $bad ), $enabled ),
				$bad . ' must not resolve to a gateway'
			);
		}
	}
}
