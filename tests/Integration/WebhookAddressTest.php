<?php
/**
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Tests\Integration;

use Oyster\Woo\Api\Client;
use Oyster\Woo\Support\Connection;
use Oyster\Woo\Webhooks\Receiver;
use Oyster\Woo\Webhooks\Registrar;
use Oyster\Woo\Webhooks\Webhook_Secret;
use WP_UnitTestCase;

final class WebhookAddressTest extends WP_UnitTestCase {

	public function tear_down(): void {
		( new Webhook_Secret() )->clear();
		parent::tear_down();
	}

	public function test_registering_records_the_address_it_sent(): void {
		( new Connection() )->save( array( 'bearer' => 'a-bearer', 'vendor_id' => 7 ) );

		$sent = null;
		$stub = function ( $pre, $args, $url ) use ( &$sent ) {
			$sent = json_decode( (string) ( $args['body'] ?? '' ), true );

			return array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) wp_json_encode( array( 'data' => array( 'id' => 42, 'secret' => 'a-secret' ) ) ),
			);
		};

		add_filter( 'pre_http_request', $stub, 10, 3 );
		$registered = ( new Registrar( new Connection(), new Client(), new Webhook_Secret() ) )->register_endpoint();
		remove_filter( 'pre_http_request', $stub, 10 );

		delete_option( Connection::OPTION_KEY );

		$this->assertTrue( $registered );
		$this->assertSame( Receiver::url(), $sent['url'] ?? null );
		$this->assertSame( Receiver::url(), ( new Webhook_Secret() )->registered_url() );
	}

	public function test_a_store_registered_before_this_was_recorded_reports_nothing(): void {
		( new Webhook_Secret() )->save( 42, 'a-secret' );

		$this->assertSame( '', ( new Webhook_Secret() )->registered_url() );
	}

	public function test_recording_an_event_does_not_forget_the_address(): void {
		// record_event() rewrites the option; the address has to survive it.
		$secret = new Webhook_Secret();
		$secret->save( 42, 'a-secret', Receiver::url() );
		$secret->record_event( 'scan.completed' );

		$this->assertSame( Receiver::url(), ( new Webhook_Secret() )->registered_url() );
	}

	public function test_the_address_is_this_site_rest_route(): void {
		// Shape depends on the store's permalinks: pretty gives /wp-json/..., plain gives
		// ?rest_route=/..., and both are live. Only the route and the host are fixed.
		$this->assertStringContainsString( 'oyster-woocommerce/v1/webhook', Receiver::url() );
		$this->assertStringStartsWith( home_url(), Receiver::url() );
	}
}
