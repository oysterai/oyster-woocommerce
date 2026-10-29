<?php

declare( strict_types=1 );

namespace Oyster\Woo\Tests\Unit;

use Oyster\Woo\Support\Business_Choice;
use PHPUnit\Framework\TestCase;

/**
 * Someone who runs several businesses on one Oyster login picks which one this
 * store joins. The choice arrives from the browser, so only a business the login
 * listed may be accepted.
 */
final class BusinessChoiceTest extends TestCase {

	private function login(): array {
		return array(
			'token'      => 'abc',
			'businesses' => array(
				array( 'vendor_id' => 7, 'business_name' => 'Fundamentals', 'is_current' => true, 'role' => 'owner' ),
				array( 'vendor_id' => 9, 'business_name' => 'Skin Functional', 'is_current' => false ),
			),
		);
	}

	public function test_reads_the_businesses_a_login_offers(): void {
		$this->assertSame(
			array(
				array( 'vendor_id' => 7, 'business_name' => 'Fundamentals', 'is_current' => true ),
				array( 'vendor_id' => 9, 'business_name' => 'Skin Functional', 'is_current' => false ),
			),
			Business_Choice::from_login( $this->login() )
		);
	}

	public function test_a_login_without_a_list_offers_nothing_to_choose(): void {
		$this->assertSame( array(), Business_Choice::from_login( array( 'token' => 'abc' ) ) );
		$this->assertFalse( Business_Choice::needs_choice( array() ) );
	}

	public function test_only_more_than_one_business_needs_a_choice(): void {
		$choices = Business_Choice::from_login( $this->login() );

		$this->assertTrue( Business_Choice::needs_choice( $choices ) );
		$this->assertFalse( Business_Choice::needs_choice( array_slice( $choices, 0, 1 ) ) );
	}

	public function test_malformed_entries_are_dropped(): void {
		$choices = Business_Choice::from_login(
			array( 'businesses' => array( 'nonsense', array( 'business_name' => 'No id' ), array( 'vendor_id' => '9', 'business_name' => 'Ok' ) ) )
		);

		$this->assertSame( array( array( 'vendor_id' => 9, 'business_name' => 'Ok', 'is_current' => false ) ), $choices );
	}

	public function test_a_choice_counts_only_when_the_login_listed_it(): void {
		$choices = Business_Choice::from_login( $this->login() );

		$this->assertSame( 'Skin Functional', Business_Choice::find( $choices, 9 )['business_name'] ?? null );
		$this->assertNull( Business_Choice::find( $choices, 1234 ) );
	}
}
