<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisionsDev\Tests\Unit\Fixtures;

use Apermo\AdvancedRevisionsDev\Fixtures\Marker;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Marker constants and helpers.
 */
final class MarkerTest extends TestCase {

	/**
	 * Author login uses the configured prefix with a 1-based index.
	 */
	public function test_author_login_uses_one_indexed_numbers(): void {
		self::assertSame( 'ar_test_author_1', Marker::author_login( 1 ) );
		self::assertSame( 'ar_test_author_42', Marker::author_login( 42 ) );
	}

	/**
	 * Author email uses the example.tld safe domain.
	 */
	public function test_author_email_uses_example_tld(): void {
		self::assertSame(
			'ar_test_author_1@example.tld',
			Marker::author_email( 1 ),
		);
	}

	/**
	 * Post and revision marker keys are distinct so resetters don't cross-delete.
	 */
	public function test_seeded_post_meta_key_differs_from_revision(): void {
		self::assertNotSame( Marker::SEEDED_POST, Marker::SEEDED_REVISION );
	}
}
