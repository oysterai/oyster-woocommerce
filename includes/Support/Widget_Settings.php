<?php
/**
 * Merchant-controlled storefront widget settings (the float launcher).
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Read/normalize the float-launcher settings — primary color, intro copy,
 * logo, auto-open, where it sits — the source of truth the storefront loader
 * passes to createScanWidget().
 */
final class Widget_Settings {

	public const OPTION_KEY = 'oyster_woocommerce_widget_settings';

	/**
	 * Corners the launcher can be pinned to. Empty means the store has not
	 * chosen, and the position set in the Oyster dashboard applies.
	 *
	 * @return array<string, string> Stored value => label.
	 */
	public static function corners(): array {
		return array(
			''             => __( 'Use the position from your Oyster dashboard', 'oyster-woocommerce' ),
			'bottom-right' => __( 'Bottom right', 'oyster-woocommerce' ),
			'bottom-left'  => __( 'Bottom left', 'oyster-woocommerce' ),
			'top-right'    => __( 'Top right', 'oyster-woocommerce' ),
			'top-left'     => __( 'Top left', 'oyster-woocommerce' ),
		);
	}

	/**
	 * The widget refuses to place the launcher off the page, so this is a sanity
	 * bound on the field rather than a layout rule.
	 */
	public const OFFSET_MAX = 500;

	/**
	 * @return array{
	 *     float_enabled:bool,
	 *     primary_color:string,
	 *     intro_message:string,
	 *     message_body:string,
	 *     display_logo:bool,
	 *     auto_open:bool,
	 *     launcher_corner:string,
	 *     launcher_offset_x:string,
	 *     launcher_offset_y:string
	 * }
	 */
	public static function defaults(): array {
		return array(
			// Off: a storefront should not gain a floating button nobody chose.
			'float_enabled' => false,
			'primary_color' => '', // Empty = inherit the vendor's configured color.
			'intro_message' => __( 'Skin issues?', 'oyster-woocommerce' ),
			'message_body'  => __( 'Take a complete skin analysis and find the right products for your skin.', 'oyster-woocommerce' ),
			'display_logo'  => true,
			'auto_open'     => false,
			// All three empty = inherit from the dashboard. The offsets stay
			// strings rather than ints because '' and '0' are different
			// answers: blank leaves the widget its own margin, which is
			// narrower on a phone, and 0 pins the launcher flush to the corner.
			'launcher_corner'   => '',
			'launcher_offset_x' => '',
			'launcher_offset_y' => '',
		);
	}

	/**
	 * The launcher position to publish, or an empty array when the store has set
	 * none. Keys are the data attributes the loader reads.
	 *
	 * @return array<string, string>
	 */
	public static function launcher_position(): array {
		$settings = self::get();
		$position = array();

		if ( '' !== $settings['launcher_corner'] ) {
			$position['corner'] = (string) $settings['launcher_corner'];
		}

		foreach ( array( 'x' => 'launcher_offset_x', 'y' => 'launcher_offset_y' ) as $axis => $key ) {
			if ( '' !== $settings[ $key ] ) {
				$position[ $axis ] = (string) $settings[ $key ];
			}
		}

		return $position;
	}

	/**
	 * @return array<string, mixed> Stored settings merged over defaults.
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Sanitize a raw settings submission. Used as the Settings API
	 * sanitize_callback, so it receives untrusted input.
	 *
	 * @param mixed $input
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();

		$color = isset( $input['primary_color'] ) ? sanitize_hex_color( (string) $input['primary_color'] ) : '';

		$corner = (string) ( $input['launcher_corner'] ?? '' );

		return array(
			'float_enabled' => ! empty( $input['float_enabled'] ),
			'primary_color' => is_string( $color ) ? $color : '',
			'intro_message' => sanitize_text_field( (string) ( $input['intro_message'] ?? $defaults['intro_message'] ) ),
			'message_body'  => sanitize_textarea_field( (string) ( $input['message_body'] ?? $defaults['message_body'] ) ),
			'display_logo'  => ! empty( $input['display_logo'] ),
			'auto_open'     => ! empty( $input['auto_open'] ),
			'launcher_corner'   => array_key_exists( $corner, self::corners() ) ? $corner : '',
			'launcher_offset_x' => self::sanitize_offset( $input['launcher_offset_x'] ?? '' ),
			'launcher_offset_y' => self::sanitize_offset( $input['launcher_offset_y'] ?? '' ),
		);
	}

	/**
	 * Blank stays blank. Coercing it to a number here would silently pin the
	 * launcher to a margin the merchant never typed.
	 *
	 * @param mixed $value
	 */
	private static function sanitize_offset( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$raw = trim( (string) $value );
		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return '';
		}

		return (string) max( 0, min( self::OFFSET_MAX, (int) round( (float) $raw ) ) );
	}
}
