<?php
/**
 * Playground blueprint seed script — run via the `runPHP` step in
 * dev-blueprint(.local).json, after wp-load.php and after this plugin +
 * WooCommerce are both active. Not part of the plugin itself; dev-only.
 *
 * @package Oyster\Woo
 */

// Skip the WooCommerce setup wizard redirect — this is a disposable test
// store, not a real shop being onboarded.
delete_transient( '_wc_activation_redirect' );
update_option( 'woocommerce_onboarding_profile', array( 'skipped' => true ) );

// Seed a few products with SKUs so catalog sync has something to push.
if ( class_exists( 'WC_Product_Simple' ) ) {
	$products = array(
		array( 'name' => 'Calming Cleanser', 'sku' => 'OYS-CLN-001', 'price' => '24.00' ),
		array( 'name' => 'Vitamin C Serum', 'sku' => 'OYS-SER-002', 'price' => '48.00' ),
		array( 'name' => 'Barrier Repair Moisturizer', 'sku' => 'OYS-MOI-003', 'price' => '32.00' ),
	);
	foreach ( $products as $p ) {
		if ( wc_get_product_id_by_sku( $p['sku'] ) ) {
			continue;
		}
		$product = new WC_Product_Simple();
		$product->set_name( $p['name'] );
		$product->set_sku( $p['sku'] );
		$product->set_regular_price( $p['price'] );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->save();
	}
}
