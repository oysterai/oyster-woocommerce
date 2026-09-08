<?php

declare( strict_types=1 );

namespace Oyster\Woo\Tests\Integration;

use Oyster\Woo\Frontend\Scan_Page;
use Oyster\Woo\Support\Connection;
use WP_Block_Type_Registry;
use WP_UnitTestCase;

/**
 * The standalone scan page: that creating it produces a working page, and that
 * the page stays as hard to stumble on as a link-only page is supposed to be.
 *
 * Only reachable here. Every claim depends on WordPress actually doing
 * something — publishing a page through the real insert path, running the
 * search query, building a sitemap, appending pages to a nav menu.
 */
final class ScanPageTest extends WP_UnitTestCase {

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

		// The hooks under test are the ones the plugin registered on boot, not
		// this instance's. It exists to reach create() and the page's state.
		$this->scan_page = new Scan_Page();
	}

	public function test_creating_the_page_publishes_one_with_the_scan_block_on_it(): void {
		$id = $this->scan_page->create();

		$this->assertIsInt( $id );
		$this->assertSame( 'page', get_post_type( $id ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertStringContainsString( 'wp:oyster/skin-scan', (string) get_post( $id )->post_content );
		$this->assertSame( $id, $this->scan_page->id() );
	}

	/**
	 * The page is the block, so the storefront anchor has to come out of it —
	 * this is what makes the page a scan surface rather than an empty page.
	 */
	public function test_the_page_renders_a_scan_anchor(): void {
		$this->assertTrue(
			WP_Block_Type_Registry::get_instance()->is_registered( 'oyster/skin-scan' ),
			'precondition: the block the page is made of is registered'
		);

		$id = $this->scan_page->create();

		$rendered = apply_filters( 'the_content', (string) get_post( $id )->post_content );

		$this->assertStringContainsString( 'data-oyster-widget="inline"', $rendered );
	}

	public function test_creating_it_twice_yields_one_page(): void {
		$first  = $this->scan_page->create();
		$second = $this->scan_page->create();

		$this->assertSame( $first, $second );
		$this->assertCount(
			1,
			get_posts(
				array(
					'post_type'   => 'page',
					'title'       => 'Skin analysis',
					'post_status' => 'any',
					'fields'      => 'ids',
					'numberposts' => -1,
				)
			)
		);
	}

	/**
	 * A merchant who deletes the page should be able to ask for another rather
	 * than being told one already exists, pointing at something in the trash.
	 */
	public function test_a_trashed_page_stops_counting_as_the_scan_page(): void {
		$first = $this->scan_page->create();
		wp_trash_post( $first );

		$this->assertSame( 0, $this->scan_page->id() );
		$this->assertFalse( $this->scan_page->exists() );

		$second = $this->scan_page->create();

		$this->assertNotSame( $first, $second );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Staying unlisted
	 * -----------------------------------------------------------------------
	 */

	/**
	 * The whole point of the page. Publishing a top-level page appends it to
	 * every menu with "add new top-level pages automatically" ticked, which
	 * would put a link to it in the site's navigation for every visitor.
	 */
	public function test_it_is_not_appended_to_a_menu_that_auto_adds_pages(): void {
		$menu_id = $this->menu_that_auto_adds_pages();

		$this->scan_page->create();

		$this->assertSame( array(), wp_get_nav_menu_items( $menu_id ) ?: array() );
	}

	/**
	 * Core's handler is unhooked for the insert, so the test that matters most
	 * is the one proving it went back: a merchant's own next page must still
	 * land in the menu they configured.
	 */
	public function test_an_ordinary_page_still_reaches_that_menu_afterwards(): void {
		$menu_id = $this->menu_that_auto_adds_pages();

		$this->scan_page->create();

		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'About us',
				'post_status' => 'publish',
			)
		);

		$titles = wp_list_pluck( wp_get_nav_menu_items( $menu_id ) ?: array(), 'title' );

		$this->assertSame( array( 'About us' ), $titles );
	}

	/**
	 * The navigation on a block theme, which is the default a store is likely
	 * to be running. With no menu built, the Navigation block falls back to a
	 * Page List, and a Page List is every published page — so a page nobody is
	 * supposed to find lands in the site's own header.
	 */
	public function test_the_page_list_block_does_not_link_to_it(): void {
		$id    = $this->scan_page->create();
		$other = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Stockists',
				'post_status' => 'publish',
			)
		);

		$rendered = do_blocks( '<!-- wp:page-list /-->' );

		$this->assertStringContainsString( $this->link_to( $other ), $rendered, 'precondition: the block lists pages at all' );
		$this->assertStringNotContainsString( $this->link_to( $id ), $rendered );
	}

	public function test_it_is_left_out_of_page_lists(): void {
		$id = $this->scan_page->create();

		$this->assertNotContains( $id, wp_list_pluck( get_pages(), 'ID' ) );
		$this->assertStringNotContainsString( $this->link_to( $id ), (string) wp_list_pages( array( 'echo' => 0 ) ) );
	}

	/**
	 * The exclusion is a front-end one. An admin still has to be able to pick
	 * the page as a parent, or set it as the front page.
	 */
	public function test_wp_admin_can_still_list_the_page(): void {
		$id = $this->scan_page->create();

		set_current_screen( 'edit-page' );

		$this->assertContains( $id, wp_list_pluck( get_pages(), 'ID' ) );

		set_current_screen( 'front' );
	}

	public function test_the_page_asks_search_engines_to_skip_it(): void {
		$id = $this->scan_page->create();
		$this->go_to( (string) get_permalink( $id ) );

		$this->assertArrayHasKey( 'noindex', apply_filters( 'wp_robots', array() ) );
	}

	public function test_an_ordinary_page_is_left_indexable(): void {
		$this->scan_page->create();
		$other = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );

		$this->go_to( (string) get_permalink( $other ) );

		$this->assertArrayNotHasKey( 'noindex', apply_filters( 'wp_robots', array() ) );
	}

	public function test_it_is_left_out_of_the_sitemap(): void {
		$id = $this->scan_page->create();

		$args = apply_filters( 'wp_sitemaps_posts_query_args', array(), 'page' );

		$this->assertContains( $id, $args['post__not_in'] );
	}

	public function test_other_post_types_keep_their_sitemap_query(): void {
		$this->scan_page->create();

		$this->assertSame( array(), apply_filters( 'wp_sitemaps_posts_query_args', array(), 'post' ) );
	}

	public function test_it_does_not_come_back_from_the_site_search(): void {
		$id    = $this->scan_page->create();
		$other = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Skin analysis notes',
				'post_status' => 'publish',
			)
		);

		$this->go_to( home_url( '/?s=analysis' ) );

		$found = wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' );

		$this->assertContains( $other, $found, 'precondition: this search finds pages at all' );
		$this->assertNotContains( $id, $found );
	}

	/**
	 * Unlisted is the state it starts in, not the only one it has: a merchant
	 * who decides to promote the page can, and nothing about it stays hidden
	 * after that.
	 */
	public function test_a_merchant_can_let_search_find_it(): void {
		$id = $this->scan_page->create();
		$this->assertTrue( $this->scan_page->is_unlisted(), 'precondition: it starts unlisted' );

		$this->scan_page->set_unlisted( false );

		$this->go_to( (string) get_permalink( $id ) );
		$this->assertArrayNotHasKey( 'noindex', apply_filters( 'wp_robots', array() ) );

		$this->assertSame( array(), apply_filters( 'wp_sitemaps_posts_query_args', array(), 'page' ) );

		$this->go_to( home_url( '/?s=analysis' ) );
		$this->assertContains( $id, wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );

		$this->assertContains( $id, wp_list_pluck( get_pages(), 'ID' ) );
		$this->assertStringContainsString( $this->link_to( $id ), do_blocks( '<!-- wp:page-list /-->' ) );
	}

	/**
	 * The href as it appears in rendered markup, closing quote included: a bare
	 * permalink is a prefix of every page whose id starts with the same digits
	 * (`?page_id=12` inside `?page_id=123`), which would make these assertions
	 * pass or fail on unrelated posts.
	 */
	private function link_to( int $page_id ): string {
		return 'href="' . get_permalink( $page_id ) . '"';
	}

	private function menu_that_auto_adds_pages(): int {
		$this->assertNotFalse(
			has_action( 'transition_post_status', '_wp_auto_add_pages_to_menu' ),
			'precondition: WordPress is auto-adding published pages to menus'
		);

		$menu_id = wp_create_nav_menu( 'Primary' );
		update_option( 'nav_menu_options', array( 'auto_add' => array( $menu_id ) ) );

		return (int) $menu_id;
	}
}
