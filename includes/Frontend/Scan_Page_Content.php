<?php
/**
 * The scan page's starting content.
 *
 * @package Oyster\Woo
 */

declare( strict_types=1 );

namespace Oyster\Woo\Frontend;

use Oyster\Woo\Support\Connection;
use Oyster\Woo\Support\Widget_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A landing page, written as ordinary core blocks so the merchant owns it the
 * moment it exists: every heading, sentence and question below is editable in
 * the block editor, and removing a section is deleting a block.
 *
 * Each section is a full-width band with a constrained column inside it, which
 * is what keeps the page from reading as a narrow strip of text down the middle
 * of a wide screen. The visual work (tints, cards, icon discs, the pill and
 * chips) is done by assets/css/scan-page.css off the class names below, so the
 * markup a merchant opens in the editor stays plain blocks with plain text.
 *
 * Block attributes are kept to className, anchor, level, align and layout.
 * Hand-written markup has to match what the editor would have saved or it is
 * flagged as unexpected content, and the less each block asserts the less there
 * is to diverge.
 */
final class Scan_Page_Content {

	/** Where every call to action on the page points. */
	public const WIDGET_ANCHOR = 'skin-analysis';

	/**
	 * The colour the page is built around: the merchant's own if they set one,
	 * otherwise the colour their Oyster account was carrying when the store
	 * connected. Page decoration, unlike the widget's own colour, is not worth
	 * leaving blank rather than risking a stale value.
	 */
	public static function accent(): string {
		$chosen = (string) Widget_Settings::get()['primary_color'];

		return '' !== $chosen ? $chosen : ( new Connection() )->primary_color();
	}

	/**
	 * @param string $accent Hex colour the highlighted words are set in. Frozen
	 *                       into the content on creation, the way any colour a
	 *                       merchant picks in the editor would be.
	 */
	public static function blocks( string $accent ): string {
		return self::hero( $accent )
			. self::steps()
			. self::discover()
			. self::widget()
			. self::faq()
			. self::closing();
	}

	/*
	 * -----------------------------------------------------------------------
	 * Sections
	 * -----------------------------------------------------------------------
	 */

	private static function hero( string $accent ): string {
		return self::band(
			'oyster-scan-hero oyster-scan-band--tint',
			self::split_heading(
				__( 'Understand your skin', 'oyster-woocommerce' ),
				__( 'before you buy', 'oyster-woocommerce' ),
				$accent
			)
			. self::paragraph( __( 'Our skin analysis takes the guesswork out of skincare. In about two minutes you will know what your skin actually needs, and which of our products suit it.', 'oyster-woocommerce' ), 'oyster-scan-lede' )
			. self::chips(
				array(
					__( 'About two minutes', 'oyster-woocommerce' ),
					__( 'Personalised to your skin', 'oyster-woocommerce' ),
					__( 'Matched to our range', 'oyster-woocommerce' ),
				)
			)
			. self::button( __( 'Start your skin scan', 'oyster-woocommerce' ) )
		);
	}

	private static function steps(): string {
		$steps = array(
			array(
				__( 'Step 1', 'oyster-woocommerce' ),
				__( 'Take a photo', 'oyster-woocommerce' ),
				__( 'Use your phone or your laptop camera. Good light, no makeup, and that is all we need.', 'oyster-woocommerce' ),
			),
			array(
				__( 'Step 2', 'oyster-woocommerce' ),
				__( 'We read your skin', 'oyster-woocommerce' ),
				__( 'The analysis looks at tone, texture, hydration, breakouts and early signs of ageing.', 'oyster-woocommerce' ),
			),
			array(
				__( 'Step 3', 'oyster-woocommerce' ),
				__( 'Get your routine', 'oyster-woocommerce' ),
				__( 'A breakdown of what your skin needs, with products from our range chosen against it.', 'oyster-woocommerce' ),
			),
		);

		$marks = array( 'camera', 'scan', 'routine' );
		$cards = '';
		foreach ( $steps as $index => $step ) {
			$cards .= self::column(
				'oyster-scan-card oyster-scan-card--step oyster-scan-card--' . $marks[ $index ],
				self::paragraph( $step[0], 'oyster-scan-step-label' )
				. self::heading( 3, $step[1] )
				. self::paragraph( $step[2] )
			);
		}

		return self::band(
			'oyster-scan-steps',
			self::eyebrow( __( 'Simple and quick', 'oyster-woocommerce' ) )
			. self::heading( 2, __( 'How it works', 'oyster-woocommerce' ) )
			. self::paragraph( __( 'Three steps to a routine built around your skin.', 'oyster-woocommerce' ), 'oyster-scan-lede' )
			. self::columns( 'oyster-scan-grid oyster-scan-grid--3', $cards )
		);
	}

	private static function discover(): string {
		$points = array(
			array(
				__( 'Your skin type', 'oyster-woocommerce' ),
				__( 'Oily, dry, combination or sensitive, so every product you choose works with your skin rather than against it.', 'oyster-woocommerce' ),
			),
			array(
				__( 'The concerns that matter', 'oyster-woocommerce' ),
				__( 'Dark marks, fine lines, dehydration or breakouts, ranked so you know what to treat first.', 'oyster-woocommerce' ),
			),
			array(
				__( 'Hydration and barrier health', 'oyster-woocommerce' ),
				__( 'How well your skin is holding moisture, and whether your barrier could use more support.', 'oyster-woocommerce' ),
			),
			array(
				__( 'Products that actually fit', 'oyster-woocommerce' ),
				__( 'Recommendations from our own shelves, matched to what the analysis found.', 'oyster-woocommerce' ),
			),
		);

		$marks = array( 'skin', 'concern', 'hydration', 'match' );
		$cards = '';
		foreach ( $points as $index => $point ) {
			$cards .= self::column(
				'oyster-scan-card oyster-scan-card--' . $marks[ $index ],
				self::heading( 3, $point[0] ) . self::paragraph( $point[1] )
			);
		}

		return self::band(
			'oyster-scan-discover oyster-scan-band--tint',
			self::eyebrow( __( 'Your personalised report', 'oyster-woocommerce' ) )
			. self::heading( 2, __( 'What you will find out', 'oyster-woocommerce' ) )
			. self::paragraph( __( 'No more guessing which products to try. Your report gives you the knowledge to build a routine that works.', 'oyster-woocommerce' ), 'oyster-scan-lede' )
			. self::columns( 'oyster-scan-grid oyster-scan-grid--2', $cards )
		);
	}

	/**
	 * The scan itself. Every call to action on the page points at this anchor,
	 * so the id stays put even if the copy around it is rewritten.
	 */
	private static function widget(): string {
		return self::band(
			'oyster-scan-widget',
			self::eyebrow( __( 'Start here', 'oyster-woocommerce' ) )
			. self::heading( 2, __( 'Your skin scan', 'oyster-woocommerce' ) )
			. self::paragraph( __( 'Find somewhere with even light, and take the photo when you are ready.', 'oyster-woocommerce' ), 'oyster-scan-lede' )
			. '<!-- wp:oyster/skin-scan /-->' . "\n",
			self::WIDGET_ANCHOR
		);
	}

	private static function faq(): string {
		$questions = array(
			array(
				__( 'How long does it take?', 'oyster-woocommerce' ),
				__( 'About two minutes, including the few questions we ask alongside the photo.', 'oyster-woocommerce' ),
			),
			array(
				__( 'Do I need a special camera?', 'oyster-woocommerce' ),
				__( 'No. A phone or a laptop camera is enough. Face a window or a lamp and you will get a better read.', 'oyster-woocommerce' ),
			),
			array(
				__( 'Do I need to take my makeup off?', 'oyster-woocommerce' ),
				__( 'Yes, a bare face gives the most accurate result. Makeup hides the texture and tone the analysis is reading.', 'oyster-woocommerce' ),
			),
			array(
				__( 'Which products will it recommend?', 'oyster-woocommerce' ),
				__( 'Products from this shop, chosen against what the analysis found rather than picked off a general list.', 'oyster-woocommerce' ),
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

		return self::band(
			'oyster-scan-faq oyster-scan-band--tint',
			self::eyebrow( __( 'Common questions', 'oyster-woocommerce' ) )
			. self::heading( 2, __( 'Got questions? We have answers', 'oyster-woocommerce' ) )
			. '<!-- wp:group {"className":"oyster-scan-faq-list","layout":{"type":"constrained","contentSize":"780px"}} -->' . "\n"
			. '<div class="wp-block-group oyster-scan-faq-list">' . "\n" . $details . '</div>' . "\n"
			. '<!-- /wp:group -->' . "\n"
		);
	}

	private static function closing(): string {
		return self::band(
			'oyster-scan-closing',
			'<!-- wp:group {"className":"oyster-scan-closing-card","layout":{"type":"constrained","contentSize":"620px"}} -->' . "\n"
			. '<div class="wp-block-group oyster-scan-closing-card">' . "\n"
			. self::heading( 2, __( 'Ready to find out what your skin needs?', 'oyster-woocommerce' ) )
			. self::paragraph( __( 'It takes two minutes, and you will know exactly what to shop for.', 'oyster-woocommerce' ), 'oyster-scan-lede' )
			. self::button( __( 'Start your skin scan', 'oyster-woocommerce' ) )
			. '</div>' . "\n"
			. '<!-- /wp:group -->' . "\n"
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Block builders
	 * -----------------------------------------------------------------------
	 */

	/**
	 * A full-width band with a constrained column inside it. `align: full` is
	 * how a block theme is asked to let a section out of its content column;
	 * the plugin's own template is full width already, and the stylesheet
	 * handles the rest.
	 */
	private static function band( string $class_name, string $inner, string $anchor = '' ): string {
		$classes = 'oyster-scan-section ' . $class_name;

		$attrs = array(
			'align'     => 'full',
			'className' => $classes,
		);
		if ( '' !== $anchor ) {
			$attrs['anchor'] = $anchor;
		}
		$attrs['layout'] = array(
			'type'        => 'constrained',
			'contentSize' => '1120px',
		);

		$id = '' !== $anchor ? ' id="' . esc_attr( $anchor ) . '"' : '';

		return '<!-- wp:group ' . self::json( $attrs ) . ' -->' . "\n"
			. '<div class="wp-block-group alignfull ' . esc_attr( $classes ) . '"' . $id . '>' . "\n"
			. $inner
			. '</div>' . "\n"
			. '<!-- /wp:group -->' . "\n";
	}

	private static function columns( string $class_name, string $inner ): string {
		return '<!-- wp:columns {"className":"' . esc_attr( $class_name ) . '"} -->' . "\n"
			. '<div class="wp-block-columns ' . esc_attr( $class_name ) . '">' . "\n" . $inner . '</div>' . "\n"
			. '<!-- /wp:columns -->' . "\n";
	}

	private static function column( string $class_name, string $inner ): string {
		return '<!-- wp:column {"className":"' . esc_attr( $class_name ) . '"} -->' . "\n"
			. '<div class="wp-block-column ' . esc_attr( $class_name ) . '">' . "\n" . $inner . '</div>' . "\n"
			. '<!-- /wp:column -->' . "\n";
	}

	private static function chips( array $labels ): string {
		$inner = '';
		foreach ( $labels as $label ) {
			$inner .= self::paragraph( (string) $label, 'oyster-scan-chip' );
		}

		$attrs = array(
			'className' => 'oyster-scan-chips',
			'layout'    => array(
				'type'            => 'flex',
				'flexWrap'        => 'wrap',
				'justifyContent'  => 'center',
			),
		);

		return '<!-- wp:group ' . self::json( $attrs ) . ' -->' . "\n"
			. '<div class="wp-block-group oyster-scan-chips">' . "\n" . $inner . '</div>' . "\n"
			. '<!-- /wp:group -->' . "\n";
	}

	/**
	 * The headline, with its closing words in the vendor's colour. `mark` with
	 * a transparent background is the markup the editor's own inline colour
	 * control produces, so the merchant can recolour or clear it there.
	 *
	 * A heading rather than a title: every theme prints the page's own title
	 * above the content, so this reads as the line under it. Claiming the h1
	 * here would give the page two of them in any theme that prints one, which
	 * is every theme.
	 */
	private static function split_heading( string $lead, string $accented, string $accent ): string {
		$mark = '<mark style="background-color:rgba(0,0,0,0);color:' . esc_attr( $accent ) . '" class="has-inline-color">' . esc_html( $accented ) . '</mark>';

		return '<!-- wp:heading {"className":"oyster-scan-title"} -->' . "\n"
			. '<h2 class="wp-block-heading oyster-scan-title">' . esc_html( $lead ) . ' ' . $mark . '</h2>' . "\n"
			. '<!-- /wp:heading -->' . "\n";
	}

	private static function eyebrow( string $text, string $extra = '' ): string {
		return self::paragraph( $text, trim( 'oyster-scan-eyebrow ' . $extra ) );
	}

	private static function heading( int $level, string $text ): string {
		$attrs = 2 === $level ? '' : ' {"level":' . $level . '}';

		return '<!-- wp:heading' . $attrs . ' -->' . "\n"
			. '<h' . $level . ' class="wp-block-heading">' . esc_html( $text ) . '</h' . $level . '>' . "\n"
			. '<!-- /wp:heading -->' . "\n";
	}

	private static function paragraph( string $text, string $class_name = '' ): string {
		$attrs = '' !== $class_name ? ' {"className":"' . esc_attr( $class_name ) . '"}' : '';
		$class = '' !== $class_name ? ' class="' . esc_attr( $class_name ) . '"' : '';

		return '<!-- wp:paragraph' . $attrs . ' -->' . "\n"
			. '<p' . $class . '>' . esc_html( $text ) . '</p>' . "\n"
			. '<!-- /wp:paragraph -->' . "\n";
	}

	private static function button( string $label ): string {
		return '<!-- wp:buttons {"className":"oyster-scan-cta"} -->' . "\n"
			. '<div class="wp-block-buttons oyster-scan-cta"><!-- wp:button -->' . "\n"
			. '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#' . esc_attr( self::WIDGET_ANCHOR ) . '">' . esc_html( $label ) . '</a></div>' . "\n"
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
