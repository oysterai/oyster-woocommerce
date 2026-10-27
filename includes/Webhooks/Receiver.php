<?php
/**
 * Receives Oyster's event callbacks and re-emits them as WordPress actions.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Webhooks;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Public on purpose (`permission_callback: __return_true`): the signature IS the
 * authentication. Oyster has no WordPress account here and cannot hold a nonce,
 * so requiring a capability would reject every legitimate delivery. Don't
 * "fix" the permission callback without replacing the signature check.
 *
 * The two actions this fires carry the batch id, not the scan. Oyster sends it
 * that way deliberately so a customer's analysis is not copied into this site's
 * logs; a listener that needs the detail fetches it with the store's own
 * credential.
 */
final class Receiver {

	public const NAMESPACE = 'oyster-woocommerce/v1';

	public const ROUTE = '/webhook';

	/** Long enough to outlive the retry schedule that would replay a delivery. */
	private const SEEN_TTL = HOUR_IN_SECONDS;

	/**
	 * Longer than PHP lets a request run, so an older claim belongs to an attempt that
	 * died (a fatal, a timeout) and the retry may take it over.
	 */
	private const CLAIM_TTL = MINUTE_IN_SECONDS;

	public const WHEEL_REWARD_WON = 'oyster_woocommerce_wheel_reward_won';

	private const EVENT_ACTIONS = array(
		'scan.completed'       => 'oyster_woocommerce_scan_completed',
		'recommendation.ready' => 'oyster_woocommerce_recommendations_ready',
		'wheel.reward_won'     => self::WHEEL_REWARD_WON,
	);

	public function __construct(
		private Webhook_Secret $secret
	) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function url(): string {
		return rest_url( self::NAMESPACE . self::ROUTE );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$secret = $this->secret->secret();
		if ( null === $secret ) {
			return new WP_REST_Response( array( 'error' => 'not_registered' ), 503 );
		}

		// The raw body, not the parsed params: the signature covers the bytes as
		// sent, and re-encoding the parsed array will not reproduce them.
		$body = $request->get_body();

		if ( ! Signature::is_valid( (string) $request->get_header( 'oyster_signature' ), $body, $secret ) ) {
			return new WP_REST_Response( array( 'error' => 'bad_signature' ), 400 );
		}

		$delivery = trim( (string) $request->get_header( 'oyster_delivery' ) );
		if ( '' === $delivery ) {
			return new WP_REST_Response( array( 'error' => 'missing_delivery_id' ), 400 );
		}

		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response( array( 'error' => 'bad_payload' ), 400 );
		}

		$event = (string) ( $payload['event'] ?? '' );
		$action = self::EVENT_ACTIONS[ $event ] ?? null;
		if ( null === $action ) {
			// Acknowledged, not retried: an event this version does not know is
			// not a failure, and 4xx-ing it would trip Oyster's circuit breaker
			// and silence the events this version DOES handle.
			return new WP_REST_Response( array( 'ignored' => $event ), 200 );
		}

		// Delivery is at-least-once, so a retry after a timeout that actually
		// succeeded arrives again. Without this a listener sends two emails.
		if ( false !== get_option( $this->seen_key( $delivery ) ) ) {
			return new WP_REST_Response( array( 'duplicate' => true ), 200 );
		}

		// Another attempt at this delivery is still running. Not acknowledged, so Oyster
		// asks again later and either finds it handled or finds the attempt died.
		if ( ! $this->claim( $delivery ) ) {
			return new WP_REST_Response( array( 'error' => 'in_progress' ), 409 );
		}

		// The attempt that held the claim may have finished between the check above
		// and taking it.
		if ( false !== get_option( $this->seen_key( $delivery ) ) ) {
			delete_option( $this->claim_key( $delivery ) );

			return new WP_REST_Response( array( 'duplicate' => true ), 200 );
		}

		$batch_id = (string) ( $payload['data']['batch_id'] ?? '' );

		$this->secret->record_event( $event );

		try {
			do_action( $action, $batch_id, $payload );
		} catch ( \Throwable $e ) {
			// Oyster retries with the same delivery id, so a listener that failed must
			// not leave the delivery claimed or the retry is turned away.
			delete_option( $this->claim_key( $delivery ) );

			return new WP_REST_Response( array( 'error' => 'listener_failed' ), 500 );
		}

		// Seen only once the listener has finished: marked before it, a request that
		// died part way (a fatal, a timeout) left every retry acknowledged as a
		// duplicate, and the work was never done.
		add_option( $this->seen_key( $delivery ), time(), '', 'no' );
		delete_option( $this->claim_key( $delivery ) );

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * True when this attempt may run the listeners. `add_option` with autoload off
	 * rather than a transient: a transient read-then-write races two concurrent
	 * retries, while add_option fails atomically on the unique key. A claim left by
	 * an attempt that died is taken over with a compare-and-set, so two retries
	 * cannot both take it.
	 */
	private function claim( string $delivery ): bool {
		global $wpdb;

		$key = $this->claim_key( $delivery );

		if ( false !== add_option( $key, time(), '', 'no' ) ) {
			$this->forget_expired();

			return true;
		}

		$claimed_at = (int) get_option( $key );
		if ( $claimed_at > time() - self::CLAIM_TTL ) {
			return false;
		}

		$taken = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => (string) time() ),
			array(
				'option_name'  => $key,
				'option_value' => (string) $claimed_at,
			)
		);
		wp_cache_delete( $key, 'options' );

		return 1 === $taken;
	}

	private function seen_key( string $delivery ): string {
		return 'oyster_woo_seen_' . md5( $delivery );
	}

	private function claim_key( string $delivery ): string {
		return 'oyster_woo_claim_' . md5( $delivery );
	}

	private function forget_expired(): void {
		global $wpdb;

		// Bounded so one request never sweeps a large backlog.
		$stale = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options}
				 WHERE ( option_name LIKE 'oyster_woo_seen_%%' OR option_name LIKE 'oyster_woo_claim_%%' ) AND option_value < %d
				 LIMIT 50",
				time() - self::SEEN_TTL
			)
		);

		foreach ( (array) $stale as $name ) {
			delete_option( $name );
		}
	}
}
