<?php
/** @package Oyster\Woo */
declare( strict_types=1 );
namespace Oyster\Woo\Wheel;
defined( 'ABSPATH' ) || exit;

final class Lock {
	/** The database connection releases this lock even if PHP exits unexpectedly. */
	public static function run( string $key, callable $callback ) {
		global $wpdb;
		$name = 'oyster_wheel_' . substr( hash( 'sha256', $wpdb->prefix . $key ), 0, 48 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) ) {
			throw new \RuntimeException( 'reward_busy' );
		}
		try {
			return $callback();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}
}
