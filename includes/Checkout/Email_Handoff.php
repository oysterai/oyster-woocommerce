<?php
/**
 * Storefront handler for the scan-result email's checkout CTA.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Checkout;

use Oyster\Woo\Support\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * After a scan, Oyster emails the shopper their report. Its "shop" links point
 * back at this store rather than at the widget, so a shopper who comes back
 * days later lands on checkout with their routine already in the cart instead
 * of having to find the widget and rebuild it.
 *
 * The link is a plain storefront URL carrying the scan's Oyster product ids:
 *
 *     https://store.example.com/?oyster_checkout=1&oyster_batch=<batch>&oyster_products=<ids>&oyster_routine=<routine>&oyster_attribution_id=<uuid>
 *
 * Every parameter is `oyster_`-prefixed, and that is not cosmetic. WordPress
 * reserves a set of public query vars, `p` (post id) among them: given a bare
 * `?p=12,44`, WordPress resolves it to a post and issues a canonical 301 to
 * that post's permalink *with the parameter stripped*. A store that hasn't
 * updated yet — the exact window this link is most likely to be clicked in —
 * would dump the shopper on an unrelated post instead of the home page. Don't
 * un-prefix these, and don't add a bare `s`, `m`, `w`, `name` or `page` either.
 *
 * We intercept it on `template_redirect` — a normal front-end request, so the
 * visitor's own cart session is already live and the reply carries their cart
 * cookie — fill the cart, and forward them to checkout.
 *
 * Deliberately unsigned. This plugin ships to merchants, so it can't hold a
 * secret shared with Oyster to verify a signature against. It doesn't need
 * one: the ids are resolved server-side against this vendor's own synced
 * catalog, so the worst a hand-edited link can do is put this store's own
 * products into the clicker's own cart — exactly what WooCommerce's built-in
 * `?add-to-cart=` links already allow. Scan attribution is likewise re-checked
 * against the vendor's own scans when the paid order is reported.
 */
final class Email_Handoff {

	/**
	 * Presence of this query var (with a truthy value) marks a request as an
	 * email CTA click. Oyster's email builder emits the same name.
	 */
	public const QUERY_PARAM = 'oyster_checkout';

	/**
	 * A routine is a handful of products; anything beyond this is a malformed
	 * or hand-edited link, and the resolve call rejects oversized batches
	 * anyway. Excess ids are dropped rather than failing the whole handoff.
	 */
	private const MAX_ITEMS = 100;

	public function __construct(
		private Connection $connection,
		private Cart_Filler $filler
	) {}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_handle' ), 5 );
	}

	public function maybe_handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- A link
		// clicked from an email can't carry a nonce; nothing here is trusted
		// beyond "add these ids to your own cart" (see the class docblock).
		if ( empty( $_GET[ self::QUERY_PARAM ] ) ) {
			return;
		}

		if ( ! $this->connection->is_connected() || ! function_exists( 'wc_get_checkout_url' ) ) {
			return;
		}

		$items = Cart_Filler::sanitize_items( $this->parse_items( $this->get_param( 'oyster_products', 'oyster_p' ) ) );
		if ( ! $items ) {
			$this->redirect( $this->fallback_url() );
		}

		$attribution = array_filter(
			array(
				'batch_id'              => $this->get_param( 'oyster_batch', 'oyster_b' ),
				'routine_id'            => $this->get_param( 'oyster_routine', 'oyster_r' ),
				'widget_attribution_id' => $this->get_param( 'oyster_attribution_id', 'oyster_a' ),
			),
			static fn( string $value ): bool => '' !== $value
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = $this->filler->fill( $items, $attribution );

		// Nothing addable (not synced yet, or sold out) is as much a dead end
		// as an outright failure — send them to the cart with the same notice
		// rather than dropping them into an empty checkout.
		$added = isset( $result['added'] ) ? (int) $result['added'] : 0;

		$this->redirect( $added > 0 ? wc_get_checkout_url() : $this->fallback_url() );
	}

	/**
	 * Cart page plus the flag `Cart_Controller::maybe_show_error_notice()`
	 * turns into a real WooCommerce notice, so the shopper is told to add the
	 * items themselves instead of wondering what happened.
	 */
	private function fallback_url(): string {
		return add_query_arg( 'oyster_checkout_error', '1', wc_get_cart_url() );
	}

	private function redirect( string $url ): void {
		nocache_headers();
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Comma-separated Oyster product ids → the item shape Cart_Filler takes.
	 * Quantity is always 1: a routine recommends one of each.
	 *
	 * @return array<int, array{product_id:string, quantity:int}>
	 */
	private function parse_items( string $raw ): array {
		if ( '' === $raw ) {
			return array();
		}

		$ids = array_slice( array_unique( explode( ',', $raw ) ), 0, self::MAX_ITEMS );

		return array_map(
			static fn( string $id ): array => array(
				'product_id' => trim( $id ),
				'quantity'   => 1,
			),
			$ids
		);
	}

	/**
	 * `$legacy_key` is the abbreviated name this parameter shipped under
	 * before it was spelled out. It stays supported permanently, on purpose:
	 * a link that has already gone out lives in someone's inbox for years and
	 * carries whatever names were current the day it was sent. Dropping the
	 * old spelling would silently break those clicks long after anyone
	 * remembers why. It also makes the plugin and the email side safe to
	 * deploy in either order.
	 */
	private function get_param( string $key, string $legacy_key = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see maybe_handle().
		$value = $_GET[ $key ] ?? '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see maybe_handle().
		if ( '' === $value && '' !== $legacy_key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see maybe_handle().
			$value = $_GET[ $legacy_key ] ?? '';
		}

		return is_scalar( $value ) ? sanitize_text_field( wp_unslash( (string) $value ) ) : '';
	}
}
