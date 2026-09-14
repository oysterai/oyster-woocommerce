<?php
/**
 * The scan page's starting content.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * A landing page, written as ordinary core blocks so the merchant owns it the
 * moment it exists: every heading, sentence and question below is editable in
 * the block editor, and deleting any section is a normal edit rather than a
 * setting.
 *
 * Block markup is kept to `className`, `anchor` and `level` attributes, with
 * the visual design in assets/css/scan-page.css. Hand-written markup has to
 * match what the editor would have saved or it is flagged as unexpected
 * content, and the less each block asserts, the less there is to diverge.
 */
final class Scan_Page_Content {

	/** Where every call to action on the page points. */
	public const WIDGET_ANCHOR = 'skin-analysis';

	public static function blocks(): string {
		return self::hero()
			. self::steps()
			. self::widget()
			. self::faq();
	}

	/**
	 * No heading of its own: the page title is the headline, printed by
	 * whichever template is rendering. A heading here would sit underneath the
	 * title a block theme already prints, giving the page two of them.
	 */
	private static function hero(): string {
		return self::section(
			'oyster-scan-hero',
			self::paragraph( __( 'Know what your skin actually needs, in minutes. Take a quick scan and get a routine built from the products we stock.', 'oyster-woocommerce' ), 'oyster-scan-lede' )
			. self::button( __( 'Analyse my skin now', 'oyster-woocommerce' ) )
		);
	}

	private static function steps(): string {
		$steps = array(
			array(
				__( 'Take a photo', 'oyster-woocommerce' ),
				__( 'Use your phone or your laptop camera. Even light on your face is all it takes.', 'oyster-woocommerce' ),
			),
			array(
				__( 'Answer a few questions', 'oyster-woocommerce' ),
				__( 'Your skin type, what you would like to improve, and what you already use.', 'oyster-woocommerce' ),
			),
			array(
				__( 'Get your routine', 'oyster-woocommerce' ),
				__( 'A read on your skin, and the products from our shelves that suit it.', 'oyster-woocommerce' ),
			),
		);

		$columns = '';
		foreach ( $steps as $index => $step ) {
			$columns .= self::column(
				self::paragraph( (string) ( $index + 1 ), 'oyster-scan-step-number' )
				. self::heading( 3, $step[0] )
				. self::paragraph( $step[1] )
			);
		}

		return self::section(
			'oyster-scan-steps',
			self::heading( 2, __( 'Three steps to a routine made for you', 'oyster-woocommerce' ) )
			. '<!-- wp:columns {"className":"oyster-scan-step-grid"} -->' . "\n"
			. '<div class="wp-block-columns oyster-scan-step-grid">' . $columns . '</div>' . "\n"
			. '<!-- /wp:columns -->' . "\n"
			. self::button( __( 'Start my skin analysis', 'oyster-woocommerce' ) )
		);
	}

	/**
	 * The scan itself. Every call to action above points at this anchor, so the
	 * id stays put even if the copy around it is rewritten.
	 */
	private static function widget(): string {
		return self::section(
			'oyster-scan-widget',
			self::heading( 2, __( 'Start your skin scan', 'oyster-woocommerce' ) )
			. self::paragraph( __( 'It takes about two minutes.', 'oyster-woocommerce' ), 'oyster-scan-lede' )
			. '<!-- wp:oyster/skin-scan /-->' . "\n",
			self::WIDGET_ANCHOR
		);
	}

	private static function faq(): string {
		$questions = array(
			array(
				__( 'How long does it take?', 'oyster-woocommerce' ),
				__( 'About two minutes, including the questions.', 'oyster-woocommerce' ),
			),
			array(
				__( 'Do I need a special camera?', 'oyster-woocommerce' ),
				__( 'No. A phone or a laptop camera is enough. Face a window or a lamp and you will get a better read.', 'oyster-woocommerce' ),
			),
			array(
				__( 'Which products will it recommend?', 'oyster-woocommerce' ),
				__( 'Products from this shop, chosen against what the scan finds rather than picked from a general list.', 'oyster-woocommerce' ),
			),
			array(
				__( 'Is this a medical diagnosis?', 'oyster-woocommerce' ),
				__( 'No. It is a cosmetic skin analysis meant to help you choose skincare. Speak to a doctor or a dermatologist about anything that concerns you.', 'oyster-woocommerce' ),
			),
			array(
				__( 'Can I scan more than once?', 'oyster-woocommerce' ),
				__( 'Yes. Scanning again after a few weeks is how you see what has changed.', 'oyster-woocommerce' ),
			),
		);

		$details = '';
		foreach ( $questions as $question ) {
			$details .= '<!-- wp:details -->' . "\n"
				. '<details class="wp-block-details"><summary>' . esc_html( $question[0] ) . '</summary>'
				. self::paragraph( $question[1] )
				. '</details>' . "\n"
				. '<!-- /wp:details -->' . "\n";
		}

		return self::section(
			'oyster-scan-faq',
			self::heading( 2, __( 'Questions about the skin analysis', 'oyster-woocommerce' ) ) . $details
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Block builders
	 * -----------------------------------------------------------------------
	 */

	private static function section( string $class_name, string $inner, string $anchor = '' ): string {
		$attrs = array( 'className' => $class_name . ' oyster-scan-section' );
		if ( '' !== $anchor ) {
			$attrs['anchor'] = $anchor;
		}
		$attrs['layout'] = array( 'type' => 'constrained' );

		$id = '' !== $anchor ? ' id="' . esc_attr( $anchor ) . '"' : '';

		return '<!-- wp:group ' . self::json( $attrs ) . ' -->' . "\n"
			. '<div class="wp-block-group ' . esc_attr( $class_name . ' oyster-scan-section' ) . '"' . $id . '>' . "\n"
			. $inner
			. '</div>' . "\n"
			. '<!-- /wp:group -->' . "\n";
	}

	private static function column( string $inner ): string {
		return '<!-- wp:column {"className":"oyster-scan-step"} -->' . "\n"
			. '<div class="wp-block-column oyster-scan-step">' . "\n" . $inner . '</div>' . "\n"
			. '<!-- /wp:column -->' . "\n";
	}

	private static function heading( int $level, string $text ): string {
		$attrs = 2 === $level ? '' : '{"level":' . $level . '}';
		$open  = '<!-- wp:heading' . ( '' !== $attrs ? ' ' . $attrs : '' ) . ' -->';

		return $open . "\n"
			. '<h' . $level . ' class="wp-block-heading">' . esc_html( $text ) . '</h' . $level . '>' . "\n"
			. '<!-- /wp:heading -->' . "\n";
	}

	private static function paragraph( string $text, string $class_name = '' ): string {
		$attrs = '' !== $class_name ? ' {"className":"' . $class_name . '"}' : '';
		$class = '' !== $class_name ? ' class="' . esc_attr( $class_name ) . '"' : '';

		return '<!-- wp:paragraph' . $attrs . ' -->' . "\n"
			. '<p' . $class . '>' . esc_html( $text ) . '</p>' . "\n"
			. '<!-- /wp:paragraph -->' . "\n";
	}

	private static function button( string $label ): string {
		$href = '#' . self::WIDGET_ANCHOR;

		return '<!-- wp:buttons {"className":"oyster-scan-cta"} -->' . "\n"
			. '<div class="wp-block-buttons oyster-scan-cta"><!-- wp:button -->' . "\n"
			. '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_attr( $href ) . '">' . esc_html( $label ) . '</a></div>' . "\n"
			. '<!-- /wp:button --></div>' . "\n"
			. '<!-- /wp:buttons -->' . "\n";
	}

	/**
	 * @param array<string, mixed> $attrs
	 */
	private static function json( array $attrs ): string {
		return (string) wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
