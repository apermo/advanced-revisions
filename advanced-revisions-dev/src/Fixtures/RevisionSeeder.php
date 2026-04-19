<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisionsDev\Fixtures;

use WP_Post;
use WP_Query;

/**
 * Creates revision rows (and optional autosaves + orphans) on top of seeded
 * content. Every row created here carries {@see Marker::SEEDED_REVISION} so
 * {@see self::reset()} can delete exactly what was seeded.
 *
 * Revisions are inserted via wp_insert_post() with post_type='revision'; this
 * is what core does internally. Autosaves share the same shape with a
 * different post_name suffix.
 */
final class RevisionSeeder {

	/**
	 * Revision counts per distribution preset: [min, max].
	 *
	 * @var array<string, array{int, int}>
	 */
	private const DISTRIBUTIONS = [
		'sparse'  => [ 0, 5 ],
		'normal'  => [ 1, 25 ],
		'heavy'   => [ 10, 100 ],
		'extreme' => [ 100, 500 ],
	];

	/**
	 * Inject the shared RevisionGenerator.
	 *
	 * @param RevisionGenerator $generator Used to mutate post bodies/titles.
	 */
	public function __construct(
		private readonly RevisionGenerator $generator,
	) {
	}

	/**
	 * Run a revision-seed pass. Returns a stats map.
	 *
	 * @param array<int, int>      $post_ids IDs of parent posts to revision.
	 * @param array<string, mixed> $options  Shape: distribution:string, seed:int, spread_days:int, autosave_ratio:float, orphan_count:int.
	 * @return array{revisions:int, autosaves:int, orphans:int}
	 */
	public function seed( array $post_ids, array $options ): array {
		$rng     = new Randomizer( (int) ( $options['seed'] ?? 0 ) );
		$dist    = (string) ( $options['distribution'] ?? 'normal' );
		$spread  = \max( 1, (int) ( $options['spread_days'] ?? 1095 ) );
		$ratio   = (float) ( $options['autosave_ratio'] ?? 0.1 );
		$orphans = \max( 0, (int) ( $options['orphan_count'] ?? 0 ) );
		$range   = self::DISTRIBUTIONS[ $dist ] ?? self::DISTRIBUTIONS['normal'];
		// Anchor "now" once per run so --seed=N reproduces identical timestamps.
		$now_gmt = isset( $options['now'] ) && \is_int( $options['now'] ) && $options['now'] > 0
			? $options['now']
			: \time();
		$stats   = [
			'revisions' => 0,
			'autosaves' => 0,
			'orphans'   => 0,
		];

		foreach ( $post_ids as $post_id ) {
			$counts = $this->seed_for_parent( $rng, $post_id, $range, $ratio, $spread, $now_gmt );
			$stats['revisions'] += $counts[0];
			$stats['autosaves'] += $counts[1];
		}

		$stats['orphans'] = $this->seed_orphans( $rng, $orphans, $spread, $now_gmt );

		return $stats;
	}

	/**
	 * Seed revisions + autosaves for one parent post.
	 *
	 * @param Randomizer     $rng         Deterministic PRNG.
	 * @param int            $post_id     Parent post ID.
	 * @param array{int,int} $range       [min, max] revision count bounds.
	 * @param float          $ratio       Autosave ratio (0..1).
	 * @param int            $spread_days Historical date window in days.
	 * @param int            $now_gmt     Anchor timestamp (seconds since epoch, UTC).
	 * @return array{int, int} Tuple of [revisions_inserted, autosaves_inserted].
	 */
	private function seed_for_parent( Randomizer $rng, int $post_id, array $range, float $ratio, int $spread_days, int $now_gmt ): array {
		$parent_post = get_post( $post_id );
		if ( ! $parent_post instanceof WP_Post ) {
			return [ 0, 0 ];
		}

		$total          = $rng->int_between( $range[0], $range[1] );
		$autosave_count = (int) \floor( $total * $ratio );
		$revision_count = $total - $autosave_count;

		$revisions = $this->insert_revisions( $rng, $parent_post, $revision_count, $spread_days, false, $now_gmt );
		$autosaves = $this->insert_revisions( $rng, $parent_post, $autosave_count, $spread_days, true, $now_gmt );

		return [ $revisions, $autosaves ];
	}

	/**
	 * Insert N revisions of a given variant and report how many succeeded.
	 *
	 * @param Randomizer $rng         Deterministic PRNG.
	 * @param WP_Post    $parent_post Parent post.
	 * @param int        $count       Number of rows to insert.
	 * @param int        $spread_days Historical date window in days.
	 * @param bool       $is_autosave Whether to use the autosave post_name pattern.
	 * @param int        $now_gmt     Anchor timestamp (seconds since epoch, UTC).
	 */
	private function insert_revisions( Randomizer $rng, WP_Post $parent_post, int $count, int $spread_days, bool $is_autosave, int $now_gmt ): int {
		$inserted = 0;
		for ( $i = 1; $i <= $count; $i++ ) {
			if ( $this->insert_revision( $rng, $parent_post, $i, $spread_days, $is_autosave, $now_gmt ) ) {
				$inserted++;
			}
		}
		return $inserted;
	}

	/**
	 * Insert N orphan revisions.
	 *
	 * @param Randomizer $rng         Deterministic PRNG.
	 * @param int        $count       Number of orphans to insert.
	 * @param int        $spread_days Historical date window in days.
	 * @param int        $now_gmt     Anchor timestamp (seconds since epoch, UTC).
	 */
	private function seed_orphans( Randomizer $rng, int $count, int $spread_days, int $now_gmt ): int {
		$inserted = 0;
		for ( $i = 0; $i < $count; $i++ ) {
			if ( $this->insert_orphan( $rng, $spread_days, $i, $now_gmt ) ) {
				$inserted++;
			}
		}
		return $inserted;
	}

	/**
	 * Delete every revision this seeder created (marker-meta match).
	 *
	 * @return int Number of revisions deleted.
	 */
	public function reset(): int {
		$query = new WP_Query(
			// phpcs:ignore Apermo.DataStructures.ArrayComplexity.TooManyKeys -- WP_Query args are a WP API shape.
			[
				'post_type'      => [ 'revision' ],
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => Marker::SEEDED_REVISION, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => Marker::YES, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'no_found_rows'  => true,
			],
		);

		$deleted = 0;
		foreach ( $query->posts as $revision_id ) {
			if ( ! \is_int( $revision_id ) ) {
				continue;
			}
			// phpcs:ignore Apermo.WordPress.RequireWpErrorHandling.Unchecked -- wp_delete_post_revision typed without WP_Error.
			$result = wp_delete_post_revision( $revision_id );
			if ( $result === false || $result === null ) {
				continue;
			}
			$deleted++;
		}

		return $deleted;
	}

	/**
	 * Insert one revision row (or autosave) for a given parent post.
	 *
	 * @param Randomizer $rng         Deterministic PRNG.
	 * @param WP_Post    $parent_post Parent post.
	 * @param int        $index       1-based revision index for this parent.
	 * @param int        $spread_days Historical date window in days.
	 * @param bool       $is_autosave Whether to use the autosave post_name pattern.
	 * @param int        $now_gmt     Anchor timestamp (seconds since epoch, UTC).
	 */
	private function insert_revision( Randomizer $rng, WP_Post $parent_post, int $index, int $spread_days, bool $is_autosave, int $now_gmt ): bool {
		$mutation = $this->generator->mutated_body( $rng, $parent_post->post_content, $index );
		$title    = $this->generator->mutated_title( $rng, $parent_post->post_title );
		$date_gmt = $this->historical_date( $rng, $spread_days, $now_gmt );
		$suffix   = $is_autosave ? '-autosave-v' : '-revision-v';

		$row_id = wp_insert_post(
			// phpcs:ignore Apermo.DataStructures.ArrayComplexity.TooManyKeys -- wp_insert_post args are a WP API shape.
			[
				'post_type'     => 'revision',
				'post_parent'   => $parent_post->ID,
				'post_status'   => 'inherit',
				'post_name'     => $parent_post->ID . $suffix . $index,
				'post_title'    => $title,
				'post_content'  => $mutation,
				'post_author'   => (int) $parent_post->post_author,
				'post_date_gmt' => $date_gmt,
				'post_date'     => $date_gmt,
			],
			true,
		);

		// @phpstan-ignore-next-line smaller.alwaysFalse -- wp_insert_post can return 0 on silent failure.
		if ( is_wp_error( $row_id ) || $row_id <= 0 ) {
			return false;
		}

		update_post_meta( $row_id, Marker::SEEDED_REVISION, Marker::YES );
		return true;
	}

	/**
	 * Insert an orphan revision — post_parent points to a non-existent ID.
	 *
	 * @param Randomizer $rng         Deterministic PRNG.
	 * @param int        $spread_days Historical date window in days.
	 * @param int        $index       0-based orphan index, used to vary post_name.
	 * @param int        $now_gmt     Anchor timestamp (seconds since epoch, UTC).
	 */
	private function insert_orphan( Randomizer $rng, int $spread_days, int $index, int $now_gmt ): bool {
		$fake_parent_id = 999_000_000 + $index;
		$date_gmt       = $this->historical_date( $rng, $spread_days, $now_gmt );

		$row_id = wp_insert_post(
			// phpcs:ignore Apermo.DataStructures.ArrayComplexity.TooManyKeys -- wp_insert_post args are a WP API shape.
			[
				'post_type'     => 'revision',
				'post_parent'   => $fake_parent_id,
				'post_status'   => 'inherit',
				'post_name'     => $fake_parent_id . '-revision-v1',
				'post_title'    => 'Orphan fixture',
				'post_content'  => $this->generator->mutated_body( $rng, 'Orphaned parent was hard-deleted.', 1 ),
				'post_date_gmt' => $date_gmt,
				'post_date'     => $date_gmt,
			],
			true,
		);

		// @phpstan-ignore-next-line smaller.alwaysFalse -- wp_insert_post can return 0 on silent failure.
		if ( is_wp_error( $row_id ) || $row_id <= 0 ) {
			return false;
		}

		update_post_meta( $row_id, Marker::SEEDED_REVISION, Marker::YES );
		return true;
	}

	/**
	 * Pick a GMT timestamp within the last $spread_days days, anchored on $now_gmt.
	 *
	 * @param Randomizer $rng         Deterministic PRNG.
	 * @param int        $spread_days Window size in days.
	 * @param int        $now_gmt     Anchor timestamp (seconds since epoch, UTC).
	 */
	private function historical_date( Randomizer $rng, int $spread_days, int $now_gmt ): string {
		$offset = $rng->int_between( 0, $spread_days * 86_400 );
		return \gmdate( 'Y-m-d H:i:s', $now_gmt - $offset );
	}
}
