<?php
/**
 * "Widget" admin screen — controls the storefront float launcher.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Admin;

use Oyster\Woo\Api\Client;
use Oyster\Woo\Support\Connection;
use Oyster\Woo\Support\Widget_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Built on the Settings API: registers one setting (the widget settings array)
 * with a sanitize callback, and renders the fields. Saving is handled natively
 * by options.php, so there's no custom POST handler here.
 */
final class Widget_Settings_Screen {

	private const GROUP = 'oyster_woo_widget';

	private const SECTION = 'oyster_woo_widget_launcher';

	public function __construct(
		private Connection $connection,
		private Client $client
	) {}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
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

		if ( '' === $this->connection->public_key() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Your vendor account has no widget public key yet, so the widget will not render on the storefront. Finish widget setup in your Oyster dashboard, then reconnect.', 'oyster-woocommerce' )
			);
		}

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
