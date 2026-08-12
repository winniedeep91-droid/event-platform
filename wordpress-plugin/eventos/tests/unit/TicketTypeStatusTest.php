<?php
/**
 * Unit tests for Ticket_Type_Status.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Tests\Unit;

use EventOS\Events\Ticket_Type_Status;
use PHPUnit\Framework\TestCase;

final class TicketTypeStatusTest extends TestCase {

	public function test_active_when_under_capacity(): void {
		$this->assertSame( 'active', Ticket_Type_Status::effective( 'active', 100, 40 ) );
	}

	public function test_active_when_capacity_unlimited(): void {
		$this->assertSame( 'active', Ticket_Type_Status::effective( 'active', null, 100000 ) );
	}

	public function test_sold_out_when_sold_equals_capacity(): void {
		$this->assertSame( 'sold_out', Ticket_Type_Status::effective( 'active', 50, 50 ) );
	}

	public function test_sold_out_when_sold_exceeds_capacity(): void {
		// Can happen legitimately: capacity lowered after tickets were sold.
		$this->assertSame( 'sold_out', Ticket_Type_Status::effective( 'active', 50, 51 ) );
	}

	public function test_paused_wins_over_sold_out(): void {
		$this->assertSame( 'paused', Ticket_Type_Status::effective( 'paused', 10, 10 ) );
	}

	public function test_archived_wins_over_sold_out(): void {
		$this->assertSame( 'archived', Ticket_Type_Status::effective( 'archived', 10, 10 ) );
	}

	public function test_would_exceed_capacity_true_when_over(): void {
		$this->assertTrue( Ticket_Type_Status::would_exceed_capacity( 10, 8, 3 ) );
	}

	public function test_would_exceed_capacity_false_on_exact_fit(): void {
		$this->assertFalse( Ticket_Type_Status::would_exceed_capacity( 10, 7, 3 ) );
	}

	public function test_would_exceed_capacity_true_just_over_the_edge(): void {
		$this->assertTrue( Ticket_Type_Status::would_exceed_capacity( 10, 8, 3 ) );
		$this->assertFalse( Ticket_Type_Status::would_exceed_capacity( 10, 8, 2 ) );
	}

	public function test_would_exceed_capacity_always_false_when_unlimited(): void {
		$this->assertFalse( Ticket_Type_Status::would_exceed_capacity( null, 999999, 100 ) );
	}
}
