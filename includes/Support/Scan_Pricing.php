<?php
/**
 * What shoppers pay for a scan at this store.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Support;

use Oyster\Woo\Api\Api_Exception;
use Oyster\Woo\Api\Client;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and caches the store's scan price, held by Oyster rather than here.
 *
 * The price lives on the Oyster account on purpose. A shopper is quoted it
 * inside the scan widget *before* deciding to pay, and that quote comes from
 * Oyster — a price kept only in this database would show one figure in the
 * widget and charge another at checkout.
 *
 * Shared by the admin screen that edits it and the checkout that collects it, so
 * there is one definition of "what does a scan cost here" rather than two that
 * can disagree.
 */
final class Scan_Pricing {

	public const CACHE_KEY = 'oyster_woocommerce_scan_pricing';

	public const PACK_CACHE_KEY = 'oyster_woocommerce_scan_pack';

	/**
	 * How long a fetched price is reused. Short, because a merchant who has just
	 * changed their rate should not be reading a stale cost while deciding what
	 * to charge on top of it.
	 */
	private const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/** @var array<string, string> */
	public const MODES = array(
		'passthrough'    => 'Charge what Oyster charges me',
		'markup_percent' => 'Add a percentage',
		'markup_amount'  => 'Add a fixed amount',
		'fixed_amount'   => 'Set my own price',
	);

	/**
	 * Whether the last read was refused rather than merely unavailable. See
	 * Api_Exception::denies_access() for why the two are worth telling apart.
	 */
	private bool $access_denied = false;

	public function __construct(
		private Connection $connection,
		private Client $client
	) {}

	public function access_was_denied(): bool {
		return $this->access_denied;
	}

	/**
	 * The store's current pricing, or null when it cannot be read.
	 *
	 * @return array<string, mixed>|null
	 */
	public function current( bool $fresh = false ): ?array {
		if ( ! $fresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$bearer = $this->connection->bearer();
		if ( null === $bearer ) {
			return null;
		}

		try {
			$response = $this->client->get_scan_pricing( $bearer );
		} catch ( Api_Exception $e ) {
			$this->access_denied = $e->denies_access();

			return null;
		}

		$this->access_denied = false;

		$data = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : null;

		if ( null !== $data ) {
			set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		}

		return $data;
	}

	/**
	 * The lowest amount an order for a scan may be raised at.
	 *
	 * Null when it cannot be established, in which case the caller must not
	 * refuse the payment — a shopper who is ready to pay should not be turned
	 * away because a price lookup happened to fail.
	 */
	public function minimum_charge(): ?float {
		$pricing = $this->current();

		if ( null === $pricing || empty( $pricing['collects_externally'] ) ) {
			return null;
		}

		$price = $pricing['customer_price'] ?? null;

		return is_numeric( $price ) && (float) $price > 0 ? (float) $price : null;
	}

	public function forget(): void {
		delete_transient( self::CACHE_KEY );
		delete_transient( self::PACK_CACHE_KEY );
	}

	/**
	 * The store's scan pack, or null when it cannot be read.
	 *
	 * Cached apart from the single-scan price: same screen, different resources.
	 *
	 * @return array<string, mixed>|null
	 */
	public function current_pack( bool $fresh = false ): ?array {
		if ( ! $fresh ) {
			$cached = get_transient( self::PACK_CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$bearer = $this->connection->bearer();
		if ( null === $bearer ) {
			return null;
		}

		try {
			$response = $this->client->get_scan_pack( $bearer );
		} catch ( Api_Exception $e ) {
			$this->access_denied = $e->denies_access();

			return null;
		}

		$this->access_denied = false;

		$data = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : null;

		if ( null !== $data ) {
			set_transient( self::PACK_CACHE_KEY, $data, self::CACHE_TTL );
		}

		return $data;
	}

	public function update_pack( int $size, ?int $validity_days, ?float $pack_price_value ): void {
		$bearer = $this->connection->bearer();

		if ( null === $bearer ) {
			throw new Api_Exception( 0, null, __( 'This store is not connected to Oyster.', 'oyster-woocommerce' ) );
		}

		$this->client->update_scan_pack( $bearer, $size, $validity_days, $pack_price_value );
		$this->forget();
	}

	public function update( string $mode, ?float $value ): void {
		$bearer = $this->connection->bearer();

		if ( null === $bearer ) {
			throw new Api_Exception( 0, null, __( 'This store is not connected to Oyster.', 'oyster-woocommerce' ) );
		}

		$this->client->update_scan_pricing( $bearer, $mode, $value );
		$this->forget();
	}
}
