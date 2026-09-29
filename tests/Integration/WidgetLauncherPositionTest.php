<?php

declare( strict_types=1 );

namespace Oyster\Woo\Tests\Integration;

use Oyster\Woo\Frontend\Widget_Loader;
use Oyster\Woo\Support\Connection;
use Oyster\Woo\Support\Widget_Settings;
use WP_UnitTestCase;

/**
 * Where the floating launcher is told to sit, read out of the anchor the footer
 * actually prints.
 *
 * The blank case is the one that matters. The widget applies the position saved
 * in the merchant's Oyster dashboard only where the page says nothing, so an
 * attribute printed with an empty value is an override they cannot escape.
 */
final class WidgetLauncherPositionTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		( new Connection() )->save(
			array(
				'bearer'     => 'test-token',
				'vendor_id'  => 1,
				'public_key' => 'pk_test',
			)
		);

		$this->set_position( array() );
	}

	public function test_a_store_that_set_no_position_prints_no_position(): void {
		$anchor = $this->launcher();

		$this->assertStringNotContainsString( 'data-launcher-corner', $anchor );
		$this->assertStringNotContainsString( 'data-launcher-offset-x', $anchor );
		$this->assertStringNotContainsString( 'data-launcher-offset-y', $anchor );
	}

	public function test_a_chosen_corner_is_printed(): void {
		$this->set_position( array( 'launcher_corner' => 'top-left' ) );

		$this->assertStringContainsString( 'data-launcher-corner="top-left"', $this->launcher() );
	}

	public function test_chosen_offsets_are_printed(): void {
		$this->set_position(
			array(
				'launcher_offset_x' => '44',
				'launcher_offset_y' => '96',
			)
		);

		$anchor = $this->launcher();

		$this->assertStringContainsString( 'data-launcher-offset-x="44"', $anchor );
		$this->assertStringContainsString( 'data-launcher-offset-y="96"', $anchor );
	}

	/**
	 * A corner without offsets is a complete answer: the widget keeps its own
	 * spacing, or the merchant's dashboard spacing, and only the corner moves.
	 */
	public function test_a_corner_alone_prints_no_offsets(): void {
		$this->set_position( array( 'launcher_corner' => 'bottom-left' ) );

		$anchor = $this->launcher();

		$this->assertStringContainsString( 'data-launcher-corner="bottom-left"', $anchor );
		$this->assertStringNotContainsString( 'data-launcher-offset-x', $anchor );
	}

	public function test_clearing_a_field_hands_control_back(): void {
		$this->set_position( array( 'launcher_offset_x' => '44' ) );
		$this->assertStringContainsString( 'data-launcher-offset-x', $this->launcher(), 'precondition: it was set' );

		$this->set_position( array( 'launcher_offset_x' => '' ) );

		$this->assertStringNotContainsString( 'data-launcher-offset-x', $this->launcher() );
	}

	/**
	 * Zero is a merchant asking for a launcher flush to the corner, not an empty
	 * field. Treating the two alike would silently ignore the request.
	 */
	public function test_a_zero_offset_is_published(): void {
		$this->set_position( array( 'launcher_offset_x' => '0' ) );

		$this->assertStringContainsString( 'data-launcher-offset-x="0"', $this->launcher() );
	}

	public function test_a_corner_the_widget_does_not_know_is_dropped(): void {
		$saved = Widget_Settings::sanitize(
			array_merge( Widget_Settings::defaults(), array( 'launcher_corner' => 'middle' ) )
		);

		$this->assertSame( '', $saved['launcher_corner'] );
	}

	public function test_an_offset_is_clamped_to_the_bound_the_field_advertises(): void {
		$saved = Widget_Settings::sanitize(
			array_merge(
				Widget_Settings::defaults(),
				array(
					'launcher_offset_x' => '9000',
					'launcher_offset_y' => '-20',
				)
			)
		);

		$this->assertSame( (string) Widget_Settings::OFFSET_MAX, $saved['launcher_offset_x'] );
		$this->assertSame( '0', $saved['launcher_offset_y'] );
	}

	public function test_a_non_numeric_offset_is_treated_as_unset(): void {
		$saved = Widget_Settings::sanitize(
			array_merge( Widget_Settings::defaults(), array( 'launcher_offset_x' => 'far left' ) )
		);

		$this->assertSame( '', $saved['launcher_offset_x'] );
	}

	/**
	 * @param array<string, string> $position
	 */
	private function set_position( array $position ): void {
		update_option(
			Widget_Settings::OPTION_KEY,
			array_merge(
				Widget_Settings::defaults(),
				array( 'float_enabled' => true ),
				$position
			)
		);
	}

	/** The launcher anchor the footer prints. */
	private function launcher(): string {
		ob_start();
		( new Widget_Loader( new Connection() ) )->render_float_launcher();

		return (string) ob_get_clean();
	}
}
