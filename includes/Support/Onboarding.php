<?php
/**
 * Manual acknowledgements for steps the Setup Guide can't verify on its own.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Most Setup Guide steps are derived live from real state (connected? synced
 * anything? float launcher on?) — see Admin\Setup_Guide. A couple of steps
 * have no objective "done" signal ("customized the widget to your liking",
 * "confirmed a scan works"), so those are tracked here as plain manual
 * checkboxes instead.
 */
final class Onboarding {

	public const OPTION_KEY = 'oyster_woocommerce_onboarding';

	/**
	 * @return array{widget_customized:bool, widget_added:bool, first_scan_confirmed:bool, dismissed:bool}
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'widget_customized'    => ! empty( $stored['widget_customized'] ),
			'widget_added'         => ! empty( $stored['widget_added'] ),
			'first_scan_confirmed' => ! empty( $stored['first_scan_confirmed'] ),
			'dismissed'            => ! empty( $stored['dismissed'] ),
		);
	}

	public static function mark( string $key ): void {
		$state = self::get();
		if ( ! array_key_exists( $key, $state ) ) {
			return;
		}

		$state[ $key ] = true;
		update_option( self::OPTION_KEY, $state );
	}

	public static function dismiss(): void {
		$state              = self::get();
		$state['dismissed'] = true;
		update_option( self::OPTION_KEY, $state );
	}
}
