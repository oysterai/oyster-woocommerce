<?php

declare( strict_types=1 );

namespace Oyster\Woo\Tests\Integration;

use Oyster\Woo\Frontend\Scan_Page;
use Oyster\Woo\Frontend\Scan_Page_Content;
use Oyster\Woo\Frontend\Scan_Page_Assets;
use Oyster\Woo\Support\Connection;
use WP_UnitTestCase;

/**
 * The landing page the scan arrives inside: that its markup is really blocks
 * the editor will accept, that the calls to action reach the scan, and that the
 * layout it asks for is one the active theme can give it.
 */
final class ScanLandingPageTest extends WP_UnitTestCase {

	private const ACCENT = '#2f6b5e';

	private Scan_Page $scan_page;

	public function set_up(): void {
		parent::set_up();

		( new Connection() )->save(
			array(
				'bearer'     => 'test-token',
				'vendor_id'  => 1,
				'public_key' => 'pk_test',
			)
		);

		$this->scan_page = new Scan_Page();

		// WordPress' style registry is a global that outlives a single test, so
		// a handle enqueued by the last one is still enqueued for this one.
		$GLOBALS['wp_styles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/*
	 * -----------------------------------------------------------------------
	 * The markup is blocks, not a wall of HTML
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Content the parser cannot account for is content the editor flags as
	 * unexpected the first time the merchant opens the page, so every byte has
	 * to belong to a block.
	 */
	public function test_nothing_sits_outside_a_block(): void {
		foreach ( parse_blocks( Scan_Page_Content::blocks( self::ACCENT ) ) as $block ) {
			if ( null === $block['blockName'] ) {
				$this->assertSame( '', trim( (string) $block['innerHTML'] ), 'stray markup between blocks' );
			}
		}
	}

	public function test_every_section_is_a_full_width_band(): void {
		$top_level = array_values(
			array_filter(
				parse_blocks( Scan_Page_Content::blocks( self::ACCENT ) ),
				static fn( $block ) => null !== $block['blockName']
			)
		);

		$this->assertCount( 6, $top_level );

		foreach ( $top_level as $section ) {
			$this->assertSame( 'core/group', $section['blockName'] );
			$this->assertSame( 'full', $section['attrs']['align'] ?? '', 'a section that is not full width leaves the page a narrow strip' );
		}
	}

	/**
	 * Every theme prints the page's own title above the content, so the hero
	 * line is a heading under it. An h1 here would give the page two.
	 */
	public function test_the_content_never_claims_the_page_heading(): void {
		$this->assertSame( 0, substr_count( Scan_Page_Content::blocks( self::ACCENT ), '<h1' ) );
	}

	public function test_the_headline_is_set_in_the_merchants_colour(): void {
		$this->assertStringContainsString( 'color:' . self::ACCENT, Scan_Page_Content::blocks( self::ACCENT ) );
	}

	public function test_it_is_built_from_blocks_a_merchant_can_edit(): void {
		$names = $this->block_names( Scan_Page_Content::blocks( self::ACCENT ) );

		foreach ( array( 'core/heading', 'core/paragraph', 'core/buttons', 'core/columns', 'core/details', 'oyster/skin-scan' ) as $expected ) {
			$this->assertContains( $expected, $names );
		}
	}

	public function test_the_scan_block_appears_exactly_once(): void {
		$names = $this->block_names( Scan_Page_Content::blocks( self::ACCENT ) );

		$this->assertSame( 1, count( array_keys( $names, 'oyster/skin-scan', true ) ) );
	}

	/*
	 * -----------------------------------------------------------------------
	 * The calls to action reach the scan
	 * -----------------------------------------------------------------------
	 */

	/**
	 * The buttons sell the scan from the top of the page, so a broken anchor
	 * leaves a visitor pressing a button that does nothing.
	 */
	public function test_every_call_to_action_points_at_the_scan(): void {
		$content = Scan_Page_Content::blocks( self::ACCENT );

		preg_match_all( '/<a class="wp-block-button__link[^"]*" href="([^"]+)"/', $content, $matches );

		$this->assertNotEmpty( $matches[1], 'the page has no call to action at all' );

		foreach ( $matches[1] as $href ) {
			$this->assertSame( '#' . Scan_Page_Content::WIDGET_ANCHOR, $href );
		}
	}

	public function test_the_scan_carries_the_id_those_links_expect(): void {
		$this->assertStringContainsString(
			'id="' . Scan_Page_Content::WIDGET_ANCHOR . '"',
			Scan_Page_Content::blocks( self::ACCENT )
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * What a visitor is served
	 * -----------------------------------------------------------------------
	 */

	public function test_the_published_page_renders_the_scan_and_the_copy(): void {
		$id = $this->scan_page->create();

		$rendered = apply_filters( 'the_content', (string) get_post( $id )->post_content );

		$this->assertStringContainsString( 'data-oyster-widget="inline"', $rendered );
		$this->assertStringContainsString( 'How it works', $rendered );
		$this->assertStringContainsString( 'What you will find out', $rendered );
		$this->assertStringContainsString( '<details', $rendered );
		$this->assertStringContainsString( 'oyster-scan-section', $rendered );
	}

	public function test_the_stylesheet_loads_on_the_scan_page(): void {
		$id = $this->scan_page->create();
		$this->go_to( (string) get_permalink( $id ) );

		$this->assets()->enqueue_style();

		$this->assertTrue( wp_style_is( 'oyster-woo-scan-page', 'enqueued' ) );
	}

	public function test_the_stylesheet_stays_off_every_other_page(): void {
		$this->scan_page->create();
		$other = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );

		$this->go_to( (string) get_permalink( $other ) );

		$this->assets()->enqueue_style();

		$this->assertFalse( wp_style_is( 'oyster-woo-scan-page', 'enqueued' ) );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Layout
	 * -----------------------------------------------------------------------
	 */

	private function assets(): Scan_Page_Assets {
		return new Scan_Page_Assets( $this->scan_page );
	}

	/**
	 * A page made by the version that shipped a page template still names it.
	 * WordPress falls back to the theme's own template once the file is gone,
	 * so this is not what fixes the layout; it clears a stale value that would
	 * otherwise have Page Attributes claim a template that does not exist.
	 */
	public function test_the_retired_template_is_cleaned_off_the_page(): void {
		$id = $this->scan_page->create();
		update_post_meta( $id, '_wp_page_template', 'oyster-scan-page.php' );

		$this->assets()->forget_retired_template();

		$this->assertSame( '', get_page_template_slug( $id ) );
	}

	public function test_a_template_the_merchant_chose_is_left_alone(): void {
		$id = $this->scan_page->create();
		update_post_meta( $id, '_wp_page_template', 'full-width.php' );

		$this->assets()->forget_retired_template();

		$this->assertSame( 'full-width.php', get_page_template_slug( $id ) );
	}

	/** Nothing the plugin creates asks for a template of its own any more. */
	public function test_a_new_page_uses_the_themes_own_template(): void {
		$this->assertSame( '', get_page_template_slug( $this->scan_page->create() ) );
	}

	/**
	 * @return array<int, string> Every block name in the tree, nesting included.
	 */
	private function block_names( string $content ): array {
		$names = array();

		$walk = static function ( array $blocks ) use ( &$walk, &$names ): void {
			foreach ( $blocks as $block ) {
				if ( null !== $block['blockName'] ) {
					$names[] = (string) $block['blockName'];
				}
				if ( ! empty( $block['innerBlocks'] ) ) {
					$walk( $block['innerBlocks'] );
				}
			}
		};

		$walk( parse_blocks( $content ) );

		return $names;
	}
}
