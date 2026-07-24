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
 * Owns the login / signup / disconnect flow. Form posts route through
 * admin-post.php so each handler can verify a nonce + capability before
 * touching the API, then redirect back with a transient-backed notice.
 */
final class Connect_Screen {

	private const NOTICE_TRANSIENT = 'oyster_woo_notice_';

	public function __construct(
		private Connection $connection,
		private Client $client
	) {}

	public function register(): void {
		add_action( 'admin_post_oyster_woo_connect', array( $this, 'handle_connect' ) );
		add_action( 'admin_post_oyster_woo_signup', array( $this, 'handle_signup' ) );
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

		// Connect first: this records the store on the vendor, ensures the
		// vendor has widget keys, allow-lists this store's origin, and returns
		// the public_key — so even a brand-new vendor is widget-ready before we
		// read the config below. Best-effort: a failure here still leaves the
		// local binding intact and self-heals on the next connect.
		$public_key_from_connect = '';
		try {
			$connect                 = $this->client->connect_store( $token, home_url() );
			$public_key_from_connect = is_string( $connect['public_key'] ?? null ) ? $connect['public_key'] : '';
		} catch ( Api_Exception $e ) {
			$this->log( 'connect_store failed: ' . $e->user_message() );
		}

		try {
			$profile = $this->client->get_vendor_profile( $token );
			$config  = $this->client->get_widget_config( $token );
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
				'bearer'        => $token,
				'vendor_id'     => (int) $vendor['id'],
				'business_name' => (string) ( $vendor['business_name'] ?? '' ),
				'public_key'    => $public_key,
				'primary_color' => (string) ( $widget['button_color'] ?? '' ),
				'logo_url'      => (string) ( $widget['logo_url'] ?? '' ),
				'widget_types'  => (array) ( $widget['widget_types'] ?? array() ),
				'store_url'     => home_url(),
			)
		);

		$this->redirect_back( 'connected' );
	}

	public function handle_signup(): void {
		$this->guard( 'oyster_woo_signup' );

		$fields = array(
			'business_name'  => sanitize_text_field( wp_unslash( $_POST['business_name'] ?? '' ) ),
			'business_email' => sanitize_email( wp_unslash( $_POST['business_email'] ?? '' ) ),
			'business_phone' => sanitize_text_field( wp_unslash( $_POST['business_phone'] ?? '' ) ),
			'first_name'     => sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ),
			'last_name'      => sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) ),
			'password'       => (string) wp_unslash( $_POST['password'] ?? '' ),
		);

		if ( '' === $fields['business_email'] || '' === $fields['password'] || '' === $fields['business_name'] ) {
			$this->redirect_back( 'error', __( 'Business name, email and a password are required to create an account.', 'oyster-woocommerce' ) );
		}

		try {
			$this->client->register_vendor( $fields );
		} catch ( Api_Exception $e ) {
			if ( 422 === $e->status() ) {
				$this->redirect_back( 'error', $this->validation_message( $e ) );
			}
			$this->redirect_back( 'error', $this->transport_message( $e ) );
		}

		$this->redirect_back( 'signup_pending' );
	}

	public function handle_disconnect(): void {
		$this->guard( 'oyster_woo_disconnect' );

		$this->connection->clear();
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

		if ( $this->connection->is_connected() ) {
			$this->render_connected();
		} else {
			$this->render_disconnected();
		}

		echo '</div>';
	}

	private function render_connected(): void {
		$name         = $this->connection->business_name();
		$has_widget   = '' !== $this->connection->public_key();
		$connected_at = $this->connection->connected_at();

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

		// --- Signup --------------------------------------------------------
		echo '<div class="oyster-card" style="flex:1;min-width:320px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:24px;">';
		printf( '<h2 style="margin-top:0;">%s</h2>', esc_html__( 'Create an Oyster account', 'oyster-woocommerce' ) );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="oyster_woo_signup">';
		wp_nonce_field( 'oyster_woo_signup' );
		$this->text_field( 'business_name', __( 'Business name', 'oyster-woocommerce' ), 'text', true, get_bloginfo( 'name' ) );
		$this->text_field( 'business_email', __( 'Business email', 'oyster-woocommerce' ), 'email', true, get_bloginfo( 'admin_email' ) );
		$this->text_field( 'business_phone', __( 'Business phone', 'oyster-woocommerce' ), 'text', false );
		$this->text_field( 'password', __( 'Choose a password', 'oyster-woocommerce' ), 'password', true );
		printf(
			'<p><button type="submit" class="button">%s</button></p>',
			esc_html__( 'Create account', 'oyster-woocommerce' )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'We’ll email a verification code. Verify, then log in on the left to finish connecting.', 'oyster-woocommerce' )
		);
		echo '</form>';
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Render a labelled input row. Value is only pre-filled for non-secret
	 * fields; passwords never echo a value.
	 */
	private function text_field( string $name, string $label, string $type, bool $required, string $value = '' ): void {
		$id = 'oyster_woo_' . $name;
		printf( '<p><label for="%s" style="display:block;font-weight:600;margin-bottom:4px;">%s</label>', esc_attr( $id ), esc_html( $label ) );
		printf(
			'<input type="%s" id="%s" name="%s" class="regular-text" style="width:100%%;" value="%s" autocomplete="%s" %s></p>',
			esc_attr( $type ),
			esc_attr( $id ),
			esc_attr( $name ),
			'password' === $type ? '' : esc_attr( $value ),
			'password' === $type ? 'new-password' : 'off',
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
			'connected'      => array( 'success', __( 'Store connected to Oyster.', 'oyster-woocommerce' ) ),
			'disconnected'   => array( 'success', __( 'Store disconnected from Oyster.', 'oyster-woocommerce' ) ),
			'signup_pending' => array( 'info', __( 'Account created. Check your email for a verification code, then log in to connect.', 'oyster-woocommerce' ) ),
			'error'          => array( 'error', (string) ( $notice['detail'] ?? __( 'Something went wrong.', 'oyster-woocommerce' ) ) ),
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

	/**
	 * Flatten a Laravel 422 validation body into a single readable line.
	 */
	private function validation_message( Api_Exception $e ): string {
		$payload = $e->payload();
		if ( is_array( $payload ) && isset( $payload['errors'] ) && is_array( $payload['errors'] ) ) {
			$first = reset( $payload['errors'] );
			if ( is_array( $first ) && isset( $first[0] ) ) {
				return (string) $first[0];
			}
		}

		return $e->user_message();
	}

	private function log( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $message, array( 'source' => 'oyster-woocommerce' ) );
		}
	}
}
