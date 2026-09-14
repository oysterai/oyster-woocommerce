<?php
/**
 * Layout for the scan page.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Gets the scan page out of the column a classic theme wraps a page in.
 *
 * A classic theme renders a page inside its blog layout: a narrow content
 * well with the sidebar beside it, so a landing page arrives with a search
 * box, recent posts and an archive list next to the scan. This registers a
 * page template that keeps the theme's header and footer and drops the rest,
 * and the scan page is created with that template already selected.
 *
 * Block themes are left alone. They lay pages out themselves, have no sidebar
 * to shed, and a PHP template calling get_header() in one would fall through
 * to WordPress' bare theme-compat header instead of the theme's own.
 *
 * The template is a normal entry in Page Attributes either way, so a merchant
 * whose theme does something unusual can switch the page back to the theme's
 * own template without touching anything else.
 */
final class Scan_Page_Template {

	public const SLUG = 'oyster-scan-page.php';

	private const STYLE_HANDLE = 'oyster-woo-scan-page';

	public function __construct( private Scan_Page $scan_page ) {}

	public function register(): void {
		add_filter( 'theme_page_templates', array( $this, 'offer_template' ) );
		add_filter( 'template_include', array( $this, 'use_template' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_style' ) );
		add_filter( 'render_block', array( $this, 'hide_duplicate_title' ), 10, 2 );
	}

	/**
	 * A block theme's page template prints the title itself, and this page's
	 * content opens with that same headline styled as the hero. Only one of
	 * them can stay, and the one in the content is the one the merchant can
	 * edit, colour and move.
	 *
	 * Scoped to this page and to the title of this page: a query loop on it
	 * listing other posts is rendering their titles, not this one's.
	 *
	 * @param array<string, mixed> $block
	 */
	public function hide_duplicate_title( string $html, array $block ): string {
		if ( 'core/post-title' !== ( $block['blockName'] ?? '' ) ) {
			return $html;
		}

		$id = $this->scan_page->id();

		if ( 0 === $id || ! is_page( $id ) || get_the_ID() !== $id ) {
			return $html;
		}

		return '';
	}

	/**
	 * Whether this theme is one the template belongs in at all.
	 */
	public static function suits_active_theme(): bool {
		return ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme();
	}

	/**
	 * @param array<string, string> $templates
	 * @return array<string, string>
	 */
	public function offer_template( array $templates ): array {
		if ( self::suits_active_theme() ) {
			$templates[ self::SLUG ] = __( 'Oyster scan page', 'oyster-woocommerce' );
		}

		return $templates;
	}

	public function use_template( string $template ): string {
		if ( ! $this->is_scan_page() || ! self::suits_active_theme() ) {
			return $template;
		}

		if ( self::SLUG !== get_page_template_slug( get_queried_object_id() ) ) {
			return $template;
		}

		$ours = OYSTER_WOO_PATH . 'templates/scan-page.php';

		return is_readable( $ours ) ? $ours : $template;
	}

	/**
	 * Loaded on the scan page whatever template is rendering it: the design
	 * lives on the blocks, which a block theme renders without this template
	 * and a merchant keeps if they switch back to their theme's own.
	 */
	public function enqueue_style(): void {
		if ( ! $this->is_scan_page() ) {
			return;
		}

		wp_enqueue_style(
			self::STYLE_HANDLE,
			OYSTER_WOO_URL . 'assets/css/scan-page.css',
			array(),
			OYSTER_WOO_VERSION
		);

		// Every tint, disc and button on the page is mixed from this one value,
		// so the page takes the merchant's colour rather than the plugin's.
		wp_add_inline_style(
			self::STYLE_HANDLE,
			'.oyster-scan-section{--oyster-accent:' . esc_attr( Scan_Page_Content::accent() ) . ';}'
		);
	}

	private function is_scan_page(): bool {
		$id = $this->scan_page->id();

		return $id > 0 && is_page( $id );
	}
}
