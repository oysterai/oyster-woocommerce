<?php
/**
 * Uninstall cleanup — runs only on explicit "Delete" from the Plugins screen.
 *
 * Retires this store's Oyster credential, then removes the plugin's options. It
 * does NOT delete anything else on Oyster's side: the vendor account, catalog,
 * and orders live there and outlive the plugin. Deactivation preserves state;
 * only a true uninstall wipes local config.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

// Guard: only WordPress should ever include this file.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Retire the store credential before the option holding it is deleted.
 *
 * Deleting only the local copy would leave a working credential behind with
 * nothing left on this site able to retire it — the site is, after all, being
 * dismantled. This is the last moment it can be done automatically.
 *
 * Deliberately hand-rolled rather than booting the plugin's classes: uninstall
 * runs in a bare context, and a fatal here would abort the whole cleanup and
 * leave the options behind too. Every failure path falls through to the deletes
 * below.
 */
$oyster_woo_retire_credential = static function (): void {
	$connection = get_option( 'oyster_woocommerce_connection' );
	if ( ! is_array( $connection ) || empty( $connection['bearer_enc'] ) ) {
		return;
	}

	// Only the two dependency-free helpers this needs. Booting the plugin
	// proper would run its whole bootstrap during a deletion.
	foreach ( array( 'Crypto', 'Url_Guard' ) as $class_file ) {
		$path = __DIR__ . '/includes/Support/' . $class_file . '.php';
		if ( ! is_readable( $path ) ) {
			return;
		}
		require_once $path;
	}

	if ( ! class_exists( '\Oyster\Woo\Support\Crypto' ) || ! class_exists( '\Oyster\Woo\Support\Url_Guard' ) ) {
		return;
	}

	$bearer = \Oyster\Woo\Support\Crypto::decrypt( $connection['bearer_enc'] );
	if ( null === $bearer || '' === $bearer ) {
		return;
	}

	// Through the same guard the plugin uses, so an overridden base URL is
	// validated rather than trusted — this call carries a credential.
	$base_url = \Oyster\Woo\Support\Url_Guard::resolve( 'OYSTER_WOO_API_BASE_URL', 'https://api.oysterskin.com' );

	// Short timeout: someone is waiting on a plugin deletion, and an
	// unreachable API must not hold the screen. An orphaned credential can
	// still be retired from the Oyster dashboard, where it is listed.
	wp_remote_request(
		rtrim( $base_url, '/' ) . '/api/v1/integrations/woocommerce/connect',
		array(
			'method'  => 'DELETE',
			'timeout' => 5,
			'headers' => array(
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . $bearer,
				'X-CHANNEL'     => 'vendor-dash',
			),
		)
	);
};

$oyster_woo_retire_credential();

delete_option( 'oyster_woocommerce_connection' );
delete_option( 'oyster_woocommerce_widget_settings' );

// Best-effort: drop any Action Scheduler jobs we own (catalog sync, added in P2).
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', [], 'oyster-woocommerce' );
}
