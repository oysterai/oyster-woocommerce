<?php
/**
 * Read access to Oyster's API for companion plugins.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Api;

use Oyster\Woo\Support\Connection;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The call is made here so the store credential stays owned by one plugin, rather
 * than the merchant minting a second key that outlives the connection.
 */
final class Companion_Access {

	public const FILTER = 'oyster_woocommerce_api_get';

	public function __construct(
		private readonly Connection $connection,
		private readonly Client $client
	) {}

	public function register(): void {
		add_filter( self::FILTER, array( $this, 'get' ), 10, 2 );
	}

	/**
	 * @param mixed  $response Unused; filters are called with a default.
	 * @param string $path     API path, e.g. `/skin/delivery/<batch id>`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get( $response, string $path = '' ) {
		if ( '' === $path ) {
			return new WP_Error( 'oyster_woocommerce_no_path', __( 'No API path was given.', 'oyster-woocommerce' ) );
		}

		$bearer = $this->connection->bearer();

		if ( null === $bearer ) {
			return new WP_Error( 'oyster_woocommerce_not_connected', __( 'This store is not connected to Oyster.', 'oyster-woocommerce' ) );
		}

		try {
			return $this->client->request( 'GET', $path, array( 'bearer' => $bearer ) );
		} catch ( Api_Exception $e ) {
			return new WP_Error(
				'oyster_woocommerce_api_error',
				$e->getMessage(),
				array( 'status' => $e->status() )
			);
		}
	}
}
