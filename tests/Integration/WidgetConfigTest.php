<?php

declare( strict_types=1 );

namespace Oyster\Woo\Tests\Integration;

use Oyster\Woo\Frontend\Widget_Loader;
use Oyster\Woo\Support\Connection;
use Oyster\Woo\Support\Widget_Settings;
use WP_UnitTestCase;

/**
 * What the storefront is told about the widget, read back out of the inline
 * script the loader attaches rather than from the private method that builds
 * it, so the assertions are about what a shopper's browser actually receives.
 *
 * The colour is the reason this exists. The widget applies the vendor's own
 * saved colour only to options the page leaves out, so anything this config
 * states unconditionally is an override the merchant cannot escape.
 */
final class WidgetConfigTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		( new Connection() )->save(
			array(
				'bearer'        => 'test-token',
				'vendor_id'     => 1,
				'public_key'    => 'pk_test',
				'primary_color' => '#123456',
			)
		);
	}

	/**
	 * A store that never opened the colour field must say nothing about colour,
	 * which is what lets the dashboard's own setting apply.
	 */
	public function test_no_colour_is_published_when_the_merchant_set_none(): void {
		$this->assertSame( '', Widget_Settings::defaults()['primary_color'], 'precondition: blank is the default' );

		$this->assertArrayNotHasKey( 'primaryColor', $this->published_config() );
	}

	/**
	 * Including the colour captured from the dashboard when the store was
	 * connected. It is a snapshot from that moment, so publishing it would
	 * pin the storefront to whatever the colour was that day.
	 */
	public function test_the_connect_time_colour_is_not_published_either(): void {
		$this->assertSame( '#123456', ( new Connection() )->primary_color(), 'precondition: a colour was captured at connect' );

		$this->assertArrayNotHasKey( 'primaryColor', $this->published_config() );
	}

	public function test_a_colour_the_merchant_chose_is_published(): void {
		$this->set_colour( '#ff0000' );

		$this->assertSame( '#ff0000', $this->published_config()['primaryColor'] );
	}

	public function test_clearing_the_colour_hands_control_back(): void {
		$this->set_colour( '#ff0000' );
		$this->assertArrayHasKey( 'primaryColor', $this->published_config(), 'precondition: it was set' );

		$this->set_colour( '' );

		$this->assertArrayNotHasKey( 'primaryColor', $this->published_config() );
	}

	/** The rest of the config is unconditional, and the widget needs it. */
	public function test_the_keys_the_loader_cannot_work_without_are_always_there(): void {
		$config = $this->published_config();

		foreach ( array( 'publicKey', 'loaderUrl', 'app', 'scanPaymentUrl' ) as $key ) {
			$this->assertArrayHasKey( $key, $config );
		}

		$this->assertSame( 'pk_test', $config['publicKey'] );
		$this->assertSame( 'woocommerce', $config['app'] );
	}

	private function set_colour( string $colour ): void {
		update_option(
			Widget_Settings::OPTION_KEY,
			array_merge( Widget_Settings::defaults(), array( 'primary_color' => $colour ) )
		);
	}

	/**
	 * @return array<string, mixed> The decoded window.OysterWooConfig.
	 */
	private function published_config(): array {
		// A fresh registry each time: the loader registers its handle once, and
		// a handle already present would keep the previous test's config.
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		( new Widget_Loader( new Connection() ) )->register_assets();

		$inline = wp_scripts()->get_data( 'oyster-woo-loader', 'before' );
		$this->assertIsArray( $inline, 'the loader attached no inline config' );

		$json = '';
		foreach ( $inline as $chunk ) {
			if ( is_string( $chunk ) && false !== strpos( $chunk, 'OysterWooConfig' ) ) {
				$json = trim( substr( $chunk, (int) strpos( $chunk, '=' ) + 1 ) );
				$json = rtrim( $json, ';' );
			}
		}

		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded, 'could not decode the published config: ' . $json );

		return $decoded;
	}
}
