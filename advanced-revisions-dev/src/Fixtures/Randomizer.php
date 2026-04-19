<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisionsDev\Fixtures;

/**
 * Wraps a deterministic PRNG so the same seed reproduces the same sequence of
 * calls — essential for reproducible fixture scenarios.
 *
 * Uses PHP's Mersenne Twister. This touches global state via mt_srand(); the
 * seeder is single-threaded (WP-CLI), so parallelism is not a concern here.
 * PHP 8.2's \Random\Randomizer would be cleaner but we target 8.1.
 *
 * wp_rand() is the WP-recommended replacement for mt_rand() but it is
 * deliberately non-deterministic — useless for reproducible fixtures.
 */
final class Randomizer {

	/**
	 * Seeds the global PRNG.
	 *
	 * @param int $seed Non-zero seed; 0 means "do not seed" (use current global state).
	 */
	public function __construct( int $seed = 0 ) {
		if ( $seed !== 0 ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand -- wp_rand() is non-deterministic; fixtures need the seed to carry.
			\mt_srand( $seed );
		}
	}

	/**
	 * Returns an inclusive random integer in [$min, $max].
	 *
	 * @param int $min Lower bound, inclusive.
	 * @param int $max Upper bound, inclusive.
	 */
	public function int_between( int $min, int $max ): int {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- wp_rand() is non-deterministic; fixtures need the mt_srand seed to carry.
		return \mt_rand( $min, $max );
	}

	/**
	 * Picks one element from $choices. Returns null for an empty list.
	 *
	 * @param array<int, mixed> $choices Items to pick from.
	 */
	public function pick( array $choices ): mixed {
		$count = \count( $choices );
		if ( $count === 0 ) {
			return null;
		}
		return $choices[ $this->int_between( 0, $count - 1 ) ];
	}

	/**
	 * Returns a random float in [0, 1).
	 */
	public function probability(): float {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- see int_between().
		return \mt_rand() / ( \mt_getrandmax() + 1 );
	}
}
