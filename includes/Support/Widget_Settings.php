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
 * logo, auto-open — the source of truth the storefront loader passes to
 * createScanWidget().
 */
final class Widget_Settings {

	public const OPTION_KEY = 'oyster_woocommerce_widget_settings';

	/**
	 * @return array{
	 *     float_enabled:bool,
	 *     primary_color:string,
	 *     intro_message:string,
	 *     message_body:string,
	 *     display_logo:bool,
	 *     auto_open:bool
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
		);
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

		return array(
			'float_enabled' => ! empty( $input['float_enabled'] ),
			'primary_color' => is_string( $color ) ? $color : '',
			'intro_message' => sanitize_text_field( (string) ( $input['intro_message'] ?? $defaults['intro_message'] ) ),
			'message_body'  => sanitize_textarea_field( (string) ( $input['message_body'] ?? $defaults['message_body'] ) ),
			'display_logo'  => ! empty( $input['display_logo'] ),
			'auto_open'     => ! empty( $input['auto_open'] ),
		);
	}
}
