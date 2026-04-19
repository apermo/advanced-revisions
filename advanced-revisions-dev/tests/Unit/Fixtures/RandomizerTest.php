<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisionsDev\Tests\Unit\Fixtures;

use Apermo\AdvancedRevisionsDev\Fixtures\Randomizer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Randomizer PRNG wrapper. Determinism is the contract that
 * matters most — fixtures rely on reproducible seeds.
 *
 * Note: mt_srand()/mt_rand() share global PHP state, so tests must exhaust
 * one Randomizer before creating another. This matches how the seeder uses
 * Randomizer in practice (one instance per WP-CLI command run).
 */
final class RandomizerTest extends TestCase {

	/**
	 * Same seed produces identical sequence when consumed sequentially.
	 */
	public function test_same_seed_produces_same_sequence(): void {
		$sequence_a = [];
		$rng_a      = new Randomizer( 42 );
		for ( $i = 0; $i < 20; $i++ ) {
			$sequence_a[] = $rng_a->int_between( 0, 1_000 );
		}

		$sequence_b = [];
		$rng_b      = new Randomizer( 42 );
		for ( $i = 0; $i < 20; $i++ ) {
			$sequence_b[] = $rng_b->int_between( 0, 1_000 );
		}

		self::assertSame( $sequence_a, $sequence_b );
	}

	/**
	 * Different seeds diverge within a small window.
	 */
	public function test_different_seeds_diverge(): void {
		$sequence_a = [];
		$rng_a      = new Randomizer( 42 );
		for ( $i = 0; $i < 20; $i++ ) {
			$sequence_a[] = $rng_a->int_between( 0, 1_000 );
		}

		$sequence_b = [];
		$rng_b      = new Randomizer( 7 );
		for ( $i = 0; $i < 20; $i++ ) {
			$sequence_b[] = $rng_b->int_between( 0, 1_000 );
		}

		self::assertNotSame( $sequence_a, $sequence_b );
	}

	/**
	 * Bounds passed to int_between are inclusive on both ends.
	 */
	public function test_int_between_bounds_are_inclusive(): void {
		$rng = new Randomizer( 1 );

		for ( $i = 0; $i < 100; $i++ ) {
			$value = $rng->int_between( 5, 10 );
			self::assertGreaterThanOrEqual( 5, $value );
			self::assertLessThanOrEqual( 10, $value );
		}
	}

	/**
	 * Picking from an empty list returns null rather than throwing.
	 */
	public function test_pick_returns_null_for_empty_list(): void {
		$rng = new Randomizer( 1 );

		self::assertNull( $rng->pick( [] ) );
	}

	/**
	 * Picking always returns an element of the input list.
	 */
	public function test_pick_returns_element_from_list(): void {
		$rng     = new Randomizer( 42 );
		$choices = [ 'a', 'b', 'c' ];

		for ( $i = 0; $i < 20; $i++ ) {
			self::assertContains( $rng->pick( $choices ), $choices );
		}
	}

	/**
	 * Probability values stay in the half-open interval [0, 1).
	 */
	public function test_probability_is_bounded(): void {
		$rng = new Randomizer( 123 );

		for ( $i = 0; $i < 100; $i++ ) {
			$value = $rng->probability();
			self::assertGreaterThanOrEqual( 0.0, $value );
			self::assertLessThan( 1.0, $value );
		}
	}
}
