<?php
/**
 * Unit tests for Campaign_Status.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Tests\Unit;

use EventOS\Events\Campaign_Status;
use PHPUnit\Framework\TestCase;

final class CampaignStatusTest extends TestCase {

	private const NOW = 1_700_000_000;

	/** Not a WordPress constant on purpose — this suite has zero WP dependency. */
	private const ONE_DAY = 86400;

	public function test_active_with_no_expiry(): void {
		$this->assertSame( 'active', Campaign_Status::effective( 'active', null, self::NOW ) );
	}

	public function test_active_before_expiry(): void {
		$future = gmdate( 'Y-m-d H:i:s', self::NOW + self::ONE_DAY );

		$this->assertSame( 'active', Campaign_Status::effective( 'active', $future, self::NOW ) );
	}

	public function test_expired_after_expiry(): void {
		$past = gmdate( 'Y-m-d H:i:s', self::NOW - self::ONE_DAY );

		$this->assertSame( 'expired', Campaign_Status::effective( 'active', $past, self::NOW ) );
	}

	public function test_draft_wins_over_expired(): void {
		$past = gmdate( 'Y-m-d H:i:s', self::NOW - self::ONE_DAY );

		$this->assertSame( 'draft', Campaign_Status::effective( 'draft', $past, self::NOW ) );
	}

	public function test_paused_wins_over_expired(): void {
		$past = gmdate( 'Y-m-d H:i:s', self::NOW - self::ONE_DAY );

		$this->assertSame( 'paused', Campaign_Status::effective( 'paused', $past, self::NOW ) );
	}

	public function test_archived_wins_over_expired(): void {
		$past = gmdate( 'Y-m-d H:i:s', self::NOW - self::ONE_DAY );

		$this->assertSame( 'archived', Campaign_Status::effective( 'archived', $past, self::NOW ) );
	}
}
