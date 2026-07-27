<?php
/**
 * "Catalog" admin screen — triggers and reports on catalog sync.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Admin;

use Oyster\Woo\Support\Catalog_Filter;
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

	private const FILTER_GROUP = 'oyster_woo_catalog_filter';

	private const FILTER_SECTION = 'oyster_woo_catalog_filter_section';

	public function __construct(
		private Connection $connection,
		private Catalog_Sync $sync
	) {}

	public function register(): void {
		add_action( 'admin_post_oyster_woo_sync_catalog', array( $this, 'handle_sync_now' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'admin_init', array( $this, 'register_filter_settings' ) );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Sync scope (Settings API — saved via options.php, mirrors Widget_Settings_Screen)
	 * -----------------------------------------------------------------------
	 */

	public function register_filter_settings(): void {
		register_setting(
			self::FILTER_GROUP,
			Catalog_Filter::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Catalog_Filter::class, 'sanitize' ),
				'default'           => Catalog_Filter::defaults(),
			)
		);

		add_settings_section(
			self::FILTER_SECTION,
			__( 'What syncs to Oyster', 'oyster-woocommerce' ),
			function (): void {
				echo '<p class="description">' . esc_html__( 'By default every published product syncs. If your store sells more than what Oyster should recommend, scope sync to specific categories or tags — a product matches if it has ANY of the selected terms, from either taxonomy.', 'oyster-woocommerce' ) . '</p>';
			},
			self::FILTER_GROUP
		);

		add_settings_field( 'oyster_woo_catalog_filter_mode', __( 'Sync scope', 'oyster-woocommerce' ), array( $this, 'field_mode' ), self::FILTER_GROUP, self::FILTER_SECTION );
		add_settings_field( 'oyster_woo_catalog_filter_categories', __( 'Categories', 'oyster-woocommerce' ), array( $this, 'field_categories' ), self::FILTER_GROUP, self::FILTER_SECTION );
		add_settings_field( 'oyster_woo_catalog_filter_tags', __( 'Tags', 'oyster-woocommerce' ), array( $this, 'field_tags' ), self::FILTER_GROUP, self::FILTER_SECTION );
	}

	public function field_mode(): void {
		$mode    = Catalog_Filter::get()['mode'];
		$options = array(
			Catalog_Filter::MODE_ALL   => __( 'Sync all published products', 'oyster-woocommerce' ),
			Catalog_Filter::MODE_ALLOW => __( 'Only sync selected categories/tags below', 'oyster-woocommerce' ),
			Catalog_Filter::MODE_DENY  => __( 'Sync all products except selected categories/tags below', 'oyster-woocommerce' ),
		);

		foreach ( $options as $value => $label ) {
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="radio" name="%s[mode]" value="%s" %s> %s</label>',
				esc_attr( Catalog_Filter::OPTION_KEY ),
				esc_attr( $value ),
				checked( $mode, $value, false ),
				esc_html( $label )
			);
		}
	}

	public function field_categories(): void {
		$this->term_checkboxes( 'category_ids', 'product_cat' );
	}

	public function field_tags(): void {
		$this->term_checkboxes( 'tag_ids', 'product_tag' );
	}

	private function term_checkboxes( string $key, string $taxonomy ): void {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || ! $terms ) {
			echo '<p class="description">' . esc_html__( 'None found.', 'oyster-woocommerce' ) . '</p>';
			return;
		}

		$selected = array_map( 'absint', (array) Catalog_Filter::get()[ $key ] );

		echo '<div style="max-height:200px;overflow-y:auto;border:1px solid #dcdcde;border-radius:4px;padding:8px;max-width:400px;">';
		foreach ( $terms as $term ) {
			printf(
				'<label style="display:block;margin-bottom:2px;"><input type="checkbox" name="%s[%s][]" value="%d" %s> %s</label>',
				esc_attr( Catalog_Filter::OPTION_KEY ),
				esc_attr( $key ),
				(int) $term->term_id,
				checked( in_array( (int) $term->term_id, $selected, true ), true, false ),
				esc_html( $term->name )
			);
		}
		echo '</div>';
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
			esc_html__( 'Products sync to Oyster automatically whenever you save them, scoped to whatever you choose below. Use "Sync now" for the first import, or to force a full re-sync.', 'oyster-woocommerce' )
		);

		$this->render_scope_notice();

		settings_errors( Catalog_Filter::OPTION_KEY );

		echo '<form method="post" action="options.php">';
		settings_fields( self::FILTER_GROUP );
		do_settings_sections( self::FILTER_GROUP );
		submit_button( __( 'Save sync scope', 'oyster-woocommerce' ) );
		echo '</form>';

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

	/**
	 * Shown whenever the *current, live* scope means nothing is eligible to
	 * sync — not just right after a settings save, so a vendor who lands here
	 * later still sees why their catalog isn't syncing. Deliberately not an
	 * error: an empty allow-list is the safe default, not a mistake.
	 */
	private function render_scope_notice(): void {
		$settings = Catalog_Filter::get();
		if ( Catalog_Filter::MODE_ALLOW !== $settings['mode'] || $settings['category_ids'] || $settings['tag_ids'] ) {
			return;
		}

		printf(
			'<div class="notice notice-warning inline"><p>%s</p></div>',
			esc_html__( 'No categories or tags are selected below, so nothing syncs yet. Pick at least one, or choose "Sync all published products" if that\'s intentional.', 'oyster-woocommerce' )
		);
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
