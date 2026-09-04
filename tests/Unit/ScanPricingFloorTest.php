<?php

declare( strict_types=1 );

namespace Oyster\Woo\Tests\Unit;

use Oyster\Woo\Support\Scan_Pricing;
use PHPUnit\Framework\TestCase;

/**
 * The floor an order for a scan is raised to.
 *
 * It exists so a tampered request cannot buy a scan for pennies that the store
 * is then billed for in full. It must not overshoot: a shopper is quoted a
 * figure by the widget before they decide to pay, and charging more than the
 * quote at the till is worse than the problem the floor solves.
 */
final class ScanPricingFloorTest extends TestCase {

	/** @return array<string, mixed> */
	private function pricing( float $customer_price ): array {
		return array(
			'collects_externally' => true,
			'customer_price'      => $customer_price,
		);
	}

	/** @return array<string, mixed> */
	private function pack( float $pack_price, bool $sellable = true ): array {
		return array(
			'enabled'    => true,
			'sellable'   => $sellable,
			'pack_price' => $pack_price,
		);
	}

	public function test_a_store_without_packs_floors_at_the_single_scan_price(): void {
		$this->assertSame( 3000.0, Scan_Pricing::lowest_price( $this->pricing( 3000.0 ), null ) );
	}

	/**
	 * A pack costs more than one scan whenever its rate is undiscounted, which
	 * was the only case that existed before Oyster could discount a pack.
	 */
	public function test_a_dearer_pack_leaves_the_floor_alone(): void {
		$this->assertSame(
			3000.0,
			Scan_Pricing::lowest_price( $this->pricing( 3000.0 ), $this->pack( 7500.0 ) )
		);
	}

	/** The reason this exists: a discounted pack undercuts a single scan. */
	public function test_a_cheaper_pack_lowers_the_floor_to_itself(): void {
		$this->assertSame(
			2000.0,
			Scan_Pricing::lowest_price( $this->pricing( 3000.0 ), $this->pack( 2000.0 ) )
		);
	}

	/** Nothing is being sold as a pack, so its price must not lower the floor. */
	public function test_an_unsellable_pack_is_ignored(): void {
		$this->assertSame(
			3000.0,
			Scan_Pricing::lowest_price( $this->pricing( 3000.0 ), $this->pack( 2000.0, sellable: false ) )
		);
	}

	public function test_a_pack_only_store_floors_at_the_pack(): void {
		$pricing = array( 'collects_externally' => true, 'customer_price' => null );

		$this->assertSame( 2000.0, Scan_Pricing::lowest_price( $pricing, $this->pack( 2000.0 ) ) );
	}

	/**
	 * No usable figure at all. The caller treats null as "do not raise the
	 * amount" rather than "refuse", so a lookup failure never strands a shopper.
	 */
	public function test_no_price_anywhere_gives_no_floor(): void {
		$pricing = array( 'collects_externally' => true, 'customer_price' => 0 );

		$this->assertNull( Scan_Pricing::lowest_price( $pricing, null ) );
	}
}
