<?php

declare( strict_types=1 );

namespace Oyster\Woo\Tests\Unit;

use Oyster\Woo\Api\Client;
use Oyster\Woo\Checkout\Scan_Payment;
use Oyster\Woo\Support\Connection;
use Oyster\Woo\Support\Scan_Pricing;
use PHPUnit\Framework\TestCase;

/**
 * The marker that tells the payment page why it was opened.
 *
 * That page is the store's own checkout in a popup the shopper never navigated
 * to, mid-scan. Anything the storefront normally greets a visitor with lands on
 * top of the one thing they are there to do. The plugin keeps its own widget off
 * the page outright; this class is what lets a merchant do the same for theirs
 * without writing PHP.
 */
final class ScanPaymentBodyClassTest extends TestCase {

	private Scan_Payment $payments;

	protected function setUp(): void {
		$connection     = new Connection();
		$client         = new Client();
		$this->payments = new Scan_Payment( $connection, $client, new Scan_Pricing( $connection, $client ) );

		unset( $_GET[ Scan_Payment::PAYMENT_FLAG ] );
	}

	protected function tearDown(): void {
		unset( $_GET[ Scan_Payment::PAYMENT_FLAG ] );
	}

	public function test_the_class_is_added_when_paying_for_a_scan(): void {
		$_GET[ Scan_Payment::PAYMENT_FLAG ] = '1';

		$this->assertContains( 'oyster-scan-payment', $this->payments->add_body_class( array() ) );
	}

	public function test_an_ordinary_page_gets_no_such_class(): void {
		$this->assertNotContains( 'oyster-scan-payment', $this->payments->add_body_class( array() ) );
	}

	/** Added to, never replaced — the theme's own classes have to survive. */
	public function test_existing_classes_survive(): void {
		$_GET[ Scan_Payment::PAYMENT_FLAG ] = '1';

		$classes = $this->payments->add_body_class( array( 'woocommerce', 'theme-default' ) );

		$this->assertContains( 'woocommerce', $classes );
		$this->assertContains( 'theme-default', $classes );
		$this->assertCount( 3, $classes );
	}
}
