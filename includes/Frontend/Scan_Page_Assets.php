<?php
/**
 * Styling for the scan page.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the scan page's stylesheet, and the one colour every part of its design
 * is mixed from.
 *
 * This used to also register a page template that called get_header(), printed
 * the content full width, and called get_footer(), so a classic theme could not
 * wrap the page in its blog column. That cannot be done safely. A theme's
 * get_header() may leave a container open that expects particular children:
 * one commercial theme opens a CSS grid for its content-plus-sidebar layout, so
 * a template dropping its own element into it put the whole page in a 90px
 * grid track. A plugin cannot know what a theme has opened, and there is no
 * hook that tells it.
 *
 * The page therefore renders through the theme's own page template, like any
 * other page, and the design works inside whatever container that gives it.
 */
final class Scan_Page_Assets {

	/** The template this plugin used to assign, kept only to clean it off. */
	private const RETIRED_TEMPLATE = 'oyster-scan-page.php';

	private const STYLE_HANDLE = 'oyster-woo-scan-page';

	public function __construct( private Scan_Page $scan_page ) {}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_style' ) );
		add_action( 'admin_init', array( $this, 'forget_retired_template' ) );
	}

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

	/**
	 * A page created by an earlier version still names the retired template in
	 * its meta. WordPress falls back to the theme's page template on its own
	 * when the file is gone, so this is not what fixes the layout; it clears the
	 * stale value so Page Attributes reads "Default template" and says what is
	 * actually happening.
	 */
	public function forget_retired_template(): void {
		$id = $this->scan_page->id();

		if ( 0 === $id || self::RETIRED_TEMPLATE !== get_page_template_slug( $id ) ) {
			return;
		}

		delete_post_meta( $id, '_wp_page_template' );
	}

	private function is_scan_page(): bool {
		$id = $this->scan_page->id();

		return $id > 0 && is_page( $id );
	}
}
