<?php
/**
 * "Scan payments" admin screen — what shoppers pay for a skin scan, and how.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Admin;

use Oyster\Woo\Api\Api_Exception;
use Oyster\Woo\Support\Connection;
use Oyster\Woo\Support\Dashboard_Link;
use Oyster\Woo\Support\Scan_Payment_Methods;
use Oyster\Woo\Support\Scan_Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Sets the price shoppers pay when this store collects scan payments itself.
 *
 * Oyster bills the store for every scan whoever paid, so Oyster's rate is the
 * store's cost and what the shopper pays is the store's to decide — a markup, a
 * discount, or a flat price of its own.
 *
 * ## Why this is not a WordPress option
 *
 * The price is held by Oyster, not here, and this screen reads and writes it
 * over the API. That is deliberate: the shopper is shown the price inside the
 * scan widget *before* they choose to pay, and that quote comes from Oyster. A
 * price kept only in this database would quote one figure in the widget and
 * charge another at checkout.
 *
 * Because the value lives on the other side, this uses a plain POST handler
 * rather than the Settings API, which exists to persist into wp_options.
 *
 * ## The second half of the screen
 *
 * Which payment methods a scan may be paid with is the opposite: a fact about
 * this store's checkout that Oyster has no view of, so it is an option here.
 * Both belong on one screen because a merchant thinking about scan payments is
 * thinking about both at once.
 */
final class Scan_Pricing_Screen {

	private const ACTION = 'oyster_woo_save_scan_pricing';

	private const NONCE = 'oyster_woo_scan_pricing';

	private const ACTION_METHODS = 'oyster_woo_save_scan_methods';

	private const NONCE_METHODS = 'oyster_woo_scan_methods';

	private const ACTION_PACK = 'oyster_woo_save_scan_pack';

	private const NONCE_PACK = 'oyster_woo_scan_pack';

	public function __construct(
		private Connection $connection,
		private Scan_Pricing $pricing
	) {}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_METHODS, array( $this, 'handle_save_methods' ) );
		add_action( 'admin_post_' . self::ACTION_PACK, array( $this, 'handle_save_pack' ) );
	}

	public function handle_save(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this integration.', 'oyster-woocommerce' ) );
		}

		check_admin_referer( self::NONCE );

		$mode = isset( $_POST['mode'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['mode'] ) ) : '';

		if ( ! array_key_exists( $mode, Scan_Pricing::MODES ) ) {
			$this->redirect_back( 'invalid_mode' );
		}

		// Sent as a string so an empty field is distinguishable from a zero — a
		// cast alone would turn "" into 0.0 and quietly save a price of nothing.
		$raw   = isset( $_POST['value'] ) ? trim( (string) wp_unslash( $_POST['value'] ) ) : '';
		$value = ( 'passthrough' === $mode || '' === $raw ) ? null : (float) $raw;

		if ( 'passthrough' !== $mode && null === $value ) {
			$this->redirect_back( 'value_required' );
		}

		try {
			$this->pricing->update( $mode, $value );
		} catch ( Api_Exception $e ) {
			$this->redirect_back( 'failed', $e->user_message() );
		}

		$this->redirect_back( 'saved' );
	}

	/**
	 * Shape the store's scan pack, only the parts the store owns. Whether packs may be
	 * sold, and the margin Oyster pays back, are Oyster account settings.
	 */
	public function handle_save_pack(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this integration.', 'oyster-woocommerce' ) );
		}

		check_admin_referer( self::NONCE_PACK );

		$size = isset( $_POST['pack_size'] ) ? (int) wp_unslash( $_POST['pack_size'] ) : 0;

		if ( $size < 2 ) {
			$this->redirect_back( 'pack_size_invalid' );
		}

		// Strings, so an empty field stays distinguishable from a zero.
		$raw_validity = isset( $_POST['pack_validity_days'] ) ? trim( (string) wp_unslash( $_POST['pack_validity_days'] ) ) : '';
		$validity     = '' === $raw_validity ? null : (int) $raw_validity;

		if ( null !== $validity && $validity < 1 ) {
			$this->redirect_back( 'pack_validity_invalid' );
		}

		$raw_price = isset( $_POST['pack_price_value'] ) ? trim( (string) wp_unslash( $_POST['pack_price_value'] ) ) : '';
		$price     = '' === $raw_price ? null : (float) $raw_price;

		try {
			$this->pricing->update_pack( $size, $validity, $price );
		} catch ( Api_Exception $e ) {
			$this->redirect_back( 'failed', $e->user_message() );
		}

		$this->redirect_back( 'pack_saved' );
	}

	public function handle_save_methods(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this integration.', 'oyster-woocommerce' ) );
		}

		check_admin_referer( self::NONCE_METHODS );

		// Unticking every box posts no field at all, which is the "offer them
		// all" choice rather than a missing submission.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$posted = isset( $_POST['methods'] ) ? (array) wp_unslash( $_POST['methods'] ) : array();

		update_option(
			Scan_Payment_Methods::OPTION_KEY,
			Scan_Payment_Methods::sanitize( $posted, array_keys( Scan_Payment_Methods::enabled_gateways() ) ),
			false
		);

		$this->redirect_back( 'methods_saved' );
	}

	public function render(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this integration.', 'oyster-woocommerce' ) );
		}

		echo '<div class="wrap oyster-woo">';
		printf( '<h1>%s</h1>', esc_html__( 'Scan payments', 'oyster-woocommerce' ) );

		if ( ! $this->connection->is_connected() ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Connect your store to Oyster before setting up scan payments.', 'oyster-woocommerce' ),
				esc_url( admin_url( 'admin.php?page=' . Menu::PARENT_SLUG ) ),
				esc_html__( 'Go to Connection', 'oyster-woocommerce' )
			);
			echo '</div>';

			return;
		}

		$this->render_notice();

		$pricing = $this->pricing->current();

		if ( null === $pricing ) {
			$this->render_unreadable();
			echo '</div>';

			return;
		}

		if ( empty( $pricing['collects_externally'] ) ) {
			$this->render_not_applicable();
			echo '</div>';

			return;
		}

		// Absent means offered: a store on an older Oyster release still sells
		// single scans, and hiding their pricing would leave them unable to change it.
		if ( false !== ( $pricing['single_scan_offered'] ?? true ) ) {
			$this->render_summary( $pricing );
			$this->render_form( $pricing );
		}

		$this->render_pack_form();
		$this->render_payment_methods();

		echo '</div>';
	}

	/**
	 * Shown when the price could not be read.
	 *
	 * Two different problems wear the same symptom. Oyster being briefly
	 * unreachable is fixed by reloading; a credential Oyster refuses is not, and
	 * telling a merchant to reload sends them round a loop that cannot end.
	 */
	private function render_unreadable(): void {
		if ( ! $this->pricing->access_was_denied() ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Could not read your scan pricing from Oyster just now. Reload to try again.', 'oyster-woocommerce' )
			);

			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p><p><a href="%s" class="button">%s</a></p></div>',
			esc_html__( 'Your store\'s connection to Oyster is not permitted to read scan pricing. Reconnecting your store renews it, and nothing else about your setup changes.', 'oyster-woocommerce' ),
			esc_url( admin_url( 'admin.php?page=' . Menu::PARENT_SLUG ) ),
			esc_html__( 'Reconnect your store', 'oyster-woocommerce' )
		);
	}

	/**
	 * Shown when Oyster, not this store, takes the shopper's payment. There is
	 * nothing to set: the shopper pays Oyster's price directly, and a form here
	 * would collect a number that never reaches a checkout.
	 */
	private function render_not_applicable(): void {
		printf(
			'<div class="notice notice-info inline"><p>%s</p></div>',
			esc_html__( 'Oyster collects scan payments for your store, so shoppers pay Oyster\'s price and there is nothing to set here. Contact Oyster if you would like to collect scan payments through your own checkout instead.', 'oyster-woocommerce' )
		);

		$this->render_billing_link();
	}

	/**
	 * @param array<string, mixed> $pricing
	 */
	private function render_summary( array $pricing ): void {
		$currency = (string) ( $pricing['currency'] ?? '' );
		$cost     = isset( $pricing['oyster_rate'] ) ? (float) $pricing['oyster_rate'] : null;
		$price    = isset( $pricing['customer_price'] ) ? (float) $pricing['customer_price'] : null;
		$margin   = ( null !== $cost && null !== $price ) ? round( $price - $cost, 2 ) : null;

		echo '<p class="description" style="max-width:40em">';
		esc_html_e( 'Your store collects scan payments through its own checkout, so you set what shoppers pay. This changes the amount charged — not whether a shopper is charged, and not what Oyster bills you.', 'oyster-woocommerce' );
		echo '</p>';

		echo '<table class="widefat striped" style="max-width:40em;margin:1em 0"><tbody>';
		$this->summary_row( __( 'Oyster charges you', 'oyster-woocommerce' ), $this->money( $cost, $currency ) );
		$this->summary_row( __( 'Your shopper pays', 'oyster-woocommerce' ), $this->money( $price, $currency ) );
		$this->summary_row(
			__( 'You keep', 'oyster-woocommerce' ),
			$this->money( $margin, $currency )
				. ( ( null !== $margin && $margin < 0 )
					? ' — ' . esc_html__( 'you are charging less than a scan costs you', 'oyster-woocommerce' )
					: '' )
		);
		echo '</tbody></table>';

		$this->render_billing_link();
	}

	private function summary_row( string $label, string $value ): void {
		printf(
			'<tr><th scope="row" style="width:14em">%s</th><td><strong>%s</strong></td></tr>',
			esc_html( $label ),
			wp_kses_post( $value )
		);
	}

	private function money( ?float $amount, string $currency ): string {
		if ( null === $amount ) {
			return '&mdash;';
		}

		return esc_html( trim( $currency . ' ' . number_format( $amount, 2 ) ) );
	}

	private function render_billing_link(): void {
		printf(
			'<p><a href="%s" target="_blank" rel="noopener noreferrer">%s</a> &mdash; %s</p>',
			esc_url( Dashboard_Link::billing_url() ),
			esc_html__( 'View your Oyster billing and usage', 'oyster-woocommerce' ),
			esc_html__( 'your current rate, what you have spent, and your invoices.', 'oyster-woocommerce' )
		);
	}

	/**
	 * @param array<string, mixed> $pricing
	 */
	private function render_form( array $pricing ): void {
		$mode     = (string) ( $pricing['mode'] ?? 'passthrough' );
		$value    = isset( $pricing['value'] ) ? (string) $pricing['value'] : '';
		$currency = (string) ( $pricing['currency'] ?? '' );

		printf( '<h2>%s</h2>', esc_html__( 'Pricing', 'oyster-woocommerce' ) );

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::ACTION ) );
		wp_nonce_field( self::NONCE );

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="oyster-pricing-mode">' . esc_html__( 'Pricing', 'oyster-woocommerce' ) . '</label></th><td>';
		echo '<select name="mode" id="oyster-pricing-mode">';
		foreach ( Scan_Pricing::MODES as $key => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $key ),
				selected( $mode, $key, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="oyster-pricing-value">' . esc_html__( 'Amount', 'oyster-woocommerce' ) . '</label></th><td>';
		printf(
			'<input type="number" step="0.01" name="value" id="oyster-pricing-value" value="%s" class="regular-text" />',
			esc_attr( $value )
		);
		echo '<p class="description">';
		printf(
			/* translators: %s: store currency code */
			esc_html__( 'A percentage for "Add a percentage" — negative to discount. Otherwise an amount in %s. Leave empty when charging Oyster\'s rate.', 'oyster-woocommerce' ),
			esc_html( $currency )
		);
		echo '</p>';
		echo '<p class="description">';
		esc_html_e( 'A fixed markup only adds. To charge less than a scan costs you, use a negative percentage or set your own price.', 'oyster-woocommerce' );
		echo '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Save pricing', 'oyster-woocommerce' ) );
		echo '</form>';
	}

	/**
	 * Which of the store's payment methods a scan may be paid with.
	 */
	/**
	 * Several scans on one payment.
	 *
	 * Rendered only when Oyster has enabled packs for this store. Reading the pack is a
	 * second API call, so a store that does not sell packs never pays for it.
	 */
	private function render_pack_form(): void {
		$pack = $this->pricing->current_pack();

		if ( null === $pack || empty( $pack['enabled'] ) ) {
			return;
		}

		$size      = (string) ( $pack['size'] ?? '' );
		$validity  = isset( $pack['validity_days'] ) ? (string) $pack['validity_days'] : '';
		$max_days  = isset( $pack['max_validity_days'] ) ? (int) $pack['max_validity_days'] : 0;
		$needs_val = ! empty( $pack['requires_pack_value'] );
		$price_val = isset( $pack['pack_price_value'] ) ? (string) $pack['pack_price_value'] : '';
		$currency  = (string) ( $pack['currency'] ?? '' );

		printf( '<h2>%s</h2>', esc_html__( 'Scan packs', 'oyster-woocommerce' ) );

		echo '<p class="description">';
		esc_html_e( 'A shopper pays once and gets several scans. They run the rest whenever they like, using the payment reference we email them. The whole pack is settled when it is bought, so a later scan costs you nothing more.', 'oyster-woocommerce' );
		echo '</p>';

		if ( empty( $pack['sellable'] ) ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__( 'No pack is being offered to your shoppers yet. Fill in the fields below to start selling one.', 'oyster-woocommerce' )
			);
		}

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::ACTION_PACK ) );
		wp_nonce_field( self::NONCE_PACK );

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="oyster-pack-size">' . esc_html__( 'Scans per pack', 'oyster-woocommerce' ) . '</label></th><td>';
		printf(
			'<input type="number" step="1" min="2" max="50" name="pack_size" id="oyster-pack-size" value="%s" class="small-text" />',
			esc_attr( $size )
		);
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="oyster-pack-validity">' . esc_html__( 'Usable for (days)', 'oyster-woocommerce' ) . '</label></th><td>';
		printf(
			'<input type="number" step="1" min="1" name="pack_validity_days" id="oyster-pack-validity" value="%s" class="small-text" />',
			esc_attr( $validity )
		);
		echo '<p class="description">';
		if ( $max_days > 0 ) {
			printf(
				/* translators: %d: the longest window this store may set, in days */
				esc_html__( 'Up to %d days on your account. Anything longer is shortened to that.', 'oyster-woocommerce' ),
				(int) $max_days
			);
		} else {
			esc_html_e( 'Leave empty to use the default.', 'oyster-woocommerce' );
		}
		echo ' ';
		esc_html_e( 'A pack stops working after this, so anything unused is lost. Leave your shoppers long enough to come back.', 'oyster-woocommerce' );
		echo '</p>';
		echo '</td></tr>';

		if ( $needs_val ) {
			echo '<tr><th scope="row"><label for="oyster-pack-price">' . esc_html__( 'Pack price', 'oyster-woocommerce' ) . '</label></th><td>';
			printf(
				'<input type="number" step="0.01" name="pack_price_value" id="oyster-pack-price" value="%s" class="regular-text" />',
				esc_attr( $price_val )
			);
			echo '<p class="description">';
			printf(
				/* translators: %s: store currency code */
				esc_html__( 'In %s. Your pricing option needs a figure of its own for packs. A percentage already scales with the pack, but a flat amount belongs to the offer and is charged once.', 'oyster-woocommerce' ),
				esc_html( $currency )
			);
			echo '</p>';
			echo '</td></tr>';
		}

		echo '</tbody></table>';

		submit_button( __( 'Save pack', 'oyster-woocommerce' ) );
		echo '</form>';
	}

	private function render_payment_methods(): void {
		printf( '<h2>%s</h2>', esc_html__( 'Payment methods', 'oyster-woocommerce' ) );

		echo '<p class="description" style="max-width:40em">';
		esc_html_e( 'Choose what a shopper may pay for a scan with. Tick nothing to offer every method your checkout has enabled — your own checkout is unaffected either way.', 'oyster-woocommerce' );
		echo '</p>';

		$enabled = Scan_Payment_Methods::enabled_gateways();

		if ( empty( $enabled ) ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Your checkout has no payment methods enabled, so nothing can be paid for here yet.', 'oyster-woocommerce' ),
				esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ),
				esc_html__( 'Set up payments', 'oyster-woocommerce' )
			);

			return;
		}

		$chosen = Scan_Payment_Methods::chosen();

		// The one state a merchant cannot see coming: they picked a method, then
		// disabled it somewhere else entirely, and scan payments stopped.
		if ( ! empty( $chosen ) && empty( array_intersect( $chosen, array_keys( $enabled ) ) ) ) {
			printf(
				'<div class="notice notice-error inline"><p>%s</p></div>',
				esc_html__( 'None of the methods you chose are enabled in your checkout, so a shopper has no way to pay for a scan. Tick one that is enabled, or untick everything to offer them all.', 'oyster-woocommerce' )
			);
		}

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::ACTION_METHODS ) );
		wp_nonce_field( self::NONCE_METHODS );

		echo '<fieldset>';
		foreach ( $enabled as $id => $title ) {
			// PHP turns a numeric-string array key into an int, so the saved
			// choice would never match one.
			$id = (string) $id;

			printf(
				'<label for="oyster-method-%1$s" style="display:block;margin:.35em 0"><input type="checkbox" id="oyster-method-%1$s" name="methods[]" value="%1$s"%2$s /> %3$s</label>',
				esc_attr( $id ),
				checked( in_array( $id, $chosen, true ), true, false ),
				esc_html( $title )
			);
		}
		echo '</fieldset>';

		// Installing a gateway does not enable it, and an absence here reads as
		// this plugin not supporting it rather than the checkout not offering it.
		printf(
			'<p class="description" style="max-width:40em">%s <a href="%s">%s</a></p>',
			esc_html__( 'Only methods your checkout has enabled are listed.', 'oyster-woocommerce' ),
			esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ),
			esc_html__( 'Manage payment methods', 'oyster-woocommerce' )
		);

		submit_button( __( 'Save payment methods', 'oyster-woocommerce' ), 'secondary' );
		echo '</form>';
	}

	private function render_notice(): void {
		$status = isset( $_GET['oyster_pricing'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['oyster_pricing'] ) ) : '';

		if ( '' === $status ) {
			return;
		}

		$saved = array(
			'saved'         => __( 'Scan pricing updated. Shoppers are quoted the new price from their next scan.', 'oyster-woocommerce' ),
			'pack_saved'    => __( 'Scan pack updated. Shoppers are offered the new pack from their next scan.', 'oyster-woocommerce' ),
			'methods_saved' => __( 'Payment methods updated. Shoppers see the new choice the next time one pays for a scan.', 'oyster-woocommerce' ),
		);

		if ( isset( $saved[ $status ] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( $saved[ $status ] )
			);

			return;
		}

		$detail = isset( $_GET['oyster_detail'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['oyster_detail'] ) ) : '';

		$messages = array(
			'invalid_mode'   => __( 'That pricing option is not one this store offers.', 'oyster-woocommerce' ),
			'value_required' => __( 'Enter an amount for the pricing option you chose.', 'oyster-woocommerce' ),
			'pack_size_invalid'     => __( 'A pack holds at least two scans; one scan is just a scan.', 'oyster-woocommerce' ),
			'pack_validity_invalid' => __( 'Enter how many days a pack stays usable, or leave it empty for the default.', 'oyster-woocommerce' ),
			'not_connected'  => __( 'Connect your store to Oyster before setting up scan payments.', 'oyster-woocommerce' ),
			'failed'         => '' !== $detail
				? $detail
				: __( 'Oyster did not accept that price. Check the amount and try again.', 'oyster-woocommerce' ),
		);

		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html( $messages[ $status ] ?? $messages['failed'] )
		);
	}

	private function redirect_back( string $status, string $detail = '' ): never {
		$args = array(
			'page'           => Menu::PRICING_SLUG,
			'oyster_pricing' => $status,
		);

		if ( '' !== $detail ) {
			$args['oyster_detail'] = $detail;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
