<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisionsDev\Tests\Unit\Fixtures;

use Apermo\AdvancedRevisionsDev\Fixtures\ContentGenerator;
use Apermo\AdvancedRevisionsDev\Fixtures\Randomizer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ContentGenerator. Output shape is the contract ContentSeeder
 * depends on, so we assert shape + determinism rather than exact strings.
 */
final class ContentGeneratorTest extends TestCase {

	/**
	 * Titles are always non-empty strings.
	 */
	public function test_title_is_non_empty_string(): void {
		$generator = new ContentGenerator();
		$rng       = new Randomizer( 42 );

		self::assertNotEmpty( $generator->title( $rng ) );
	}

	/**
	 * Asserts the same seed plus same call sequence produces the same title.
	 */
	public function test_title_is_deterministic_for_same_seed(): void {
		$generator = new ContentGenerator();

		$a = $generator->title( new Randomizer( 42 ) );
		$b = $generator->title( new Randomizer( 42 ) );

		self::assertSame( $a, $b );
	}

	/**
	 * Asserts body has at least two paragraphs (joined by blank lines).
	 */
	public function test_body_has_at_least_two_paragraphs(): void {
		$generator = new ContentGenerator();
		$rng       = new Randomizer( 42 );

		$body = $generator->body( $rng );
		self::assertGreaterThanOrEqual( 2, \substr_count( $body, "\n\n" ) + 1 );
	}

	/**
	 * Asserts the post() method returns the shape wp_insert_post() expects.
	 */
	public function test_post_returns_wp_insert_post_shape(): void {
		$generator = new ContentGenerator();
		$rng       = new Randomizer( 42 );

		$post = $generator->post( $rng, 'ar_test_article', 7, 'publish', '2025-01-15 12:00:00' );

		self::assertSame( 'ar_test_article', $post['post_type'] );
		self::assertSame( 'publish', $post['post_status'] );
		self::assertSame( 7, $post['post_author'] );
		self::assertSame( '2025-01-15 12:00:00', $post['post_date_gmt'] );
		self::assertSame( '2025-01-15 12:00:00', $post['post_date'] );
		self::assertIsString( $post['post_title'] );
		self::assertNotEmpty( $post['post_title'] );
		self::assertIsString( $post['post_content'] );
		self::assertIsString( $post['post_excerpt'] );
	}

	/**
	 * Calls to post() are byte-identical for the same seed and inputs.
	 */
	public function test_post_is_deterministic_for_same_seed(): void {
		$generator = new ContentGenerator();

		$a = $generator->post(
			new Randomizer( 42 ),
			'ar_test_article',
			7,
			'publish',
			'2025-01-15 12:00:00',
		);
		$b = $generator->post(
			new Randomizer( 42 ),
			'ar_test_article',
			7,
			'publish',
			'2025-01-15 12:00:00',
		);

		self::assertSame( $a, $b );
	}

	/**
	 * Excerpts are sometimes empty and sometimes not across 200 seeds.
	 */
	public function test_excerpt_may_be_empty(): void {
		$generator = new ContentGenerator();

		$saw_empty     = false;
		$saw_non_empty = false;

		for ( $seed = 1; $seed <= 200 && ! ( $saw_empty && $saw_non_empty ); $seed++ ) {
			$excerpt = $generator->excerpt( new Randomizer( $seed ) );
			if ( $excerpt === '' ) {
				$saw_empty = true;
			} else {
				$saw_non_empty = true;
			}
		}

		self::assertTrue( $saw_empty, 'Expected at least one empty excerpt across 200 seeds' );
		self::assertTrue( $saw_non_empty, 'Expected at least one non-empty excerpt across 200 seeds' );
	}
}
