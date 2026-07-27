<?php
/**
 * Error raised when an Oyster API call fails.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Carries the upstream HTTP status and decoded payload so callers can branch
 * on 401/422 the way the worker's OysterApiError does.
 */
final class Api_Exception extends \RuntimeException {

	/**
	 * @param mixed $payload Decoded response body (array|string|null).
	 */
	public function __construct(
		private int $status,
		private mixed $payload = null,
		string $message = ''
	) {
		parent::__construct( '' !== $message ? $message : sprintf( 'Oyster API error %d', $status ) );
	}

	public function status(): int {
		return $this->status;
	}

	public function payload(): mixed {
		return $this->payload;
	}

	/**
	 * True for transport-level failures (DNS, timeout, TLS) which we surface as
	 * status 0 — distinct from an HTTP error the API actually returned.
	 */
	public function is_transport_error(): bool {
		return 0 === $this->status;
	}

	/**
	 * Best-effort human-readable message pulled from a Laravel-style error body
	 * ({ message: "...", errors: { field: [...] } }), falling back to the
	 * exception message.
	 */
	public function user_message(): string {
		if ( is_array( $this->payload ) && isset( $this->payload['message'] ) && is_string( $this->payload['message'] ) ) {
			return $this->payload['message'];
		}

		return $this->getMessage();
	}
}
