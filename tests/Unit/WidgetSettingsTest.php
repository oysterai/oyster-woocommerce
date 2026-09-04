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

	/** Every declared key is always present, so readers never have to null-check. */
	public function test_the_returned_shape_is_complete(): void {
		$result = Widget_Settings::sanitize( array( 'intro_message' => 'x' ) );

		foreach ( array_keys( Widget_Settings::defaults() ) as $key ) {
			$this->assertArrayHasKey( $key, $result );
		}
	}
}
