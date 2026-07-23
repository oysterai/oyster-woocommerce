<?php
/**
 * Stores the store<->Oyster-vendor binding.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The single source of truth for "is this store connected to an Oyster vendor,
 * and if so, who". Persisted as one autoloaded option; the bearer token is
 * encrypted (see Crypto) while the non-secret display fields (public_key,
 * primary_color, logo_url, business_name) are stored plainly so the storefront
 * loader and admin can read them without a decrypt on every page load.
 */
final class Connection {

	public const OPTION_KEY = 'oyster_woocommerce_connection';

	public const DEFAULT_PRIMARY_COLOR = '#0e1e3a';

	/**
	 * @var array<string, mixed>|null In-request cache of the raw option.
	 */
	private ?array $cache = null;

	public function is_connected(): bool {
		return null !== $this->bearer() && $this->vendor_id() > 0;
	}

	public function bearer(): ?string {
		return Crypto::decrypt( $this->raw()['bearer_enc'] ?? null );
	}

	public function vendor_id(): int {
		return (int) ( $this->raw()['vendor_id'] ?? 0 );
	}

	public function business_name(): string {
		return (string) ( $this->raw()['business_name'] ?? '' );
	}

	public function public_key(): string {
		return (string) ( $this->raw()['public_key'] ?? '' );
	}

	public function primary_color(): string {
		$color = (string) ( $this->raw()['primary_color'] ?? '' );

		return '' !== $color ? $color : self::DEFAULT_PRIMARY_COLOR;
	}

	public function logo_url(): string {
		return (string) ( $this->raw()['logo_url'] ?? '' );
	}

	/**
	 * @return string[] Widget experiences the vendor has enabled, if any.
	 */
	public function widget_types(): array {
		$types = $this->raw()['widget_types'] ?? array();

		return is_array( $types ) ? array_values( array_filter( array_map( 'strval', $types ) ) ) : array();
	}

	/**
	 * The storefront origin we reported to skin-ai-api at connect time.
	 */
	public function store_url(): string {
		return (string) ( $this->raw()['store_url'] ?? home_url() );
	}

	public function connected_at(): int {
		return (int) ( $this->raw()['connected_at'] ?? 0 );
	}

	/**
	 * Persist a freshly-authenticated connection. `$bearer` is encrypted here;
	 * everything else is public display config. Called from the Connect screen
	 * after a successful login + profile + widget-config fetch.
	 *
	 * @param array{
	 *     bearer:string,
	 *     vendor_id:int,
	 *     business_name?:string,
	 *     public_key?:string,
	 *     primary_color?:string,
	 *     logo_url?:string,
	 *     widget_types?:string[],
	 *     store_url?:string
	 * } $data
	 */
	public function save( array $data ): void {
		$record = array(
			'bearer_enc'    => Crypto::encrypt( (string) ( $data['bearer'] ?? '' ) ),
			'vendor_id'     => (int) ( $data['vendor_id'] ?? 0 ),
			'business_name' => (string) ( $data['business_name'] ?? '' ),
			'public_key'    => (string) ( $data['public_key'] ?? '' ),
			'primary_color' => (string) ( $data['primary_color'] ?? '' ),
			'logo_url'      => (string) ( $data['logo_url'] ?? '' ),
			'widget_types'  => array_values( (array) ( $data['widget_types'] ?? array() ) ),
			'store_url'     => (string) ( $data['store_url'] ?? home_url() ),
			'connected_at'  => time(),
		);

		$this->write( $record );
	}

	/**
	 * Merge non-secret display fields onto an existing connection — used to
	 * refresh widget config (color/logo/types) without re-authenticating.
	 *
	 * @param array<string, mixed> $partial
	 */
	public function update_display( array $partial ): void {
		$current = $this->raw();
		$allowed = array( 'public_key', 'primary_color', 'logo_url', 'widget_types', 'business_name' );

		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $partial ) ) {
				$current[ $key ] = $partial[ $key ];
			}
		}

		$this->write( $current );
	}

	/**
	 * Forget the connection entirely (disconnect / uninstall).
	 */
	public function clear(): void {
		delete_option( self::OPTION_KEY );
		$this->cache = null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function raw(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION_KEY, array() );
			$this->cache = is_array( $stored ) ? $stored : array();
		}

		return $this->cache;
	}

	/**
	 * @param array<string, mixed> $record
	 */
	private function write( array $record ): void {
		update_option( self::OPTION_KEY, $record );
		$this->cache = $record;
	}
}
