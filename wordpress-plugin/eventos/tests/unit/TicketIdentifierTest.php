<?php
/**
 * Unit tests for Ticket_Identifier.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Tests\Unit;

use EventOS\Events\Ticket_Identifier;
use PHPUnit\Framework\TestCase;

final class TicketIdentifierTest extends TestCase {

	public function test_ticket_number_has_expected_shape(): void {
		$number = Ticket_Identifier::ticket_number( 42 );

		$this->assertMatchesRegularExpression( '/^EVT42-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/', $number );
	}

	public function test_ticket_number_excludes_ambiguous_characters(): void {
		// 0/O and 1/I/L are easy to mistype when a door operator keys a
		// ticket number in by hand — the alphabet must never produce them.
		for ( $i = 0; $i < 200; $i++ ) {
			$number = Ticket_Identifier::ticket_number( 1 );

			foreach ( array( '0', 'O', '1', 'I', 'L' ) as $ambiguous ) {
				$this->assertStringNotContainsString( $ambiguous, $number );
			}
		}
	}

	public function test_ticket_number_is_scoped_to_its_event(): void {
		$this->assertStringStartsWith( 'EVT7-', Ticket_Identifier::ticket_number( 7 ) );
		$this->assertStringStartsWith( 'EVT123-', Ticket_Identifier::ticket_number( 123 ) );
	}

	public function test_ticket_numbers_are_effectively_unique(): void {
		$seen = array();

		for ( $i = 0; $i < 500; $i++ ) {
			$seen[ Ticket_Identifier::ticket_number( 7 ) ] = true;
		}

		// 500 draws from a 32^8 keyspace should never collide in practice;
		// Ticket_Repository::issue() additionally retries on the database's
		// own unique constraint, so this only needs to prove the generator
		// itself is not degenerate (e.g. always returning the same value).
		$this->assertCount( 500, $seen );
	}

	public function test_qr_token_is_40_hex_characters(): void {
		$token = Ticket_Identifier::qr_token();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{40}$/', $token );
	}

	public function test_qr_tokens_are_effectively_unique(): void {
		$seen = array();

		for ( $i = 0; $i < 500; $i++ ) {
			$seen[ Ticket_Identifier::qr_token() ] = true;
		}

		$this->assertCount( 500, $seen );
	}

	public function test_qr_token_and_ticket_number_never_collide_in_shape(): void {
		// The scanner looks a code up as a QR token first, then falls back
		// to ticket number — the two formats must stay unambiguous.
		$token  = Ticket_Identifier::qr_token();
		$number = Ticket_Identifier::ticket_number( 1 );

		$this->assertDoesNotMatchRegularExpression( '/^EVT\d+-/', $token );
		$this->assertDoesNotMatchRegularExpression( '/^[0-9a-f]{40}$/', $number );
	}
}
