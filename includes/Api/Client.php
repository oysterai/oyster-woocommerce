<?php
/**
 * Thin client around Oyster's API.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Api;

use Oyster\Woo\Support\Url_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * One place that injects auth + the X-CHANNEL identifier and turns non-2xx
 * responses into a typed Api_Exception. All domain reads/writes go through
 * here.
 */
final class Client {

	private const DEFAULT_BASE_URL = 'https://api.oysterskin.com';

	private const TIMEOUT = 15;

	/*
	 * -----------------------------------------------------------------------
	 * Endpoint helpers
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Authenticate a vendor. Returns the raw login envelope
	 * ({ success, token, user: { id, user_type } }).
	 *
	 * @return array<string, mixed>
	 * @throws Api_Exception On invalid credentials (401/422) or transport error.
	 */
	public function login( string $email, string $password ): array {
		return $this->request(
			'POST',
			'/api/v1/auth/login',
			array( 'body' => array( 'email' => $email, 'password' => $password ) )
		);
	}

	/**
	 * @return array<string, mixed> Vendor profile envelope ({ vendor: { id, business_name, ... } }).
	 * @throws Api_Exception
	 */
	public function get_vendor_profile( string $bearer ): array {
		return $this->request( 'GET', '/api/v1/vendors/profile', array( 'bearer' => $bearer ) );
	}

	/**
	 * @return array<string, mixed> Widget config envelope ({ data: { public_key, button_color, logo_url, widget_types, ... } }).
	 * @throws Api_Exception
	 */
	public function get_widget_config( string $bearer ): array {
		return $this->request( 'GET', '/api/v1/vendors/widget/config', array( 'bearer' => $bearer ) );
	}

	/**
	 * Ask Oyster to email a one-time code to the account being connected.
	 *
	 * Connecting produces a long-lived store credential, so a password alone
	 * isn't enough to obtain one — the code proves whoever is at this keyboard
	 * can also read that account's email.
	 *
	 * Oyster rate-limits this, so a stream of resend clicks eventually 429s.
	 *
	 * @return array<string, mixed> { data: { sent_to, expires_in_minutes } }
	 * @throws Api_Exception
	 */
	public function request_connect_code( string $bearer ): array {
		return $this->request(
			'POST',
			'/api/v1/integrations/woocommerce/connect/challenge',
			array( 'bearer' => $bearer )
		);
	}

	/**
	 * Register this store's domain against the vendor so the storefront widget
	 * session token isn't rejected on origin, and so scan-usage is attributed
	 * to the WooCommerce channel.
	 *
	 * Also returns this store's own long-lived connection credential, which is
	 * what every later call authenticates with. It is shown once and cannot be
	 * retrieved again — reconnecting issues a fresh one and retires the old.
	 *
	 * Backend counterpart: POST /api/v1/integrations/woocommerce/connect.
	 *
	 * @param string $code One-time code from request_connect_code().
	 *
	 * @return array<string, mixed> { public_key, connection_token }
	 * @throws Api_Exception
	 */
	public function connect_store( string $bearer, string $store_url, string $code ): array {
		return $this->request(
			'POST',
			'/api/v1/integrations/woocommerce/connect',
			array(
				'bearer' => $bearer,
				'body'   => array(
					'store_url' => $store_url,
					'code'      => $code,
				),
			)
		);
	}

	/**
	 * Retire this store's connection credential.
	 *
	 * Authenticated by the credential itself, so it works with nothing but what
	 * this site already holds — no login, no human present — which is what lets
	 * the uninstall routine call it.
	 *
	 * Idempotent: disconnecting a store that was never connected, or twice over,
	 * succeeds rather than erroring.
	 *
	 * @return array<string, mixed>
	 * @throws Api_Exception
	 */
	public function disconnect_store( string $bearer ): array {
		return $this->request(
			'DELETE',
			'/api/v1/integrations/woocommerce/connect',
			array( 'bearer' => $bearer )
		);
	}

	/**
	 * Upsert a batch of catalog rows (one per purchasable WooCommerce unit —
	 * see Sync\Product_Mapper). A 207 response (partial per-row failures) still
	 * decodes normally here; the caller inspects `data.failed`/`failed_items`.
	 *
	 * @param array<int, array<string, mixed>> $products Max 250 rows per call.
	 * @return array<string, mixed> Envelope: { data: { created, updated, claimed, failed, failed_items, ... } }.
	 * @throws Api_Exception
	 */
	public function bulk_upsert_products( string $bearer, array $products ): array {
		return $this->request(
			'POST',
			'/api/v1/integrations/woocommerce/products/bulk-upsert',
			array(
				'bearer'  => $bearer,
				'body'    => array( 'products' => $products ),
				// Larger batches (up to 250 rows) touch the DB once per row on
				// the backend; the default timeout is sized for small,
				// single-object calls like connect/profile.
				'timeout' => 30,
			)
		);
	}

	/**
	 * Archive every Oyster row (including variations) tied to the given
	 * WooCommerce product ids. Product-level only — no way to delete a single
	 * variation without deleting its whole parent product.
	 *
	 * @param string[] $product_ids
	 * @return array<string, mixed> Envelope: { data: { archived } }.
	 * @throws Api_Exception
	 */
	public function delete_products( string $bearer, array $product_ids ): array {
		return $this->request(
			'POST',
			'/api/v1/integrations/woocommerce/products/delete',
			array(
				'bearer' => $bearer,
				'body'   => array( 'woocommerce_product_ids' => $product_ids ),
			)
		);
	}

	/**
	 * Resolve Oyster product ids (from the widget's checkout payload) to their
	 * WooCommerce product/variation ids, for the storefront cart-add flow.
	 *
	 * @param int[] $product_ids
	 * @return array<string, mixed> Envelope: { data: { "<oyster_id>": { woocommerce_product_id, woocommerce_variation_id } } }.
	 * @throws Api_Exception
	 */
	public function resolve_variants( string $bearer, array $product_ids ): array {
		return $this->request(
			'POST',
			'/api/v1/integrations/woocommerce/products/resolve-variants',
			array(
				'bearer' => $bearer,
				'body'   => array( 'product_ids' => array_values( $product_ids ) ),
			)
		);
	}

	/**
	 * Record a paid WooCommerce order as a tracking-only Oyster order for scan
	 * attribution. Tolerant by design — the backend returns 200 with
	 * `recorded: false` (not an error) when it can't attribute the order, e.g.
	 * an unrecognised batch id; the caller doesn't need to treat that as failure.
	 *
	 * @param array<string, mixed> $order
	 * @return array<string, mixed> Envelope: { data: {id, order_number}|null, recorded: bool }.
	 * @throws Api_Exception
	 */
	public function record_order( string $bearer, array $order ): array {
		return $this->request(
			'POST',
			'/api/v1/integrations/woocommerce/orders',
			array(
				'bearer' => $bearer,
				'body'   => $order,
			)
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Transport
	 * -----------------------------------------------------------------------
	 */

	/**
	 * @param 'GET'|'POST'|'PUT'|'PATCH'|'DELETE' $method
	 * @param array{body?:array<string,mixed>|null,bearer?:string,headers?:array<string,string>,timeout?:int} $options
	 * @return array<string, mixed> Decoded JSON body (empty array for no content).
	 * @throws Api_Exception
	 */
	public function request( string $method, string $path, array $options = array() ): array {
		$url = $this->base_url() . '/' . ltrim( $path, '/' );

		$headers = array(
			'Accept' => 'application/json',
			// The backend gates vendor-authenticated requests on this channel
			// header; 'vendor-dash' is the same channel Oyster's own vendor
			// dashboard uses, which this plugin is the WooCommerce-side
			// equivalent of.
			'X-CHANNEL'  => 'vendor-dash',
			'User-Agent' => 'oyster-woocommerce/' . OYSTER_WOO_VERSION . '; ' . home_url(),
		);

		if ( ! empty( $options['bearer'] ) ) {
			$headers['Authorization'] = 'Bearer ' . $options['bearer'];
		}

		if ( ! empty( $options['headers'] ) && is_array( $options['headers'] ) ) {
			$headers = array_merge( $headers, $options['headers'] );
		}

		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => isset( $options['timeout'] ) ? (int) $options['timeout'] : self::TIMEOUT,
		);

		if ( array_key_exists( 'body', $options ) && null !== $options['body'] ) {
			$headers['Content-Type'] = 'application/json';
			$args['headers']         = $headers;
			$args['body']            = wp_json_encode( $options['body'] );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new Api_Exception( 0, null, $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$parsed = '' !== $raw ? json_decode( $raw, true ) : null;
		$body   = is_array( $parsed ) ? $parsed : array();

		if ( $status < 200 || $status >= 300 ) {
			throw new Api_Exception( $status, null === $parsed ? $raw : $parsed );
		}

		return $body;
	}

	/**
	 * Resolve the Oyster API base URL every request is sent to — including
	 * the Authorization header carrying the vendor's bearer. Deliberately NOT
	 * filterable; see Url_Guard's class doc for why. The only override is the
	 * `OYSTER_WOO_API_BASE_URL` wp-config constant, validated by Url_Guard
	 * before use.
	 *
	 * Zero configuration is required for a merchant: with no constant
	 * defined, this always resolves to the production default.
	 */
	private function base_url(): string {
		return Url_Guard::resolve( 'OYSTER_WOO_API_BASE_URL', self::DEFAULT_BASE_URL );
	}
}
