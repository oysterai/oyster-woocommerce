<?php
/**
 * "Scan pricing" admin screen — what shoppers pay for a skin scan.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Admin;

use Oyster\Woo\Api\Api_Exception;
use Oyster\Woo\Support\Connection;
use Oyster\Woo\Support\Dashboard_Link;
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
 */
final class Scan_Pricing_Screen {

	private const ACTION = 'oyster_woo_save_scan_pricing';

	private const NONCE = 'oyster_woo_scan_pricing';

	public function __construct(
		private Connection $connection,
		private Scan_Pricing $pricing
	) {}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_save' ) );
	}

	public function handle_save(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this integration.', 'oyster-woocommerce' ) );
		}

		check_admin_referer( self::NONCE );

		$mode = sanitize_text_field( (string) ( $_POST['mode'] ?? '' ) );

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

	public function render(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this integration.', 'oyster-woocommerce' ) );
		}

		echo '<div class="wrap oyster-woo">';
		printf( '<h1>%s</h1>', esc_html__( 'Scan pricing', 'oyster-woocommerce' ) );

		if ( ! $this->connection->is_connected() ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Connect your store to Oyster before setting scan pricing.', 'oyster-woocommerce' ),
				esc_url( admin_url( 'admin.php?page=' . Menu::PARENT_SLUG ) ),
				esc_html__( 'Go to Connection', 'oyster-woocommerce' )
			);
			echo '</div>';

			return;
		}

		$this->render_notice();

		$pricing = $this->pricing->current();

		if ( null === $pricing ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Could not read your scan pricing from Oyster just now. Reload to try again.', 'oyster-woocommerce' )
			);
			echo '</div>';

			return;
		}

		if ( empty( $pricing['collects_externally'] ) ) {
			$this->render_not_applicable();
			echo '</div>';

			return;
		}

		$this->render_summary( $pricing );
		$this->render_form( $pricing );

		echo '</div>';
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

	private function render_notice(): void {
		$status = isset( $_GET['oyster_pricing'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['oyster_pricing'] ) ) : '';

		if ( '' === $status ) {
			return;
		}

		if ( 'saved' === $status ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Scan pricing updated. Shoppers are quoted the new price from their next scan.', 'oyster-woocommerce' )
			);

			return;
		}

		$detail = isset( $_GET['oyster_detail'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['oyster_detail'] ) ) : '';

		$messages = array(
			'invalid_mode'   => __( 'That pricing option is not one this store offers.', 'oyster-woocommerce' ),
			'value_required' => __( 'Enter an amount for the pricing option you chose.', 'oyster-woocommerce' ),
			'not_connected'  => __( 'Connect your store to Oyster before setting scan pricing.', 'oyster-woocommerce' ),
			'failed'         => '' !== $detail
				? $detail
				: __( 'Oyster did not accept that price. Check the amount and try again.', 'oyster-woocommerce' ),
		);

		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html( $messages[ $status ] ?? $messages['failed'] )
		);
	}

	private function redirect_back( string $status, string $detail = '' ): void {
		$args = array( 'page' => Menu::PRICING_SLUG, 'oyster_pricing' => $status );

		if ( '' !== $detail ) {
			$args['oyster_detail'] = $detail;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
