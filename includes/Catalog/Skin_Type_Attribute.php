<?php
/**
 * "Skin Type" global product attribute.
 *
 * Registers a WooCommerce global attribute (taxonomy `pa_skin-type`) seeded
 * with Oyster's canonical skin types, so merchants can tag which skin types a
 * product suits and the catalog sync can forward that to Oyster. The Shopify
 * app derives skin-type fit from ingredients; on WooCommerce we expose it as a
 * first-class product attribute that merchants can also import via CSV.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Idempotent installer + reader. The attribute is created once (guarded by a
 * version option) and re-created automatically if a merchant deletes it.
 */
final class Skin_Type_Attribute {

	/** Attribute slug (WooCommerce prefixes the taxonomy with `pa_`). */
	public const ATTRIBUTE_SLUG = 'skin-type';

	/** Full taxonomy name once registered. */
	public const TAXONOMY = 'pa_skin-type';

	private const VERSION_OPTION = 'oyster_woo_skin_type_attribute_version';
	private const VERSION        = 1;

	/**
	 * Canonical Oyster skin types. These MUST match the names in skin-ai-api's
	 * `skin_types` table — the backend resolves incoming values by name and
	 * ignores anything it doesn't recognise.
	 *
	 * @var array<int, string>
	 */
	private const TERMS = array( 'Normal', 'Dry', 'Oily', 'Combination', 'Sensitive', 'Acne-prone', 'Mature' );

	public function register(): void {
		// Self-heal on admin loads: cheap no-op once the version option is set,
		// and recreates the attribute if a merchant removed it.
		add_action( 'admin_init', array( __CLASS__, 'maybe_ensure' ) );
	}

	/**
	 * Ensure the attribute exists unless we've already done so at this version.
	 * Only records success so a run before WooCommerce finished loading retries.
	 */
	public static function maybe_ensure(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::VERSION ) {
			return;
		}

		if ( self::ensure() ) {
			update_option( self::VERSION_OPTION, self::VERSION );
		}
	}

	/**
	 * Create the global attribute (if missing) and seed its terms.
	 *
	 * @return bool True when the attribute + terms are in place; false when
	 *              WooCommerce isn't available yet (caller should retry later).
	 */
	public static function ensure(): bool {
		if (
			! function_exists( 'wc_create_attribute' )
			|| ! function_exists( 'wc_attribute_taxonomy_name' )
			|| ! function_exists( 'wc_attribute_taxonomy_id_by_name' )
		) {
			return false;
		}

		$taxonomy = wc_attribute_taxonomy_name( self::ATTRIBUTE_SLUG );

		if ( ! wc_attribute_taxonomy_id_by_name( self::ATTRIBUTE_SLUG ) ) {
			$created = wc_create_attribute(
				array(
					'name'         => __( 'Skin Type', 'oyster-woocommerce' ),
					'slug'         => self::ATTRIBUTE_SLUG,
					'type'         => 'select',
					'order_by'     => 'menu_order',
					'has_archives' => false,
				)
			);

			if ( is_wp_error( $created ) ) {
				return false;
			}

			// Bust WooCommerce's cached attribute-taxonomy list so the new
			// taxonomy is registered on the next `init`.
			delete_transient( 'wc_attribute_taxonomies' );
		}

		// The attribute taxonomy is normally registered on `init`; register it
		// now (idempotently) so terms can be seeded within this same request.
		if ( ! taxonomy_exists( $taxonomy ) ) {
			register_taxonomy(
				$taxonomy,
				array( 'product' ),
				array(
					'hierarchical' => false,
					'show_ui'      => false,
					'query_var'    => true,
					'rewrite'      => false,
				)
			);
		}

		foreach ( self::TERMS as $term ) {
			if ( ! term_exists( $term, $taxonomy ) ) {
				wp_insert_term( $term, $taxonomy );
			}
		}

		return true;
	}

	/**
	 * Skin-type names assigned to a product. Read by Product_Mapper.
	 *
	 * @return array<int, string>
	 */
	public static function get_for_product( int $product_id ): array {
		$terms = wp_get_post_terms( $product_id, self::TAXONOMY, array( 'fields' => 'names' ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		return array_values( array_map( 'strval', $terms ) );
	}
}
