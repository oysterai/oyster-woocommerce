<?php

declare( strict_types=1 );

namespace Oyster\Woo\Tests\Integration;

use Oyster\Woo\Sync\Sync_State;
use WP_UnitTestCase;

/**
 * Sync state round-tripping through real post meta.
 *
 * The unit suite cannot reach this: every method here is a thin wrapper over
 * WordPress' meta API, and a stubbed meta store would only prove the stub works.
 */
final class SyncStateTest extends WP_UnitTestCase {

	private int $product_id;

	public function set_up(): void {
		parent::set_up();
		$this->product_id = self::factory()->post->create( array( 'post_type' => 'product' ) );
	}

	public function test_an_untouched_product_reports_nothing(): void {
		$state = Sync_State::get( $this->product_id );

		$this->assertNull( $state['oyster_id'] );
		$this->assertNull( $state['synced_at'] );
		$this->assertFalse( Sync_State::is_synced( $this->product_id ) );
	}

	public function test_a_sync_round_trips(): void {
		$at = time();
		Sync_State::mark_synced( $this->product_id, '1240123', $at );

		$state = Sync_State::get( $this->product_id );

		$this->assertSame( '1240123', $state['oyster_id'] );
		$this->assertSame( $at, $state['synced_at'] );
		$this->assertTrue( Sync_State::is_synced( $this->product_id ) );
	}

	/** Re-syncing must overwrite rather than accumulate meta rows. */
	public function test_resyncing_replaces_the_previous_id(): void {
		Sync_State::mark_synced( $this->product_id, '111', time() - 100 );
		Sync_State::mark_synced( $this->product_id, '222', time() );

		$this->assertSame( '222', Sync_State::get( $this->product_id )['oyster_id'] );
		$this->assertCount( 1, get_post_meta( $this->product_id, Sync_State::META_OYSTER_ID ) );
	}

	public function test_clearing_removes_everything(): void {
		Sync_State::mark_synced( $this->product_id, '1240123', time() );
		Sync_State::clear( $this->product_id );

		$state = Sync_State::get( $this->product_id );

		$this->assertNull( $state['oyster_id'] );
		$this->assertNull( $state['synced_at'] );
		$this->assertSame( array(), get_post_meta( $this->product_id, Sync_State::META_OYSTER_ID ) );
	}

	/**
	 * State is per product. A bug that wrote to the wrong id would be invisible
	 * in a single-product test.
	 */
	public function test_state_does_not_leak_between_products(): void {
		$other = self::factory()->post->create( array( 'post_type' => 'product' ) );

		Sync_State::mark_synced( $this->product_id, '111', time() );

		$this->assertFalse( Sync_State::is_synced( $other ) );
	}

	/**
	 * Meta comes back from the database as a string whatever went in, so the
	 * timestamp has to be cast on the way out or every comparison against
	 * time() is a string comparison.
	 */
	public function test_the_timestamp_returns_as_an_integer(): void {
		Sync_State::mark_synced( $this->product_id, '1240123', 1755600000 );

		$this->assertIsInt( Sync_State::get( $this->product_id )['synced_at'] );
	}
}
