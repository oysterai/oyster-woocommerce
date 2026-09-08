<?php

declare( strict_types=1 );

namespace Oyster\Woo\Tests\Unit;

use Oyster\Woo\Support\Image_Url;
use PHPUnit\Framework\TestCase;

/**
 * The encoding that decides whether a product syncs or is rejected over the name
 * of its picture.
 *
 * The two shapes here are both taken from real catalogs: a browser screenshot
 * whose title separator was already mojibake by the time it was uploaded, and a
 * camera-roll filename carrying an index in brackets. Neither is unusual, and
 * neither is a valid URL as WordPress reports it.
 */
final class ImageUrlTest extends TestCase {

	public function test_it_encodes_a_character_that_is_not_valid_utf8_in_the_filename(): void {
		$this->assertSame(
			'https://shop.test/wp-content/uploads/2026/07/Serum-30ml-%EF%BF%BD-Brand.jpeg',
			Image_Url::encode( "https://shop.test/wp-content/uploads/2026/07/Serum-30ml-\u{FFFD}-Brand.jpeg" )
		);
	}

	public function test_it_encodes_square_brackets(): void {
		$this->assertSame(
			'https://shop.test/wp-content/uploads/2025/07/PhotoRoom_20220724%5B2134%5D.jpg',
			Image_Url::encode( 'https://shop.test/wp-content/uploads/2025/07/PhotoRoom_20220724[2134].jpg' )
		);
	}

	public function test_it_encodes_a_space(): void {
		$this->assertSame(
			'https://shop.test/uploads/night%20cream.jpeg',
			Image_Url::encode( 'https://shop.test/uploads/night cream.jpeg' )
		);
	}

	public function test_it_leaves_an_already_encoded_link_alone(): void {
		$encoded = 'https://shop.test/uploads/night%20cream.jpeg';

		$this->assertSame( $encoded, Image_Url::encode( $encoded ) );
	}

	public function test_a_literal_percent_is_encoded_rather_than_read_as_an_escape(): void {
		$this->assertSame(
			'https://shop.test/uploads/100%25off.jpeg',
			Image_Url::encode( 'https://shop.test/uploads/100%off.jpeg' )
		);
	}

	public function test_it_preserves_a_query_string(): void {
		$url = 'https://shop.test/uploads/serum.jpg?v=1699&width=1024';

		$this->assertSame( $url, Image_Url::encode( $url ) );
	}

	public function test_it_encodes_a_raw_byte_that_is_not_valid_utf8(): void {
		$this->assertSame(
			'https://shop.test/uploads/caf%E9.jpeg',
			Image_Url::encode( "https://shop.test/uploads/caf\xE9.jpeg" )
		);
	}

	public function test_it_trims_surrounding_whitespace(): void {
		$this->assertSame(
			'https://shop.test/uploads/a.jpeg',
			Image_Url::encode( '  https://shop.test/uploads/a.jpeg  ' )
		);
	}

	/**
	 * Encoding cannot invent a scheme or a host. These come back as they went in
	 * so the backend still gets to reject them, rather than being dressed up as
	 * something valid.
	 */
	public function test_it_does_not_dress_up_something_that_is_not_a_url(): void {
		foreach ( array( '/wp-content/uploads/a.jpeg', '//shop.test/a.jpeg', 'shop.test/a.jpeg' ) as $value ) {
			$this->assertSame( $value, Image_Url::encode( $value ) );
		}
	}

	public function test_encoding_twice_changes_nothing(): void {
		$once = Image_Url::encode( 'https://shop.test/uploads/a b[1]%20c.jpeg' );

		$this->assertSame( $once, Image_Url::encode( $once ) );
	}

	public function test_an_empty_link_stays_empty(): void {
		$this->assertSame( '', Image_Url::encode( '' ) );
		$this->assertSame( '', Image_Url::encode( '   ' ) );
	}
}
