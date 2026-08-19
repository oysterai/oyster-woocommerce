<?php
/**
 * Turns a sync rejection into something a merchant can act on.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Rejection reasons arrive as whatever failed underneath — sometimes a sentence,
 * sometimes a database driver's own words. The latter is accurate and useless to
 * the person who has to fix it: nobody reading "SQLSTATE[23505] duplicate key
 * value violates unique constraint" knows they have two products sharing a SKU.
 *
 * Known shapes are translated into the action they imply. Anything unrecognised
 * is passed through unchanged rather than replaced with something vague — a
 * message we do not understand is still better than "an error occurred", and
 * hiding it would make an unfamiliar failure impossible to report.
 */
final class Sync_Error_Message {

	/** Longest raw message kept when nothing matches; these can be pages of SQL. */
	private const MAX_LENGTH = 300;

	public static function humanize( string $raw ): string {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return __( 'Oyster rejected this product without saying why.', 'oyster-woocommerce' );
		}

		foreach ( self::rules() as $needles => $message ) {
			foreach ( explode( '|', $needles ) as $needle ) {
				if ( false !== stripos( $raw, $needle ) ) {
					return $message;
				}
			}
		}

		if ( strlen( $raw ) > self::MAX_LENGTH ) {
			return substr( $raw, 0, self::MAX_LENGTH - 1 ) . '…';
		}

		return $raw;
	}

	/**
	 * Matched in order, so put the specific before the general.
	 *
	 * @return array<string, string>
	 */
	private static function rules(): array {
		return array(
			// A SKU is unique per store in Oyster, so two products carrying the
			// same one cannot both be listed.
			'products_vendor_id_sku_unique|duplicate key|23505|unique constraint' => __(
				'Another product already uses this SKU. Give each product its own SKU, or clear it.',
				'oyster-woocommerce'
			),
			'value too long|22001'                                               => __(
				'One of this product\'s fields is too long for Oyster. Shorten the name or SKU.',
				'oyster-woocommerce'
			),
			'not-null constraint|23502'                                          => __(
				'This product is missing something Oyster requires. Check it has a name and a price.',
				'oyster-woocommerce'
			),
			'invalid input syntax|22P02|numeric'                                 => __(
				'One of this product\'s values is not in a format Oyster accepts. Check the price and weight.',
				'oyster-woocommerce'
			),
			'foreign key|23503'                                                  => __(
				'This product refers to something Oyster could not find. Try syncing again.',
				'oyster-woocommerce'
			),
			'deadlock|lock wait|40P01'                                           => __(
				'Oyster was busy when this product was sent. Try syncing again.',
				'oyster-woocommerce'
			),
		);
	}
}
