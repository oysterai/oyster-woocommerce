<?php
/**
 * "Widget" admin screen — controls the storefront float launcher.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Admin;

use Oyster\Woo\Api\Client;
use Oyster\Woo\Frontend\Scan_Page;
use Oyster\Woo\Support\Connection;
use Oyster\Woo\Support\Dashboard_Link;
use Oyster\Woo\Support\Widget_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Built on the Settings API: registers one setting (the widget settings array)
 * with a sanitize callback, and renders the fields. Saving the settings is
 * handled natively by options.php; the scan page's own actions are the one
 * thing here with a POST handler, since creating a page isn't an option write.
 */
final class Widget_Settings_Screen {

	private const GROUP = 'oyster_woo_widget';

	private const SECTION = 'oyster_woo_widget_launcher';

	private const NOTICE_TRANSIENT = 'oyster_woo_scan_page_notice_';

	public function __construct(
		private Connection $connection,
		private Client $client,
		private Scan_Page $scan_page
	) {}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_oyster_woo_create_scan_page', array( $this, 'handle_create_scan_page' ) );
		add_action( 'admin_post_oyster_woo_scan_page_visibility', array( $this, 'handle_scan_page_visibility' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	public function register_settings(): void {
		register_setting(
			self::GROUP,
			Widget_Settings::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Widget_Settings::class, 'sanitize' ),
				'default'           => Widget_Settings::defaults(),
			)
		);

		add_settings_section(
			self::SECTION,
			__( 'Floating launcher', 'oyster-woocommerce' ),
			function (): void {
				echo '<p class="description">' . esc_html__( 'The floating button that opens the skin-scan on every storefront page. For an inline scan, use the "Oyster Skin Scan" block or the [oyster_scan] shortcode on any page.', 'oyster-woocommerce' ) . '</p>';
			},
			self::GROUP
		);

		$this->add_field( 'float_enabled', __( 'Show floating launcher', 'oyster-woocommerce' ), array( $this, 'field_float_enabled' ) );
		$this->add_field( 'primary_color', __( 'Primary color', 'oyster-woocommerce' ), array( $this, 'field_primary_color' ) );
		$this->add_field( 'intro_message', __( 'Intro message', 'oyster-woocommerce' ), array( $this, 'field_intro_message' ) );
		$this->add_field( 'message_body', __( 'Message body', 'oyster-woocommerce' ), array( $this, 'field_message_body' ) );
		$this->add_field( 'display_logo', __( 'Show Oyster logo', 'oyster-woocommerce' ), array( $this, 'field_display_logo' ) );
		$this->add_field( 'auto_open', __( 'Auto-open on load', 'oyster-woocommerce' ), array( $this, 'field_auto_open' ) );
	}

	public function render(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this integration.', 'oyster-woocommerce' ) );
		}

		echo '<div class="wrap oyster-woo">';
		printf( '<h1>%s</h1>', esc_html__( 'Oyster widget', 'oyster-woocommerce' ) );

		if ( ! $this->connection->is_connected() ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Connect your store to Oyster before configuring the widget.', 'oyster-woocommerce' ),
				esc_url( admin_url( 'admin.php?page=' . Menu::PARENT_SLUG ) ),
				esc_html__( 'Go to Connection', 'oyster-woocommerce' )
			);
			echo '</div>';

			return;
		}

		echo '<p>';
		Dashboard_Link::render_button();
		echo '</p>';

		if ( '' === $this->connection->public_key() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Your vendor account has no widget public key yet, so the widget will not render on the storefront. Finish widget setup in your Oyster dashboard, then reconnect.', 'oyster-woocommerce' )
			);
		}

		$this->render_scan_page_card();

		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );
		do_settings_sections( self::GROUP );
		submit_button();
		echo '</form>';

		echo '</div>';
	}

	/*
	 * -----------------------------------------------------------------------
	 * Fields
	 * -----------------------------------------------------------------------
	 */

	public function field_float_enabled(): void {
		$value = Widget_Settings::get()['float_enabled'];
		$this->checkbox( 'float_enabled', (bool) $value, __( 'Display the floating launcher on the storefront', 'oyster-woocommerce' ) );
	}

	public function field_primary_color(): void {
		$value = (string) Widget_Settings::get()['primary_color'];
		printf(
			'<input type="text" name="%s[primary_color]" value="%s" class="oyster-color-field regular-text" placeholder="%s" pattern="^#([A-Fa-f0-9]{6})$"> <span class="description">%s</span>',
			esc_attr( Widget_Settings::OPTION_KEY ),
			esc_attr( $value ),
			esc_attr( $this->connection->primary_color() ),
			esc_html__( 'Leave blank to use your Oyster-configured color.', 'oyster-woocommerce' )
		);
	}

	public function field_intro_message(): void {
		$value = (string) Widget_Settings::get()['intro_message'];
		printf(
			'<input type="text" name="%s[intro_message]" value="%s" class="regular-text">',
			esc_attr( Widget_Settings::OPTION_KEY ),
			esc_attr( $value )
		);
	}

	public function field_message_body(): void {
		$value = (string) Widget_Settings::get()['message_body'];
		printf(
			'<textarea name="%s[message_body]" rows="3" class="large-text">%s</textarea>',
			esc_attr( Widget_Settings::OPTION_KEY ),
			esc_textarea( $value )
		);
	}

	public function field_display_logo(): void {
		$value = Widget_Settings::get()['display_logo'];
		$this->checkbox( 'display_logo', (bool) $value, __( 'Show the Oyster logo in the launcher', 'oyster-woocommerce' ) );
	}

	public function field_auto_open(): void {
		$value = Widget_Settings::get()['auto_open'];
		$this->checkbox( 'auto_open', (bool) $value, __( 'Open the scan automatically when the page loads', 'oyster-woocommerce' ) );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Scan page
	 * -----------------------------------------------------------------------
	 */

	/**
	 * The third way onto a storefront, alongside the launcher and the block: a
	 * page of its own the merchant can link to. The block and the shortcode are
	 * untouched by it — the page this creates is simply a page with that block
	 * on it, editable like any other.
	 */
	private function render_scan_page_card(): void {
		echo '<div class="oyster-card" style="max-width:640px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:24px;margin:16px 0;">';
		printf( '<h2 style="margin-top:0;">%s</h2>', esc_html__( 'Scan page', 'oyster-woocommerce' ) );

		if ( ! $this->scan_page->exists() ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'A page of its own with the scan on it, for sending people a link without putting the scan on your storefront. It is not added to your menus.', 'oyster-woocommerce' )
			);

			$this->action_form(
				'oyster_woo_create_scan_page',
				array(),
				__( 'Create scan page', 'oyster-woocommerce' ),
				'button button-secondary'
			);

			echo '</div>';

			return;
		}

		$unlisted = $this->scan_page->is_unlisted();

		printf(
			'<p><a href="%1$s" target="_blank" rel="noreferrer">%1$s</a></p>',
			esc_url( $this->scan_page->url() )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html(
				$unlisted
					? __( "Only people with the link reach it: search engines and your site's search skip it.", 'oyster-woocommerce' )
					: __( "Search engines and your site's search can find it.", 'oyster-woocommerce' )
			)
		);

		// A div, not a p: these buttons are forms, which a paragraph can't hold.
		echo '<div style="display:flex;gap:8px;align-items:center;">';
		printf(
			'<a class="button" href="%s">%s</a>',
			esc_url( $this->scan_page->edit_url() ),
			esc_html__( 'Edit page', 'oyster-woocommerce' )
		);

		$this->action_form(
			'oyster_woo_scan_page_visibility',
			array( 'unlisted' => $unlisted ? '0' : '1' ),
			$unlisted
				? __( 'Allow search to find it', 'oyster-woocommerce' )
				: __( 'Hide it from search', 'oyster-woocommerce' ),
			'button'
		);
		echo '</div>';

		echo '</div>';
	}

	public function handle_create_scan_page(): void {
		$this->authorize( 'oyster_woo_create_scan_page' );

		$result = $this->scan_page->create();

		$this->notify(
			is_wp_error( $result ) ? 'error' : 'success',
			is_wp_error( $result )
				/* translators: %s: the reason WordPress gave for refusing to create the page. */
				? sprintf( __( 'Could not create the scan page: %s', 'oyster-woocommerce' ), $result->get_error_message() )
				: __( 'Scan page created.', 'oyster-woocommerce' )
		);

		$this->redirect_back();
	}

	public function handle_scan_page_visibility(): void {
		$this->authorize( 'oyster_woo_scan_page_visibility' );

		$requested = $_POST['unlisted'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() checks it.
		$unlisted  = is_string( $requested ) && '1' === $requested;

		$this->scan_page->set_unlisted( $unlisted );

		$this->notify(
			'success',
			$unlisted
				? __( 'The scan page is now hidden from search.', 'oyster-woocommerce' )
				: __( 'The scan page can now be found in search.', 'oyster-woocommerce' )
		);

		$this->redirect_back();
	}

	public function render_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, Menu::WIDGET_SLUG ) ) {
			return;
		}

		$key    = self::NOTICE_TRANSIENT . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			'error' === $notice['type'] ? 'error' : 'success',
			esc_html( (string) $notice['message'] )
		);
	}

	private function authorize( string $nonce_action ): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'oyster-woocommerce' ) );
		}

		check_admin_referer( $nonce_action );
	}

	private function notify( string $type, string $message ): void {
		set_transient(
			self::NOTICE_TRANSIENT . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
	}

	private function redirect_back(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . Menu::WIDGET_SLUG ) );
		exit;
	}

	/**
	 * @param array<string, string> $fields Hidden inputs carried with the action.
	 */
	private function action_form( string $action, array $fields, string $label, string $class ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0;">';
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( $action ) );

		foreach ( $fields as $name => $value ) {
			printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $name ), esc_attr( $value ) );
		}

		wp_nonce_field( $action );
		printf( '<button type="submit" class="%s">%s</button>', esc_attr( $class ), esc_html( $label ) );
		echo '</form>';
	}

	/*
	 * -----------------------------------------------------------------------
	 * Helpers
	 * -----------------------------------------------------------------------
	 */

	/**
	 * @param callable $cb
	 */
	private function add_field( string $id, string $label, callable $cb ): void {
		add_settings_field( 'oyster_woo_' . $id, $label, $cb, self::GROUP, self::SECTION );
	}

	private function checkbox( string $key, bool $checked, string $label ): void {
		printf(
			'<label><input type="checkbox" name="%s[%s]" value="1" %s> %s</label>',
			esc_attr( Widget_Settings::OPTION_KEY ),
			esc_attr( $key ),
			checked( $checked, true, false ),
			esc_html( $label )
		);
	}
}
