<?php
/**
 * "Connection" admin screen — binds the store to an Oyster vendor.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Admin;

use Oyster\Woo\Api\Api_Exception;
use Oyster\Woo\Api\Client;
use Oyster\Woo\Support\Connection;
use Oyster\Woo\Support\Dashboard_Link;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the login / disconnect flow. Form posts route through admin-post.php so
 * each handler can verify a nonce + capability before touching the API, then
 * redirect back with a transient-backed notice.
 *
 * Account creation deliberately isn't here: merchants sign up in the Oyster
 * dashboard, so this screen only ever links out to it. Keeping one signup path
 * means wp-admin never collects a password for an account that doesn't exist
 * yet, and email verification, plan selection and terms stay in the one place
 * that owns them.
 */
final class Connect_Screen {

	private const NOTICE_TRANSIENT = 'oyster_woo_notice_';

	public function __construct(
		private Connection $connection,
		private Client $client,
		private Setup_Guide $setup_guide
	) {}

	/**
	 * Holds the short-lived login session between the password step and the code
	 * step. Per-user and deliberately short: it is only a bridge across one form
	 * post, and it is deleted the moment the code is accepted or the flow is
	 * abandoned.
	 */
	private const PENDING_TRANSIENT = 'oyster_woo_pending_connect_';

	/** Matches how long Oyster keeps a connection code usable. */
	private const PENDING_TTL = 10 * MINUTE_IN_SECONDS;

	public function register(): void {
		add_action( 'admin_post_oyster_woo_connect', array( $this, 'handle_connect' ) );
		add_action( 'admin_post_oyster_woo_verify', array( $this, 'handle_verify' ) );
		add_action( 'admin_post_oyster_woo_resend', array( $this, 'handle_resend' ) );
		add_action( 'admin_post_oyster_woo_cancel_connect', array( $this, 'handle_cancel' ) );
		add_action( 'admin_post_oyster_woo_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Handlers
	 * -----------------------------------------------------------------------
	 */

	public function handle_connect(): void {
		$this->guard( 'oyster_woo_connect' );

		$email    = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$password = (string) wp_unslash( $_POST['password'] ?? '' ); // Never sanitize a password.

		if ( '' === $email || '' === $password ) {
			$this->redirect_back( 'error', __( 'Enter both your Oyster email and password.', 'oyster-woocommerce' ) );
		}

		try {
			$auth = $this->client->login( $email, $password );
		} catch ( Api_Exception $e ) {
			if ( in_array( $e->status(), array( 401, 422 ), true ) ) {
				$this->redirect_back( 'error', __( 'Invalid Oyster email or password.', 'oyster-woocommerce' ) );
			}
			$this->redirect_back( 'error', $this->transport_message( $e ) );
		}

		$token = is_string( $auth['token'] ?? null ) ? $auth['token'] : '';
		if ( '' === $token ) {
			$this->redirect_back( 'error', __( 'Your Oyster account needs email verification before it can connect.', 'oyster-woocommerce' ) );
		}

		if ( 'vendor' !== ( $auth['user']['user_type'] ?? '' ) ) {
			$this->redirect_back( 'error', __( 'That account is not an Oyster vendor account.', 'oyster-woocommerce' ) );
		}

		// Password proved, but not enough on its own: connecting produces a
		// credential that stays valid until this store disconnects, so Oyster
		// also emails a one-time code. Nothing is written locally until it is
		// entered — a half-finished login leaves no trace.
		$sent_to = $this->send_code( $token );

		$this->stash_pending( $token, $sent_to );

		$this->redirect_back( 'code_sent' );
	}

	/**
	 * Step two: the emailed code. Completes the connection and stores the
	 * long-lived credential this store authenticates with from now on.
	 */
	public function handle_verify(): void {
		$this->guard( 'oyster_woo_verify' );

		$pending = $this->pending();
		if ( null === $pending ) {
			$this->redirect_back( 'error', __( 'That took too long — please log in again.', 'oyster-woocommerce' ) );
		}

		$code = trim( (string) wp_unslash( $_POST['code'] ?? '' ) );
		if ( '' === $code ) {
			$this->redirect_back( 'error', __( 'Enter the code we emailed you.', 'oyster-woocommerce' ) );
		}

		$login_token = (string) $pending['token'];

		// Connect first: this records the store on the vendor, ensures the
		// vendor has widget keys, allow-lists this store's origin, and returns
		// both the public_key and this store's own credential — so even a
		// brand-new vendor is widget-ready before we read the config below.
		//
		// No longer best-effort, unlike before: the credential everything else
		// authenticates with comes back from this call, so there is nothing
		// usable to save if it fails.
		try {
			$connect = $this->client->connect_store( $login_token, home_url(), $code );
		} catch ( Api_Exception $e ) {
			if ( 422 === $e->status() ) {
				// Wrong, expired, already used, or too many tries — Oyster does
				// not distinguish these, so neither can we.
				$this->redirect_back( 'error', __( 'That code was not accepted. Request a new one and try again.', 'oyster-woocommerce' ) );
			}
			$this->redirect_back( 'error', $this->transport_message( $e ) );
		}

		$connection_token        = is_string( $connect['connection_token'] ?? null ) ? $connect['connection_token'] : '';
		$public_key_from_connect = is_string( $connect['public_key'] ?? null ) ? $connect['public_key'] : '';

		if ( '' === $connection_token ) {
			$this->redirect_back( 'error', __( 'Oyster did not return a store credential. Please try connecting again.', 'oyster-woocommerce' ) );
		}

		// Everything from here uses the store's own credential, not the login
		// session — that session is short-lived and belongs to a person, while
		// this belongs to the store.
		try {
			$profile = $this->client->get_vendor_profile( $connection_token );
			$config  = $this->client->get_widget_config( $connection_token );
		} catch ( Api_Exception $e ) {
			$this->redirect_back( 'error', $this->transport_message( $e ) );
		}

		$vendor = $profile['vendor'] ?? null;
		if ( ! is_array( $vendor ) || empty( $vendor['id'] ) ) {
			$this->redirect_back( 'error', __( 'Could not load your Oyster vendor profile. Please try again.', 'oyster-woocommerce' ) );
		}

		$widget     = is_array( $config['data'] ?? null ) ? $config['data'] : array();
		$public_key = (string) ( $widget['public_key'] ?? '' );
		if ( '' === $public_key ) {
			$public_key = $public_key_from_connect;
		}

		$this->connection->save(
			array(
				// The store's credential. The login session is never persisted:
				// it expires, and it identifies a person rather than this store.
				'bearer'        => $connection_token,
				'vendor_id'     => (int) $vendor['id'],
				'business_name' => (string) ( $vendor['business_name'] ?? '' ),
				'public_key'    => $public_key,
				'primary_color' => (string) ( $widget['button_color'] ?? '' ),
				'logo_url'      => (string) ( $widget['logo_url'] ?? '' ),
				'widget_types'  => (array) ( $widget['widget_types'] ?? array() ),
				'store_url'     => home_url(),
			)
		);

		$this->forget_pending();
		$this->redirect_back( 'connected' );
	}

	/** Send another code, reusing the login session already proved. */
	public function handle_resend(): void {
		$this->guard( 'oyster_woo_resend' );

		$pending = $this->pending();
		if ( null === $pending ) {
			$this->redirect_back( 'error', __( 'That took too long — please log in again.', 'oyster-woocommerce' ) );
		}

		$sent_to = $this->send_code( (string) $pending['token'] );
		$this->stash_pending( (string) $pending['token'], $sent_to );

		$this->redirect_back( 'code_sent' );
	}

	/** Abandon a half-finished connection, dropping the stashed login session. */
	public function handle_cancel(): void {
		$this->guard( 'oyster_woo_cancel_connect' );

		$this->forget_pending();
		$this->redirect_back( 'cancelled' );
	}

	public function handle_disconnect(): void {
		$this->guard( 'oyster_woo_disconnect' );

		// Retire the credential at Oyster BEFORE forgetting it here. Deleting
		// only the local copy used to leave a working key behind, so a store
		// that was disconnected because it had been compromised stayed
		// connected from Oyster's side.
		$bearer = $this->connection->bearer();
		if ( null !== $bearer ) {
			try {
				$this->client->disconnect_store( $bearer );
			} catch ( Api_Exception $e ) {
				// Deliberately not fatal. Someone disconnecting wants this store
				// disconnected, and refusing because Oyster was unreachable would
				// strand them with no way to finish. The local binding goes either
				// way; an orphaned credential can be revoked from the Oyster
				// dashboard, where it is listed.
				$this->log( 'disconnect failed remotely, clearing locally anyway: ' . $e->user_message() );
			}
		}

		$this->connection->clear();
		$this->forget_pending();
		$this->redirect_back( 'disconnected' );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Views
	 * -----------------------------------------------------------------------
	 */

	public function render(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this integration.', 'oyster-woocommerce' ) );
		}

		echo '<div class="wrap oyster-woo">';
		printf( '<h1>%s</h1>', esc_html__( 'Oyster for WooCommerce', 'oyster-woocommerce' ) );

		$pending = $this->pending();

		if ( $this->connection->is_connected() ) {
			$this->render_connected();
		} elseif ( null !== $pending ) {
			$this->render_code_step( $pending );
		} else {
			$this->render_disconnected();
		}

		echo '</div>';
	}

	private function render_connected(): void {
		$name         = $this->connection->business_name();
		$has_widget   = '' !== $this->connection->public_key();
		$connected_at = $this->connection->connected_at();

		$this->setup_guide->render();

		echo '<div class="oyster-card" style="max-width:640px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:24px;margin-top:16px;">';

		printf(
			'<p style="font-size:14px;"><span style="display:inline-block;width:10px;height:10px;border-radius:50%%;background:#00a32a;margin-right:8px;"></span>%s <strong>%s</strong></p>',
			esc_html__( 'Connected to Oyster vendor', 'oyster-woocommerce' ),
			esc_html( '' !== $name ? $name : __( '(unnamed vendor)', 'oyster-woocommerce' ) )
		);

		if ( $connected_at > 0 ) {
			printf(
				'<p class="description">%s %s</p>',
				esc_html__( 'Connected', 'oyster-woocommerce' ),
				esc_html( human_time_diff( $connected_at ) . ' ' . __( 'ago', 'oyster-woocommerce' ) )
			);
		}

		if ( ! $has_widget ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__( 'Your vendor account has no widget public key yet. Finish widget setup in your Oyster dashboard, then reconnect to pull it in.', 'oyster-woocommerce' )
			);
		}

		echo '<p style="margin-top:20px;display:flex;gap:12px;flex-wrap:wrap;">';

		Dashboard_Link::render_button( '', 'button button-primary' );

		printf(
			'<a class="button" href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . Menu::WIDGET_SLUG ) ),
			esc_html__( 'Widget settings', 'oyster-woocommerce' )
		);

		echo '</p>';

		// Disconnect — its own form so the destructive action carries its own nonce.
		echo '<hr style="margin:20px 0;border:none;border-top:1px solid #f0f0f1;">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="oyster_woo_disconnect">';
		wp_nonce_field( 'oyster_woo_disconnect' );
		printf(
			'<button type="submit" class="button-link-delete" style="color:#b32d2e;">%s</button>',
			esc_html__( 'Disconnect this store from Oyster', 'oyster-woocommerce' )
		);
		echo '</form>';

		echo '</div>';
	}

	/**
	 * The code step. Shown between logging in and being connected, for as long
	 * as the parked login session lives.
	 */
	private function render_code_step( array $pending ): void {
		$sent_to = (string) $pending['sent_to'];

		printf(
			'<p style="max-width:640px;">%s</p>',
			esc_html__( 'One more step. Connecting produces a credential this store keeps using, so we emailed you a code to confirm it is really you.', 'oyster-woocommerce' )
		);

		echo '<div class="oyster-card" style="max-width:480px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:24px;margin-top:8px;">';
		printf( '<h2 style="margin-top:0;">%s</h2>', esc_html__( 'Enter your code', 'oyster-woocommerce' ) );

		if ( '' !== $sent_to ) {
			printf(
				'<p class="description">%s <strong>%s</strong></p>',
				esc_html__( 'Sent to', 'oyster-woocommerce' ),
				esc_html( $sent_to )
			);
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="oyster_woo_verify">';
		wp_nonce_field( 'oyster_woo_verify' );

		// `one-time-code` lets browsers and iOS offer the code straight from the
		// email, which is most of why people get this step right first time.
		printf(
			'<p><label for="oyster_woo_code" style="display:block;font-weight:600;margin-bottom:4px;">%s</label>'
			. '<input type="text" id="oyster_woo_code" name="code" class="regular-text" style="width:100%%;letter-spacing:0.3em;font-size:18px;"'
			. ' inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]*" required autofocus></p>',
			esc_html__( 'Code', 'oyster-woocommerce' )
		);

		printf(
			'<p><button type="submit" class="button button-primary">%s</button></p>',
			esc_html__( 'Finish connecting', 'oyster-woocommerce' )
		);
		echo '</form>';

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'The code expires in about 10 minutes and can be used once.', 'oyster-woocommerce' )
		);

		// Resend and cancel are separate posts so each carries its own nonce.
		echo '<div style="display:flex;gap:16px;align-items:center;margin-top:12px;">';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="oyster_woo_resend">';
		wp_nonce_field( 'oyster_woo_resend' );
		printf(
			'<button type="submit" class="button-link">%s</button>',
			esc_html__( 'Send a new code', 'oyster-woocommerce' )
		);
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="oyster_woo_cancel_connect">';
		wp_nonce_field( 'oyster_woo_cancel_connect' );
		printf(
			'<button type="submit" class="button-link" style="color:#b32d2e;">%s</button>',
			esc_html__( 'Cancel', 'oyster-woocommerce' )
		);
		echo '</form>';

		echo '</div>';
		echo '</div>';
	}

	private function render_disconnected(): void {
		printf(
			'<p style="max-width:640px;">%s</p>',
			esc_html__( 'Connect your store to an Oyster vendor account to sync your catalog and show the skin-scan widget on your storefront.', 'oyster-woocommerce' )
		);

		echo '<div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:8px;">';

		// --- Login ---------------------------------------------------------
		echo '<div class="oyster-card" style="flex:1;min-width:320px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:24px;">';
		printf( '<h2 style="margin-top:0;">%s</h2>', esc_html__( 'Log in to Oyster', 'oyster-woocommerce' ) );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="oyster_woo_connect">';
		wp_nonce_field( 'oyster_woo_connect' );
		$this->text_field( 'email', __( 'Oyster email', 'oyster-woocommerce' ), 'email', true );
		$this->text_field( 'password', __( 'Password', 'oyster-woocommerce' ), 'password', true );
		printf(
			'<p><button type="submit" class="button button-primary">%s</button></p>',
			esc_html__( 'Connect', 'oyster-woocommerce' )
		);
		echo '</form>';
		echo '</div>';

		// --- No account yet ------------------------------------------------
		echo '<div class="oyster-card" style="flex:1;min-width:320px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:24px;">';
		printf( '<h2 style="margin-top:0;">%s</h2>', esc_html__( 'New to Oyster?', 'oyster-woocommerce' ) );
		printf(
			'<p>%s</p>',
			esc_html__( 'Create your vendor account in the Oyster dashboard — it takes a minute. Once your email is verified, come back here and log in to connect this store.', 'oyster-woocommerce' )
		);
		printf(
			'<p><a class="button" href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
			esc_url( Dashboard_Link::register_url() ),
			esc_html__( 'Create an Oyster account', 'oyster-woocommerce' )
		);
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Render a labelled input row. Nothing is pre-filled — these are someone
	 * else's service credentials, so autocomplete is off and the browser is
	 * told the password field is not a new-account one.
	 */
	private function text_field( string $name, string $label, string $type, bool $required ): void {
		$id = 'oyster_woo_' . $name;
		printf( '<p><label for="%s" style="display:block;font-weight:600;margin-bottom:4px;">%s</label>', esc_attr( $id ), esc_html( $label ) );
		printf(
			'<input type="%s" id="%s" name="%s" class="regular-text" style="width:100%%;" autocomplete="%s" %s></p>',
			esc_attr( $type ),
			esc_attr( $id ),
			esc_attr( $name ),
			'password' === $type ? 'current-password' : 'off',
			$required ? 'required' : ''
		);
	}

	public function render_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, Menu::PARENT_SLUG ) ) {
			return;
		}

		$key    = self::NOTICE_TRANSIENT . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );

		$map = array(
			'connected'    => array( 'success', __( 'Store connected to Oyster.', 'oyster-woocommerce' ) ),
			'disconnected' => array( 'success', __( 'Store disconnected from Oyster.', 'oyster-woocommerce' ) ),
			'code_sent'    => array( 'success', __( 'We emailed you a code. Enter it below to finish connecting.', 'oyster-woocommerce' ) ),
			'cancelled'    => array( 'success', __( 'Connection cancelled.', 'oyster-woocommerce' ) ),
			'error'        => array( 'error', (string) ( $notice['detail'] ?? __( 'Something went wrong.', 'oyster-woocommerce' ) ) ),
		);

		$status = (string) ( $notice['status'] ?? '' );
		if ( ! isset( $map[ $status ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $map[ $status ][0] ),
			esc_html( $map[ $status ][1] )
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Helpers
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Ask Oyster to email a code, returning the masked address it went to.
	 * Terminates the request on failure.
	 */
	private function send_code( string $login_token ): string {
		try {
			$response = $this->client->request_connect_code( $login_token );
		} catch ( Api_Exception $e ) {
			if ( 429 === $e->status() ) {
				$this->redirect_back( 'error', __( 'Too many codes requested. Wait a few minutes and try again.', 'oyster-woocommerce' ) );
			}
			$this->redirect_back( 'error', $this->transport_message( $e ) );
		}

		$data = is_array( $response['data'] ?? null ) ? $response['data'] : array();

		return is_string( $data['sent_to'] ?? null ) ? $data['sent_to'] : '';
	}

	/**
	 * Park the proved login session until the code arrives.
	 *
	 * Held in a per-user transient rather than a form field: it is a bearer
	 * credential, and round-tripping it through the browser would put it in
	 * page source, history and any proxy in between.
	 */
	private function stash_pending( string $token, string $sent_to ): void {
		set_transient(
			self::PENDING_TRANSIENT . get_current_user_id(),
			array(
				'token'   => $token,
				'sent_to' => $sent_to,
			),
			self::PENDING_TTL
		);
	}

	/**
	 * The parked login session, or null once it has expired or been used.
	 *
	 * @return array{token: string, sent_to: string}|null
	 */
	private function pending(): ?array {
		$pending = get_transient( self::PENDING_TRANSIENT . get_current_user_id() );

		if ( ! is_array( $pending ) || empty( $pending['token'] ) ) {
			return null;
		}

		return array(
			'token'   => (string) $pending['token'],
			'sent_to' => (string) ( $pending['sent_to'] ?? '' ),
		);
	}

	private function forget_pending(): void {
		delete_transient( self::PENDING_TRANSIENT . get_current_user_id() );
	}

	/**
	 * Verify nonce + capability for a form post, dying on failure.
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'oyster-woocommerce' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Store a notice and bounce back to the Connection page. Terminates.
	 */
	private function redirect_back( string $status, string $detail = '' ): void {
		set_transient(
			self::NOTICE_TRANSIENT . get_current_user_id(),
			array(
				'status' => $status,
				'detail' => $detail,
			),
			60
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . Menu::PARENT_SLUG ) );
		exit;
	}

	private function transport_message( Api_Exception $e ): string {
		return $e->is_transport_error()
			? __( 'Could not reach Oyster. Check your connection and try again.', 'oyster-woocommerce' )
			: sprintf(
				/* translators: %s: error detail from the API */
				__( 'Oyster returned an error: %s', 'oyster-woocommerce' ),
				$e->user_message()
			);
	}

	private function log( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $message, array( 'source' => 'oyster-woocommerce' ) );
		}
	}
}
