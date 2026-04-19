<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisionsDev\Fixtures;

// phpcs:disable Apermo.WordPress.NoHardcodedTableNames.Found -- vocabulary strings below are English, not SQL.

/**
 * Mutates post bodies to produce visible diffs between revisions.
 *
 * The goal is that a human browsing the native revision.php compare screen
 * sees a meaningful diff between any two adjacent revisions — not identical
 * text. Mutations are small and deterministic given a seeded Randomizer.
 */
final class RevisionGenerator {

	private const EDIT_TOKENS = [
		'updated',
		'revised',
		'clarified',
		'expanded',
		'refactored',
		'rephrased',
		'trimmed',
		'polished',
	];

	/**
	 * Append a short mutation marker to the body.
	 *
	 * @param Randomizer $rng      Deterministic PRNG.
	 * @param string     $original Existing post body.
	 * @param int        $revision_index 1-based revision number for this post.
	 */
	public function mutated_body( Randomizer $rng, string $original, int $revision_index ): string {
		$token = (string) $rng->pick( self::EDIT_TOKENS );
		$nonce = $rng->int_between( 1000, 9_999 );

		return $original . "\n\n[rev {$revision_index}] " . $token . ' (' . $nonce . ')';
	}

	/**
	 * Mutate a title with a small suffix. Most revisions keep the same title;
	 * ~10% of the time we tweak it so the diff covers title changes too.
	 *
	 * @param Randomizer $rng      Deterministic PRNG.
	 * @param string     $original Current post title.
	 */
	public function mutated_title( Randomizer $rng, string $original ): string {
		if ( $rng->probability() < 0.9 ) {
			return $original;
		}
		$suffix = (string) $rng->pick( self::EDIT_TOKENS );
		return $original . ' — ' . $suffix;
	}
}
