<?php
/**
 * Stores this store's webhook signing secret.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Webhooks;

use Oyster\Woo\Support\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Kept apart from Connection because the two have different lifetimes: the
 * secret is re-issued whenever the endpoint is registered or rotated, while the
 * connection outlives that.
 */
final class Webhook_Secret {

	public const OPTION_KEY = 'oyster_woocommerce_webhook';

	public function secret(): ?string {
		return Crypto::decrypt( $this->raw()['secret_enc'] ?? null );
	}

	public function endpoint_id(): int {
		return (int) ( $this->raw()['endpoint_id'] ?? 0 );
	}

	public function is_registered(): bool {
		return null !== $this->secret() && $this->endpoint_id() > 0;
	}

	public function last_event_at(): int {
		return (int) ( $this->raw()['last_event_at'] ?? 0 );
	}

	public function last_event(): string {
		return (string) ( $this->raw()['last_event'] ?? '' );
	}

	public function save( int $endpoint_id, string $secret ): void {
		$this->write(
			array(
				'endpoint_id'   => $endpoint_id,
				'secret_enc'    => Crypto::encrypt( $secret ),
				'registered_at' => time(),
			) + $this->raw()
		);
	}

	/**
	 * Surfaced on the Connect screen. Plenty of WooCommerce sites are not
	 * reachable from the internet, and without this a merchant has no way to
	 * tell that events have never arrived.
	 */
	public function record_event( string $event ): void {
		$this->write(
			array(
				'last_event'    => $event,
				'last_event_at' => time(),
			) + $this->raw()
		);
	}

	public function clear(): void {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * Read through to the option every time, deliberately. get_option is already
	 * served from WordPress' own cache, so a second layer here saves nothing and
	 * costs correctness: two instances of this class exist in a request that both
	 * registers the receiver and handles a delivery, and a cached one would go on
	 * serving a secret that clear() had already deleted.
	 *
	 * @return array<string, mixed>
	 */
	private function raw(): array {
		$stored = get_option( self::OPTION_KEY, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * @param array<string, mixed> $record
	 */
	private function write( array $record ): void {
		update_option( self::OPTION_KEY, $record );
	}
}
