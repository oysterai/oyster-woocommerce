<?php
/**
 * Uninstall cleanup — runs only on explicit "Delete" from the Plugins screen.
 *
 * Removes the plugin's options (including the encrypted vendor bearer). It does
 * NOT delete anything on skin-ai-api: the vendor account, catalog, and orders
 * live there and outlive the plugin. Deactivation preserves state; only a true
 * uninstall wipes local config.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

// Guard: only WordPress should ever include this file.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'oyster_woocommerce_connection' );
delete_option( 'oyster_woocommerce_widget_settings' );

// Best-effort: drop any Action Scheduler jobs we own (catalog sync, added in P2).
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', [], 'oyster-woocommerce' );
}
