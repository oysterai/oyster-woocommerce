<?php
/**
 * Registers this store's callback with Oyster, and retires it on disconnect.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Webhooks;

use Oyster\Woo\Api\Api_Exception;
use Oyster\Woo\Api\Client;
use Oyster\Woo\Support\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * Done here rather than asking the merchant to paste a URL and a secret: the
 * plugin already knows both sides, and a secret typed into a settings field is a
 * secret in a browser's autofill and in whatever backs up the database.
 */
final class Registrar {

	/** Stable per store, so reconnecting replaces the endpoint instead of stacking up. */
	private const ALIAS = 'woocommerce';

	private const EVENTS = array( 'scan.completed', 'recommendation.ready' );

	public function __construct(
		private Connection $connection,
		private Client $client,
		private Webhook_Secret $secret
	) {}

	/**
	 * Returns false when registration did not happen, so the caller can tell the
	 * merchant that events will not arrive yet. Never throws: a store that
	 * cannot receive callbacks is still a working store, and failing the connect
	 * over it would leave the merchant unable to finish.
	 */
	public function register_endpoint(): bool {
		$bearer = $this->connection->bearer();
		if ( null === $bearer ) {
			return false;
		}

		try {
			$response = $this->client->register_webhook_endpoint(
				$bearer,
				self::ALIAS,
				Receiver::url(),
				self::EVENTS
			);
		} catch ( Api_Exception $e ) {
			$this->log( 'could not register webhook endpoint: ' . $e->user_message() );

			return false;
		}

		$data   = is_array( $response['data'] ?? null ) ? $response['data'] : array();
		$id     = (int) ( $data['id'] ?? 0 );
		$secret = (string) ( $data['secret'] ?? '' );

		if ( $id <= 0 || '' === $secret ) {
			$this->log( 'webhook endpoint registered but the response carried no secret' );

			return false;
		}

		$this->secret->save( $id, $secret );

		return true;
	}

	/**
	 * Retire the endpoint at Oyster before forgetting it here, mirroring how the
	 * connection's own credential is handled: dropping only the local copy would
	 * leave Oyster posting a store's scan data at a site that no longer expects
	 * it, signed with a secret nobody is checking.
	 */
	public function unregister_endpoint(): void {
		$bearer      = $this->connection->bearer();
		$endpoint_id = $this->secret->endpoint_id();

		if ( null !== $bearer && $endpoint_id > 0 ) {
			try {
				$this->client->delete_webhook_endpoint( $bearer, $endpoint_id );
			} catch ( Api_Exception $e ) {
				// Same reasoning as disconnect: someone disconnecting wants this
				// store disconnected, and Oyster being unreachable must not
				// strand them. The endpoint is listed in the Oyster dashboard.
				$this->log( 'could not retire webhook endpoint remotely: ' . $e->user_message() );
			}
		}

		$this->secret->clear();
	}

	private function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[oyster-woocommerce] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
