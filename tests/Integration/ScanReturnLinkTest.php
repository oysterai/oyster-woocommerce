<?php

declare( strict_types=1 );

namespace Oyster\Woo\Tests\Integration;

use Oyster\Woo\Api\Client;
use Oyster\Woo\Checkout\Scan_Payment;
use Oyster\Woo\Support\Connection;
use Oyster\Woo\Support\Scan_Pricing;
use WP_UnitTestCase;

/**
 * The return page must name the scan or its widget can't resume it: on Android shoppers rarely go back
 * to the waiting tab.
 */
final class ScanReturnLinkTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		$connection = new Connection();
		$client     = new Client();
		( new Scan_Payment( $connection, $client, new Scan_Pricing( $connection, $client ) ) )->register();
	}

	public function test_a_scan_order_returns_to_a_page_naming_its_payment(): void {
		$order = wc_create_order();
		$order->update_meta_data( Scan_Payment::ORDER_META_REFERENCE, 'OYS-SCAN-01M3VRFH' );
		$order->save();

		$query = wp_parse_url( $order->get_checkout_order_received_url(), PHP_URL_QUERY );
		parse_str( (string) $query, $params );

		$this->assertSame( 'OYS-SCAN-01M3VRFH', $params[ Scan_Payment::RETURN_REFERENCE_PARAM ] ?? null );
		$this->assertSame( $order->get_order_key(), $params['key'] ?? null );
	}

	public function test_an_ordinary_order_returns_to_the_usual_page(): void {
		$order = wc_create_order();
		$order->save();

		$this->assertStringNotContainsString(
			Scan_Payment::RETURN_REFERENCE_PARAM,
			$order->get_checkout_order_received_url()
		);
	}
}
