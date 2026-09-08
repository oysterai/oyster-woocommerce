<?php
/**
 * "Scan payments" admin screen — how a shopper may pay for a skin scan here.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Admin;

use Oyster\Woo\Support\Connection;
use Oyster\Woo\Support\Dashboard_Link;
use Oyster\Woo\Support\Scan_Payment_Methods;
use Oyster\Woo\Support\Scan_Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Which of the store's payment methods a scan may be paid with.
 *
 * ## Why there is no price on this screen
 *
 * What a shopper pays is set on the Oyster dashboard, and nowhere else. It used to be
 * editable here as well, over the API. That made this plugin a second face on a pricing
 * system it does not own: every option Oyster added had to be mirrored into a form here
 * and shipped in a release before a merchant could use it, and until they updated, this
 * screen quietly described a pricing model that had moved on.
 *
 * The store still READS the price — the checkout has to know what to charge — but it is
 * no longer something wp-admin presents or edits.
 *
 * ## What does belong here
 *
 * Which payment methods a scan may use is a fact about this store's checkout that Oyster
 * has no view of, so it is an option in this database and a control on this screen.
 */
final class Scan_Payments_Screen {

	private const ACTION_METHODS = 'oyster_woo_save_scan_methods';

	private const NONCE_METHODS = 'oyster_woo_scan_methods';

	public function __construct(
		private Connection $connection,
		private Scan_Pricing $pricing
	) {}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_METHODS, array( $this, 'handle_save_methods' ) );
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

		// Read only to answer "does this store's checkout take scan payments at all".
		// The cached copy is enough: nothing on this screen shows a figure, so a price
		// changed a minute ago on the dashboard cannot be stale here.
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

		$this->render_pricing_links();
		$this->render_payment_methods();

		echo '</div>';
	}

	/**
	 * Shown when Oyster could not be reached.
	 *
	 * Two different problems wear the same symptom. Oyster being briefly
	 * unreachable is fixed by reloading; a credential Oyster refuses is not, and
	 * telling a merchant to reload sends them round a loop that cannot end.
	 */
	private function render_unreadable(): void {
		if ( ! $this->pricing->access_was_denied() ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Could not reach Oyster just now. Reload to try again.', 'oyster-woocommerce' )
			);

			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p><p><a href="%s" class="button">%s</a></p></div>',
			esc_html__( 'Your store\'s connection to Oyster is not permitted to read your scan setup. Reconnecting your store renews it, and nothing else about your setup changes.', 'oyster-woocommerce' ),
			esc_url( admin_url( 'admin.php?page=' . Menu::PARENT_SLUG ) ),
			esc_html__( 'Reconnect your store', 'oyster-woocommerce' )
		);
	}

	/**
	 * Shown when Oyster, not this store, takes the shopper's payment. Nothing on this
	 * screen applies: the shopper pays Oyster directly and never reaches this checkout.
	 */
	private function render_not_applicable(): void {
		printf(
			'<div class="notice notice-info inline"><p>%s</p></div>',
			esc_html__( 'Oyster collects scan payments for your store, so shoppers never pay for a scan through this checkout.', 'oyster-woocommerce' )
		);

		$this->render_dashboard_links();
	}

	/**
	 * Pricing is set on the dashboard. This says so and points at it.
	 */
	private function render_pricing_links(): void {
		printf( '<h2>%s</h2>', esc_html__( 'Pricing', 'oyster-woocommerce' ) );

		echo '<p class="description" style="max-width:40em">';
		esc_html_e( 'What shoppers pay for a scan, and any scan pack you sell, are set on your Oyster dashboard.', 'oyster-woocommerce' );
		echo '</p>';

		$this->render_dashboard_links();
	}

	private function render_dashboard_links(): void {
		printf(
			'<p><a href="%s" class="button" target="_blank" rel="noopener noreferrer">%s</a> <a href="%s" class="button" target="_blank" rel="noopener noreferrer">%s</a></p>',
			esc_url( Dashboard_Link::pricing_url() ),
			esc_html__( 'Set your scan pricing', 'oyster-woocommerce' ),
			esc_url( Dashboard_Link::billing_url() ),
			esc_html__( 'View billing and usage', 'oyster-woocommerce' )
		);
	}

	private function render_payment_methods(): void {
		printf( '<h2>%s</h2>', esc_html__( 'Payment methods', 'oyster-woocommerce' ) );

		echo '<p class="description" style="max-width:40em">';
		esc_html_e( 'Choose what a shopper may pay for a scan with. Tick nothing to offer every enabled method.', 'oyster-woocommerce' );
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

		if ( 'methods_saved' === $status ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Payment methods updated. Shoppers see the new choice the next time one pays for a scan.', 'oyster-woocommerce' )
			);

			return;
		}

		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html__( 'That change was not saved. Try again.', 'oyster-woocommerce' )
		);
	}

	private function redirect_back( string $status ): never {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => Menu::PRICING_SLUG,
					'oyster_pricing' => $status,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
