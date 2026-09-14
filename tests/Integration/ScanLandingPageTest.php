<?php

declare( strict_types=1 );

namespace Oyster\Woo\Tests\Integration;

use Oyster\Woo\Frontend\Scan_Page;
use Oyster\Woo\Frontend\Scan_Page_Content;
use Oyster\Woo\Frontend\Scan_Page_Template;
use Oyster\Woo\Support\Connection;
use WP_UnitTestCase;

/**
 * The landing page the scan arrives inside: that its markup is really blocks
 * the editor will accept, that the calls to action reach the scan, and that the
 * layout it asks for is one the active theme can give it.
 */
final class ScanLandingPageTest extends WP_UnitTestCase {

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
		foreach ( parse_blocks( Scan_Page_Content::blocks() ) as $block ) {
			if ( null === $block['blockName'] ) {
				$this->assertSame( '', trim( (string) $block['innerHTML'] ), 'stray markup between blocks' );
			}
		}
	}

	public function test_the_page_is_four_sections(): void {
		$top_level = array_values(
			array_filter(
				parse_blocks( Scan_Page_Content::blocks() ),
				static fn( $block ) => null !== $block['blockName']
			)
		);

		$this->assertSame(
			array( 'core/group', 'core/group', 'core/group', 'core/group' ),
			wp_list_pluck( $top_level, 'blockName' )
		);
	}

	public function test_it_is_built_from_blocks_a_merchant_can_edit(): void {
		$names = $this->block_names( Scan_Page_Content::blocks() );

		foreach ( array( 'core/heading', 'core/paragraph', 'core/buttons', 'core/columns', 'core/details', 'oyster/skin-scan' ) as $expected ) {
			$this->assertContains( $expected, $names );
		}
	}

	public function test_the_scan_block_appears_exactly_once(): void {
		$names = $this->block_names( Scan_Page_Content::blocks() );

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
		$content = Scan_Page_Content::blocks();

		preg_match_all( '/<a class="wp-block-button__link[^"]*" href="([^"]+)"/', $content, $matches );

		$this->assertNotEmpty( $matches[1], 'the page has no call to action at all' );

		foreach ( $matches[1] as $href ) {
			$this->assertSame( '#' . Scan_Page_Content::WIDGET_ANCHOR, $href );
		}
	}

	public function test_the_scan_carries_the_id_those_links_expect(): void {
		$this->assertStringContainsString(
			'id="' . Scan_Page_Content::WIDGET_ANCHOR . '"',
			Scan_Page_Content::blocks()
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
		$this->assertStringContainsString( 'Three steps to a routine made for you', $rendered );
		$this->assertStringContainsString( '<details', $rendered );
		$this->assertStringContainsString( 'oyster-scan-section', $rendered );
	}

	public function test_the_stylesheet_loads_on_the_scan_page(): void {
		$id = $this->scan_page->create();
		$this->go_to( (string) get_permalink( $id ) );

		$this->template()->enqueue_style();

		$this->assertTrue( wp_style_is( 'oyster-woo-scan-page', 'enqueued' ) );
	}

	public function test_the_stylesheet_stays_off_every_other_page(): void {
		$this->scan_page->create();
		$other = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );

		$this->go_to( (string) get_permalink( $other ) );

		$this->template()->enqueue_style();

		$this->assertFalse( wp_style_is( 'oyster-woo-scan-page', 'enqueued' ) );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Layout
	 * -----------------------------------------------------------------------
	 */

	/**
	 * The page asks for the template only where it makes sense, and what makes
	 * sense is decided by the theme rather than assumed.
	 */
	public function test_the_page_is_created_with_the_layout_its_theme_can_use(): void {
		$id = $this->scan_page->create();

		$expected = Scan_Page_Template::suits_active_theme() ? Scan_Page_Template::SLUG : '';

		$this->assertSame( $expected, get_page_template_slug( $id ) );
	}

	/**
	 * A block theme builds its own header and footer out of blocks. Routing one
	 * through a PHP template would call get_header(), find no header.php, and
	 * fall through to WordPress' bare theme-compat markup instead of the
	 * theme's own chrome.
	 */
	public function test_a_block_theme_is_never_routed_through_the_php_template(): void {
		if ( ! wp_is_block_theme() ) {
			$this->assertTrue( Scan_Page_Template::suits_active_theme() );
			return;
		}

		$id = $this->scan_page->create();
		update_post_meta( $id, '_wp_page_template', Scan_Page_Template::SLUG );
		$this->go_to( (string) get_permalink( $id ) );

		$this->assertSame( 'theme-template.php', $this->template()->use_template( 'theme-template.php' ) );
	}

	public function test_another_page_keeps_its_own_template(): void {
		$this->scan_page->create();
		$other = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );

		$this->go_to( (string) get_permalink( $other ) );

		$this->assertSame( 'theme-template.php', $this->template()->use_template( 'theme-template.php' ) );
	}

	public function test_the_template_is_offered_only_where_it_fits(): void {
		$offered = $this->template()->offer_template( array() );

		$this->assertSame(
			Scan_Page_Template::suits_active_theme(),
			array_key_exists( Scan_Page_Template::SLUG, $offered )
		);
	}

	private function template(): Scan_Page_Template {
		return new Scan_Page_Template( $this->scan_page );
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
