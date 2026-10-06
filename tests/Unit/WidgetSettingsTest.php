<?php

declare( strict_types=1 );

namespace Oyster\Woo\Tests\Unit;

use Oyster\Woo\Support\Widget_Settings;
use PHPUnit\Framework\TestCase;

/**
 * The storefront widget's settings, as they come back from an untrusted form
 * post. `sanitize()` is a Settings API callback, so whatever a browser sends
 * arrives here directly.
 */
final class WidgetSettingsTest extends TestCase {

	public function test_defaults_are_returned_for_an_empty_submission(): void {
		$result = Widget_Settings::sanitize( array() );

		$this->assertSame( Widget_Settings::defaults()['intro_message'], $result['intro_message'] );
		$this->assertSame( '', $result['primary_color'] );
	}

	/** A non-array submission must not fatal — the callback receives whatever is posted. */
	public function test_a_non_array_submission_is_survivable(): void {
		foreach ( array( 'a string', 42, null, true ) as $input ) {
			$result = Widget_Settings::sanitize( $input );
			$this->assertIsArray( $result );
			$this->assertArrayHasKey( 'float_enabled', $result );
		}
	}

	/**
	 * The empty string means "inherit the vendor's configured colour", so an
	 * invalid colour has to collapse to it rather than being stored as-is —
	 * this value is written into a style attribute on the storefront.
	 */
	public function test_an_invalid_colour_collapses_to_inherit(): void {
		foreach ( array( 'red', 'not-a-colour', '#12345', 'javascript:alert(1)', '#ff0000; background:url(x)' ) as $bad ) {
			$result = Widget_Settings::sanitize( array( 'primary_color' => $bad ) );
			$this->assertSame( '', $result['primary_color'], $bad . ' must not survive sanitising' );
		}
	}

	public function test_a_valid_colour_is_kept(): void {
		$this->assertSame( '#ff0000', Widget_Settings::sanitize( array( 'primary_color' => '#ff0000' ) )['primary_color'] );
		$this->assertSame( '#f00', Widget_Settings::sanitize( array( 'primary_color' => '#f00' ) )['primary_color'] );
	}

	/**
	 * Checkboxes are absent from the post body when unticked, so their absence
	 * has to read as false rather than falling back to the default — otherwise
	 * a default-on toggle could never be turned off.
	 */
	public function test_an_absent_checkbox_reads_as_off(): void {
		$this->assertTrue( Widget_Settings::defaults()['display_logo'], 'precondition: this default is on' );

		$result = Widget_Settings::sanitize( array( 'intro_message' => 'Hello' ) );

		$this->assertFalse( $result['float_enabled'] );
		$this->assertFalse( $result['display_logo'] );
	}

	/**
	 * A store that has never opened the Widget screen shows no launcher. The
	 * merchant asks for it rather than removing one that appeared on its own.
	 */
	public function test_the_floating_launcher_is_off_until_it_is_chosen(): void {
		$this->assertFalse( Widget_Settings::defaults()['float_enabled'] );
	}

	public function test_a_ticked_checkbox_reads_as_on(): void {
		$result = Widget_Settings::sanitize( array( 'float_enabled' => '1', 'auto_open' => 'yes' ) );

		$this->assertTrue( $result['float_enabled'] );
		$this->assertTrue( $result['auto_open'] );
	}

	public function test_markup_is_stripped_from_copy(): void {
		$result = Widget_Settings::sanitize(
			array(
				'intro_message' => '<script>alert(1)</script>Skin issues?',
				'message_body'  => '<b>Bold</b> body',
			)
		);

		$this->assertStringNotContainsString( '<script>', $result['intro_message'] );
		$this->assertStringNotContainsString( '<b>', $result['message_body'] );
	}

	/**
	 * Blank means "use the stacking order from the dashboard", so it has to
	 * survive sanitising rather than becoming a number the merchant never typed.
	 */
	public function test_a_blank_layer_stays_blank(): void {
		$this->assertSame( '', Widget_Settings::defaults()['z_index'], 'precondition: blank is the default' );

		foreach ( array( '', '   ', 'top', null, array( 1 ) ) as $input ) {
			$this->assertSame( '', Widget_Settings::sanitize( array( 'z_index' => $input ) )['z_index'] );
		}
	}

	public function test_a_chosen_layer_is_kept(): void {
		$this->assertSame( '9000', Widget_Settings::sanitize( array( 'z_index' => '9000' ) )['z_index'] );
		$this->assertSame( '9001', Widget_Settings::sanitize( array( 'z_index' => '9000.7' ) )['z_index'] );
	}

	/**
	 * Unlike the offsets, a 0 is not a meaningful answer here: the launcher sits
	 * one layer below the panel, so 0 collapses the two onto the same layer.
	 */
	public function test_a_layer_below_the_floor_is_raised_to_it(): void {
		foreach ( array( '0', '-1', '-9000' ) as $input ) {
			$this->assertSame(
				(string) Widget_Settings::Z_INDEX_MIN,
				Widget_Settings::sanitize( array( 'z_index' => $input ) )['z_index'],
				$input . ' must not reach the storefront'
			);
		}
	}

	public function test_a_layer_past_the_top_of_the_css_range_is_capped(): void {
		$this->assertSame(
			(string) Widget_Settings::Z_INDEX_MAX,
			Widget_Settings::sanitize( array( 'z_index' => '99999999999' ) )['z_index']
		);
	}

	/** Every declared key is always present, so readers never have to null-check. */
	public function test_the_returned_shape_is_complete(): void {
		$result = Widget_Settings::sanitize( array( 'intro_message' => 'x' ) );

		foreach ( array_keys( Widget_Settings::defaults() ) as $key ) {
			$this->assertArrayHasKey( $key, $result );
		}
	}
}
