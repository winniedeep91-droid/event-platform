<?php
/**
 * Unit tests for Identity_Normalizer.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Tests\Unit;

use EventOS\Crm\Identity_Normalizer;
use PHPUnit\Framework\TestCase;

final class IdentityNormalizerTest extends TestCase {

	public function test_email_is_trimmed_and_lowercased(): void {
		$this->assertSame( 'john@example.com', Identity_Normalizer::normalize_email( '  John@Example.com ' ) );
	}

	public function test_email_leading_and_uppercase_variants_match(): void {
		$variants = array( 'John@Example.com', 'john@example.com', ' JOHN@example.com' );

		$normalized = array_map( array( Identity_Normalizer::class, 'normalize_email' ), $variants );

		$this->assertSame( array( 'john@example.com', 'john@example.com', 'john@example.com' ), $normalized );
	}

	public function test_empty_email_normalizes_to_empty_string(): void {
		$this->assertSame( '', Identity_Normalizer::normalize_email( '   ' ) );
		$this->assertSame( '', Identity_Normalizer::normalize_email( '' ) );
	}

	public function test_phone_strips_whitespace_and_formatting(): void {
		$this->assertSame( '0821234567', Identity_Normalizer::normalize_phone( '082 123 4567' ) );
		$this->assertSame( '0821234567', Identity_Normalizer::normalize_phone( '0821234567' ) );
	}

	public function test_phone_keeps_a_leading_plus(): void {
		$this->assertSame( '+27821234567', Identity_Normalizer::normalize_phone( '+27 82 123 4567' ) );
		$this->assertSame( '+27821234567', Identity_Normalizer::normalize_phone( '+27821234567' ) );
	}

	/**
	 * Deliberate: local-format (082...) and E.164 (+2782...) South African
	 * numbers are NOT folded into an equivalent value — see the class
	 * docblock. Normalization here is for consistent storage only; it is
	 * never used as an identity-matching signal, so this is not a missed
	 * match, it's the documented design boundary.
	 */
	public function test_phone_does_not_equate_local_and_international_formats(): void {
		$local_format         = Identity_Normalizer::normalize_phone( '0821234567' );
		$international_format = Identity_Normalizer::normalize_phone( '+27821234567' );

		$this->assertNotSame( $local_format, $international_format );
	}

	public function test_empty_phone_normalizes_to_empty_string(): void {
		$this->assertSame( '', Identity_Normalizer::normalize_phone( '   ' ) );
		$this->assertSame( '', Identity_Normalizer::normalize_phone( '' ) );
	}
}
