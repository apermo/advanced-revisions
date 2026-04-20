<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisionsDev\Tests\Unit\Fixtures;

use Apermo\AdvancedRevisionsDev\Fixtures\Randomizer;
use Apermo\AdvancedRevisionsDev\Fixtures\RevisionGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RevisionGenerator. Diffs between revisions must be visible in the
 * native compare screen, so every mutation must change something.
 */
final class RevisionGeneratorTest extends TestCase {

	/**
	 * Asserts the mutated body always differs from the original by at least one character.
	 */
	public function test_mutated_body_changes_original(): void {
		$generator = new RevisionGenerator();
		$original  = 'The quick brown fox';

		for ( $seed = 1; $seed <= 20; $seed++ ) {
			$mutated = $generator->mutated_body( new Randomizer( $seed ), $original, 1 );
			self::assertNotSame( $original, $mutated );
		}
	}

	/**
	 * Asserts the same seed plus inputs produce an identical mutated body.
	 */
	public function test_mutated_body_is_deterministic(): void {
		$generator = new RevisionGenerator();

		$a = $generator->mutated_body( new Randomizer( 42 ), 'Hello', 3 );
		$b = $generator->mutated_body( new Randomizer( 42 ), 'Hello', 3 );

		self::assertSame( $a, $b );
	}

	/**
	 * Asserts the mutated body preserves the original text as a prefix, so diffs are additive.
	 */
	public function test_mutated_body_preserves_original_as_prefix(): void {
		$generator = new RevisionGenerator();
		$original  = 'Original paragraph.';

		$mutated = $generator->mutated_body( new Randomizer( 42 ), $original, 1 );
		self::assertStringStartsWith( $original, $mutated );
	}

	/**
	 * Asserts the mutated title is usually the same as the original (only ~10% mutated).
	 */
	public function test_mutated_title_is_usually_unchanged(): void {
		$generator = new RevisionGenerator();
		$original  = 'Title';

		$unchanged = 0;
		for ( $seed = 1; $seed <= 100; $seed++ ) {
			if ( $generator->mutated_title( new Randomizer( $seed ), $original ) === $original ) {
				$unchanged++;
			}
		}

		self::assertGreaterThan( 50, $unchanged, 'Expected most titles to remain unchanged' );
	}

	/**
	 * Asserts the mutated title sometimes differs (otherwise we'd never test title diffs).
	 */
	public function test_mutated_title_sometimes_changes(): void {
		$generator = new RevisionGenerator();
		$original  = 'Title';

		$changed = false;
		for ( $seed = 1; $seed <= 200 && ! $changed; $seed++ ) {
			if ( $generator->mutated_title( new Randomizer( $seed ), $original ) !== $original ) {
				$changed = true;
			}
		}

		self::assertTrue( $changed, 'Expected at least one title mutation across 200 seeds' );
	}
}
