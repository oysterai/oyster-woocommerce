<?php
/**
 * "Catalog" admin screen — triggers and reports on catalog sync.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Admin;

use Oyster\Woo\Support\Connection;
use Oyster\Woo\Support\Dashboard_Link;
use Oyster\Woo\Sync\Catalog_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only status view + a single "Sync now" action. Incremental sync
 * already runs automatically off WooCommerce product hooks (Product_Hooks);
 * this screen exists for the initial full import and for a merchant to force
 * a re-sync (e.g. after bulk-editing products with a tool that doesn't fire
 * the usual hooks, such as some CSV importers).
 */
final class Catalog_Screen {

	private const NOTICE_TRANSIENT = 'oyster_woo_catalog_notice_';

	public function __construct(
		private Connection $connection,
		private Catalog_Sync $sync
	) {}

	public function register(): void {
		add_action( 'admin_post_oyster_woo_sync_catalog', array( $this, 'handle_sync_now' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	public function handle_sync_now(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'oyster-woocommerce' ) );
		}
		check_admin_referer( 'oyster_woo_sync_catalog' );

		$this->sync->enqueue_full_import();

		set_transient( self::NOTICE_TRANSIENT . get_current_user_id(), 'started', 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . Menu::CATALOG_SLUG ) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this integration.', 'oyster-woocommerce' ) );
		}

		echo '<div class="wrap oyster-woo">';
		printf( '<h1>%s</h1>', esc_html__( 'Oyster catalog sync', 'oyster-woocommerce' ) );

		if ( ! $this->connection->is_connected() ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div></div>',
				esc_html__( 'Connect your store to Oyster before syncing your catalog.', 'oyster-woocommerce' ),
				esc_url( admin_url( 'admin.php?page=' . Menu::PARENT_SLUG ) ),
				esc_html__( 'Go to Connection', 'oyster-woocommerce' )
			);
			return;
		}

		echo '<p>';
		Dashboard_Link::render_button();
		echo '</p>';

		printf(
			'<p style="max-width:640px;">%s</p>',
			esc_html__( 'Published simple and variable products sync to Oyster automatically whenever you save them. Use "Sync now" for the first import, or to force a full re-sync.', 'oyster-woocommerce' )
		);

		$this->render_status();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:16px;">';
		echo '<input type="hidden" name="action" value="oyster_woo_sync_catalog">';
		wp_nonce_field( 'oyster_woo_sync_catalog' );
		$running = ! empty( $this->sync->status()['full_import_running'] );
		printf(
			'<button type="submit" class="button button-primary" %s>%s</button>',
			disabled( $running, true, false ),
			esc_html( $running ? __( 'Sync in progress…', 'oyster-woocommerce' ) : __( 'Sync now', 'oyster-woocommerce' ) )
		);
		echo '</form>';

		echo '</div>';
	}

	private function render_status(): void {
		$status = $this->sync->status();

		echo '<div class="oyster-card" style="max-width:640px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:24px;margin-top:16px;">';

		if ( ! empty( $status['full_import_running'] ) ) {
			printf(
				'<p><span style="display:inline-block;width:10px;height:10px;border-radius:50%%;background:#dba617;margin-right:8px;"></span>%s</p>',
				esc_html__( 'Full catalog import running in the background — this page will reflect progress on refresh.', 'oyster-woocommerce' )
			);
			$totals = is_array( $status['full_import_totals'] ?? null ) ? $status['full_import_totals'] : array();
			$this->render_totals( $totals );
		} elseif ( isset( $status['last_synced_at'] ) ) {
			printf(
				'<p><span style="display:inline-block;width:10px;height:10px;border-radius:50%%;background:#00a32a;margin-right:8px;"></span>%s %s</p>',
				esc_html__( 'Last synced', 'oyster-woocommerce' ),
				esc_html( human_time_diff( (int) $status['last_synced_at'] ) . ' ' . __( 'ago', 'oyster-woocommerce' ) )
			);
			$totals = is_array( $status['full_import_totals'] ?? null ) ? $status['full_import_totals'] : ( is_array( $status['last_result'] ?? null ) ? $status['last_result'] : array() );
			$this->render_totals( $totals );
		} else {
			echo '<p>' . esc_html__( 'Not synced yet.', 'oyster-woocommerce' ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $totals
	 */
	private function render_totals( array $totals ): void {
		if ( ! $totals ) {
			return;
		}

		echo '<p class="description" style="display:flex;gap:16px;">';
		foreach ( array(
			'created' => __( 'created', 'oyster-woocommerce' ),
			'updated' => __( 'updated', 'oyster-woocommerce' ),
			'claimed' => __( 'claimed', 'oyster-woocommerce' ),
			'failed'  => __( 'failed', 'oyster-woocommerce' ),
		) as $key => $label ) {
			printf( '<span><strong>%d</strong> %s</span>', (int) ( $totals[ $key ] ?? 0 ), esc_html( $label ) );
		}
		echo '</p>';
	}

	public function render_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, Menu::CATALOG_SLUG ) ) {
			return;
		}

		$key = self::NOTICE_TRANSIENT . get_current_user_id();
		if ( ! get_transient( $key ) ) {
			return;
		}
		delete_transient( $key );

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Catalog sync started. This may take a few minutes for a large catalog.', 'oyster-woocommerce' )
		);
	}
}
