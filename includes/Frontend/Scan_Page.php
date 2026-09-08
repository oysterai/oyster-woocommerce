<?php
/**
 * The standalone scan page.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Frontend;

use WP_Error;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * A real WordPress page whose content is the "Oyster Skin Scan" block, created
 * on request from the Widget screen. Nothing renders it: it is the same block
 * a merchant can place by hand, on a page this plugin happens to have created.
 *
 * It exists for the case the float launcher and an inline block on an existing
 * page both handle badly — putting the scan somewhere a merchant can send a
 * link to without every shopper on the storefront meeting it first.
 *
 * Tracked by post id, never by slug or content, so the plugin only ever
 * modifies a page it created itself. A merchant who already has a page at
 * /skin-analysis/ gets a second one at a suffixed slug rather than having
 * theirs adopted and quietly hidden from search.
 */
final class Scan_Page {

	public const OPTION_KEY = 'oyster_woocommerce_scan_page_id';

	private const UNLISTED_META = '_oyster_scan_page_unlisted';

	private const SLUG = 'skin-analysis';

	public function register(): void {
		add_filter( 'wp_robots', array( $this, 'no_robots' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'exclude_from_sitemap' ), 10, 2 );
		add_filter( 'get_pages', array( $this, 'exclude_from_page_lists' ) );
		add_action( 'pre_get_posts', array( $this, 'exclude_from_search' ) );
	}

	/**
	 * @return int Page id, or 0 if there isn't one. A trashed or deleted page
	 *             reads as 0 so the merchant can create a fresh one.
	 */
	public function id(): int {
		$id = (int) get_option( self::OPTION_KEY, 0 );
		if ( $id <= 0 ) {
			return 0;
		}

		$post = get_post( $id );

		return $post instanceof WP_Post && 'page' === $post->post_type && 'trash' !== $post->post_status ? $id : 0;
	}

	public function exists(): bool {
		return $this->id() > 0;
	}

	public function url(): string {
		$id = $this->id();

		return $id > 0 ? (string) get_permalink( $id ) : '';
	}

	public function edit_url(): string {
		$id = $this->id();

		return $id > 0 ? (string) get_edit_post_link( $id, 'raw' ) : '';
	}

	/**
	 * Whether the page is being kept out of search engines, this site's own
	 * search, and theme page lists.
	 */
	public function is_unlisted(): bool {
		$id = $this->id();

		return $id > 0 && '' !== (string) get_post_meta( $id, self::UNLISTED_META, true );
	}

	public function set_unlisted( bool $unlisted ): void {
		$id = $this->id();
		if ( 0 === $id ) {
			return;
		}

		if ( $unlisted ) {
			update_post_meta( $id, self::UNLISTED_META, '1' );
			return;
		}

		delete_post_meta( $id, self::UNLISTED_META );
	}

	/**
	 * Publish the page, or return the existing one. Idempotent: a merchant who
	 * clicks twice gets one page.
	 *
	 * @return int|WP_Error The page id.
	 */
	public function create(): int|WP_Error {
		$existing = $this->id();
		if ( $existing > 0 ) {
			return $existing;
		}

		$id = $this->insert();

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		update_option( self::OPTION_KEY, $id );
		update_post_meta( $id, self::UNLISTED_META, '1' );

		return $id;
	}

	/**
	 * Publishing a top-level page appends it to every nav menu with "add new
	 * top-level pages automatically" ticked, which is the one thing this page
	 * must not do — the point of it is a link nobody finds without being given
	 * it. Core does that on `transition_post_status`, so the handler comes off
	 * for the insert and goes straight back on.
	 *
	 * Unhooking rather than blanking `nav_menu_options`: a stored option left
	 * behind by a fatal mid-insert would silently change the merchant's menu
	 * behaviour for every page they publish afterwards.
	 *
	 * @return int|WP_Error
	 */
	private function insert(): int|WP_Error {
		$was_hooked = has_action( 'transition_post_status', '_wp_auto_add_pages_to_menu' );

		if ( false !== $was_hooked ) {
			remove_action( 'transition_post_status', '_wp_auto_add_pages_to_menu', (int) $was_hooked );
		}

		try {
			return wp_insert_post(
				array(
					'post_type'      => 'page',
					'post_status'    => 'publish',
					'post_title'     => __( 'Skin analysis', 'oyster-woocommerce' ),
					'post_name'      => self::SLUG,
					'post_content'   => '<!-- wp:oyster/skin-scan /-->',
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				),
				true
			);
		} finally {
			if ( false !== $was_hooked ) {
				add_action( 'transition_post_status', '_wp_auto_add_pages_to_menu', (int) $was_hooked, 3 );
			}
		}
	}

	/*
	 * -----------------------------------------------------------------------
	 * Keeping it unlisted
	 * -----------------------------------------------------------------------
	 */

	/**
	 * @param array<string, mixed> $robots
	 * @return array<string, mixed>
	 */
	public function no_robots( array $robots ): array {
		$id = $this->id();

		if ( 0 === $id || ! is_page( $id ) || ! $this->is_unlisted() ) {
			return $robots;
		}

		return wp_robots_no_robots( $robots );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function exclude_from_sitemap( array $args, string $post_type ): array {
		if ( 'page' !== $post_type ) {
			return $args;
		}

		return $this->add_exclusion( $args );
	}

	/**
	 * Every automatic list of pages a visitor can be shown, which on a block
	 * theme includes the site's own navigation: the Navigation block falls back
	 * to a Page List when no menu has been built, and a Page List is every
	 * published page. Filtering `get_pages` rather than
	 * `wp_list_pages_excludes` is what reaches it — that hook only covers
	 * `wp_list_pages()`, while both it and the Page List block read through
	 * `get_pages()`.
	 *
	 * Front end only. In wp-admin the page has to stay listable, or it could
	 * not be picked as a parent page or set as the front page.
	 *
	 * @param array<int, mixed> $pages
	 * @return array<int, mixed>
	 */
	public function exclude_from_page_lists( array $pages ): array {
		if ( is_admin() || ! $this->is_unlisted() ) {
			return $pages;
		}

		$id = $this->id();

		return array_values(
			array_filter(
				$pages,
				static fn( $page ) => ( is_object( $page ) ? (int) $page->ID : (int) $page ) !== $id
			)
		);
	}

	/**
	 * Front-end search only. An admin searching their own pages is looking for
	 * this one as often as not.
	 */
	public function exclude_from_search( WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() || ! $this->is_unlisted() ) {
			return;
		}

		$args = $this->add_exclusion( array( 'post__not_in' => $query->get( 'post__not_in', array() ) ) );

		$query->set( 'post__not_in', $args['post__not_in'] );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private function add_exclusion( array $args ): array {
		if ( ! $this->is_unlisted() ) {
			return $args;
		}

		$excluded             = array_filter( array_map( 'absint', (array) ( $args['post__not_in'] ?? array() ) ) );
		$excluded[]           = $this->id();
		$args['post__not_in'] = array_values( array_unique( $excluded ) );

		return $args;
	}
}
