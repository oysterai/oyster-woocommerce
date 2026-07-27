<?php
/**
 * "Get set up with Oyster" checklist, shown on the Connection screen.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Admin;

use Oyster\Woo\Support\Connection;
use Oyster\Woo\Support\Onboarding;
use Oyster\Woo\Support\Widget_Settings;
use Oyster\Woo\Sync\Catalog_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Mirrors the "Get set up with Oyster" checklist Oyster's Shopify
 * integration shows on first install — a short, dismissible guide so a
 * merchant knows what's left to do rather than discovering it by trial and
 * error. Steps are derived from real state where possible (synced, float
 * launcher on); a couple have no objective "done" signal and are tracked as
 * manual acknowledgements instead (Support\Onboarding).
 */
final class Setup_Guide {

	public function __construct(
		private Connection $connection,
		private Catalog_Sync $sync
	) {}

	public function register(): void {
		add_action( 'admin_post_oyster_woo_onboarding_mark', array( $this, 'handle_mark' ) );
		add_action( 'admin_post_oyster_woo_onboarding_dismiss', array( $this, 'handle_dismiss' ) );
	}

	public function handle_mark(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'oyster-woocommerce' ) );
		}
		check_admin_referer( 'oyster_woo_onboarding_mark' );

		Onboarding::mark( sanitize_key( wp_unslash( $_POST['step'] ?? '' ) ) );

		wp_safe_redirect( admin_url( 'admin.php?page=' . Menu::PARENT_SLUG ) );
		exit;
	}

	public function handle_dismiss(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'oyster-woocommerce' ) );
		}
		check_admin_referer( 'oyster_woo_onboarding_dismiss' );

		Onboarding::dismiss();

		wp_safe_redirect( admin_url( 'admin.php?page=' . Menu::PARENT_SLUG ) );
		exit;
	}

	/**
	 * Only meaningful once connected — the caller (Connect_Screen) only
	 * renders this from render_connected().
	 */
	public function render(): void {
		$onboarding = Onboarding::get();
		if ( $onboarding['dismissed'] ) {
			return;
		}

		$steps      = $this->steps( $onboarding );
		$done_count = count( array_filter( $steps, static fn( $step ) => $step['done'] ) );
		$total      = count( $steps );
		$all_done   = $done_count === $total;

		printf(
			'<details class="oyster-card" style="max-width:640px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:0;margin-top:16px;overflow:hidden;" %s>',
			$all_done ? '' : 'open'
		);

		printf(
			'<summary style="cursor:pointer;padding:20px 24px;font-weight:600;font-size:15px;list-style:none;">%s <span style="font-weight:400;color:#787c82;">(%d/%d)</span></summary>',
			$all_done ? esc_html__( "You're all set 🎉", 'oyster-woocommerce' ) : esc_html__( 'Get set up with Oyster', 'oyster-woocommerce' ),
			$done_count,
			$total
		);

		echo '<div style="padding:0 24px 20px;">';
		foreach ( $steps as $i => $step ) {
			$this->render_step( $i + 1, $step );
		}

		if ( $all_done ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px;">';
			echo '<input type="hidden" name="action" value="oyster_woo_onboarding_dismiss">';
			wp_nonce_field( 'oyster_woo_onboarding_dismiss' );
			printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Dismiss this guide', 'oyster-woocommerce' ) );
			echo '</form>';
		}
		echo '</div></details>';
	}

	/**
	 * @param array<string, mixed> $step
	 */
	private function render_step( int $n, array $step ): void {
		echo '<div style="display:flex;gap:12px;align-items:flex-start;padding:12px 0;border-top:1px solid #f0f0f1;">';

		printf(
			'<span style="flex-shrink:0;width:24px;height:24px;border-radius:50%%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;%s">%s</span>',
			$step['done'] ? 'background:#00a32a;color:#fff;' : 'background:#f0f0f1;color:#50575e;',
			$step['done'] ? '&#10003;' : (string) $n
		);

		echo '<div style="flex:1;">';
		printf( '<p style="margin:0;font-weight:600;">%s</p>', esc_html( $step['title'] ) );
		printf( '<p class="description" style="margin:2px 0 8px;">%s</p>', esc_html( $step['description'] ) );

		if ( ! $step['done'] ) {
			printf(
				'<a class="button button-small" href="%s">%s</a>',
				esc_url( $step['cta_url'] ),
				esc_html( $step['cta_label'] )
			);

			if ( isset( $step['mark_key'] ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
				echo '<input type="hidden" name="action" value="oyster_woo_onboarding_mark">';
				printf( '<input type="hidden" name="step" value="%s">', esc_attr( $step['mark_key'] ) );
				wp_nonce_field( 'oyster_woo_onboarding_mark' );
				printf( '<button type="submit" class="button-link" style="margin-left:12px;">%s</button>', esc_html__( 'Mark as done', 'oyster-woocommerce' ) );
				echo '</form>';
			}
		}

		echo '</div></div>';
	}

	/**
	 * @param array{widget_customized:bool, widget_added:bool, first_scan_confirmed:bool, dismissed:bool} $onboarding
	 * @return array<int, array<string, mixed>>
	 */
	private function steps( array $onboarding ): array {
		$widget_settings      = Widget_Settings::get();
		$status               = $this->sync->status();
		$synced               = ! empty( $status['last_synced_at'] );
		$widget_on_storefront = ! empty( $widget_settings['float_enabled'] ) || $onboarding['widget_added'];

		return array(
			array(
				'title'       => __( 'Connect your store', 'oyster-woocommerce' ),
				'description' => __( 'Your WooCommerce store is connected to your Oyster vendor account.', 'oyster-woocommerce' ),
				'done'        => true,
			),
			array(
				'title'       => __( 'Choose what syncs, then run your first import', 'oyster-woocommerce' ),
				'description' => __( 'By default nothing syncs — pick a category/tag scope (or "sync all") on the Catalog screen, then run a sync. We never auto-sync your whole store.', 'oyster-woocommerce' ),
				'done'        => $synced,
				'cta_label'   => __( 'Go to Catalog', 'oyster-woocommerce' ),
				'cta_url'     => admin_url( 'admin.php?page=' . Menu::CATALOG_SLUG ),
			),
			array(
				'title'       => __( 'Customize your widget', 'oyster-woocommerce' ),
				'description' => __( "Tailor the launcher's color, intro message and logo to your brand.", 'oyster-woocommerce' ),
				'done'        => $onboarding['widget_customized'],
				'cta_label'   => __( 'Open widget settings', 'oyster-woocommerce' ),
				'cta_url'     => admin_url( 'admin.php?page=' . Menu::WIDGET_SLUG ),
				'mark_key'    => 'widget_customized',
			),
			array(
				'title'       => __( 'Add the widget to your storefront', 'oyster-woocommerce' ),
				'description' => __( 'The floating launcher is on by default; add the [oyster_scan] shortcode or the "Oyster Skin Scan" block instead if you want it placed inline.', 'oyster-woocommerce' ),
				'done'        => $widget_on_storefront,
				'cta_label'   => __( 'Open widget settings', 'oyster-woocommerce' ),
				'cta_url'     => admin_url( 'admin.php?page=' . Menu::WIDGET_SLUG ),
				'mark_key'    => 'widget_added',
			),
			array(
				'title'       => __( 'Get your first scan', 'oyster-woocommerce' ),
				'description' => __( 'Open your storefront and run a face scan to confirm everything works end to end.', 'oyster-woocommerce' ),
				'done'        => $onboarding['first_scan_confirmed'],
				'cta_label'   => __( 'Open your storefront', 'oyster-woocommerce' ),
				'cta_url'     => home_url( '/' ),
				'mark_key'    => 'first_scan_confirmed',
			),
		);
	}
}
