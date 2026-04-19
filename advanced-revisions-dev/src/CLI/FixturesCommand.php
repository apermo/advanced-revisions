<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisionsDev\CLI;

use Apermo\AdvancedRevisionsDev\Fixtures\ContentGenerator;
use Apermo\AdvancedRevisionsDev\Fixtures\ContentSeeder;
use Apermo\AdvancedRevisionsDev\Fixtures\Marker;
use Apermo\AdvancedRevisionsDev\Fixtures\RevisionGenerator;
use Apermo\AdvancedRevisionsDev\Fixtures\RevisionSeeder;
use WP_CLI;

/**
 * WP-CLI commands for seeding dev fixtures.
 *
 * Registered under `wp ar-fixtures`. Methods map 1:1 to sub-commands.
 */
final class FixturesCommand {

	/**
	 * Seed dummy posts across the configured post types.
	 *
	 * ## OPTIONS
	 *
	 * [--count=<count>]
	 * : Posts per post type.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--seed=<seed>]
	 * : RNG seed for reproducibility. Omit for a random seed.
	 *
	 * [--authors=<authors>]
	 * : Number of test authors to rotate through.
	 * ---
	 * default: 3
	 * ---
	 *
	 * [--date-spread=<days>]
	 * : Spread post_date values across the last N days.
	 * ---
	 * default: 1095
	 * ---
	 *
	 * [--status-mix=<preset>]
	 * : post_status mix.
	 * ---
	 * default: publish
	 * options:
	 *   - publish
	 *   - mixed
	 *   - all
	 * ---
	 *
	 * [--post-types=<types>]
	 * : Comma-separated list of post type slugs. Default: all ar_test_* CPTs.
	 *
	 * [--force]
	 * : Delete any previously-seeded content before inserting.
	 *
	 * @param array<int, string>   $args       Positional args (unused).
	 * @param array<string, mixed> $assoc_args Named flags.
	 */
	public function content( array $args, array $assoc_args ): void {
		unset( $args );

		$options = $this->normalize_content_options( $assoc_args );

		if ( isset( $assoc_args['force'] ) && (bool) $assoc_args['force'] ) {
			$seeder = new ContentSeeder( new ContentGenerator() );
			$seeder->reset();
		}

		$seeder = new ContentSeeder( new ContentGenerator() );
		$totals = $seeder->seed( $options );

		$grand_total = 0;
		foreach ( $totals as $post_type => $inserted ) {
			WP_CLI::log( \sprintf( 'Seeded %d posts into %s', $inserted, $post_type ) );
			$grand_total += $inserted;
		}

		WP_CLI::success( \sprintf( 'Seeded %d posts total.', $grand_total ) );
	}

	/**
	 * Delete everything the seeder has created (marker-meta match).
	 *
	 * ## EXAMPLES
	 *
	 *     wp ar-fixtures reset-content
	 *
	 * @param array<int, string>   $args       Positional args (unused).
	 * @param array<string, mixed> $assoc_args Named flags (unused).
	 */
	public function reset_content( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );

		$seeder  = new ContentSeeder( new ContentGenerator() );
		$deleted = $seeder->reset();

		WP_CLI::success( \sprintf( 'Deleted %d seeded posts.', $deleted ) );
	}

	/**
	 * Seed revisions (and optional autosaves + orphans) on top of seeded posts.
	 *
	 * ## OPTIONS
	 *
	 * [--distribution=<preset>]
	 * : How many revisions per post.
	 * ---
	 * default: normal
	 * options:
	 *   - sparse
	 *   - normal
	 *   - heavy
	 *   - extreme
	 * ---
	 *
	 * [--seed=<seed>]
	 * : RNG seed for reproducibility.
	 *
	 * [--spread-days=<days>]
	 * : Spread revision post_date values across the last N days.
	 * ---
	 * default: 1095
	 * ---
	 *
	 * [--autosave-ratio=<ratio>]
	 * : Fraction of generated rows that become autosaves instead of revisions.
	 * ---
	 * default: 0.1
	 * ---
	 *
	 * [--orphan-count=<count>]
	 * : Orphan revisions to insert (parent ID is non-existent).
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--post-types=<types>]
	 * : Comma-separated list of post type slugs. Default: all ar_test_* CPTs.
	 *
	 * [--append]
	 * : Keep existing revisions; default is to reset seeded revisions first.
	 *
	 * @param array<int, string>   $args       Positional args (unused).
	 * @param array<string, mixed> $assoc_args Named flags.
	 */
	public function revisions( array $args, array $assoc_args ): void {
		unset( $args );

		$options = $this->normalize_revisions_options( $assoc_args );
		$seeder  = new RevisionSeeder( new RevisionGenerator() );
		$append  = isset( $assoc_args['append'] ) && (bool) $assoc_args['append'];

		if ( ! $append ) {
			$seeder->reset();
		}

		$post_ids = $this->find_seeded_post_ids( $options['post_types'] );
		$stats    = $seeder->seed( $post_ids, $options );

		WP_CLI::log( \sprintf( 'Seeded %d revisions', $stats['revisions'] ) );
		WP_CLI::log( \sprintf( 'Seeded %d autosaves', $stats['autosaves'] ) );
		WP_CLI::log( \sprintf( 'Seeded %d orphan revisions', $stats['orphans'] ) );
		WP_CLI::success(
			\sprintf(
				'Total %d rows inserted.',
				$stats['revisions'] + $stats['autosaves'] + $stats['orphans'],
			),
		);
	}

	/**
	 * Delete every revision/autosave/orphan this seeder created.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ar-fixtures reset-revisions
	 *
	 * @param array<int, string>   $args       Positional args (unused).
	 * @param array<string, mixed> $assoc_args Named flags (unused).
	 */
	public function reset_revisions( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );

		$seeder  = new RevisionSeeder( new RevisionGenerator() );
		$deleted = $seeder->reset();

		WP_CLI::success( \sprintf( 'Deleted %d seeded revisions.', $deleted ) );
	}

	/**
	 * Convenience: reset revisions then content (reverse of creation order).
	 *
	 * ## EXAMPLES
	 *
	 *     wp ar-fixtures reset-all
	 *
	 * @param array<int, string>   $args       Positional args (unused).
	 * @param array<string, mixed> $assoc_args Named flags (unused).
	 */
	public function reset_all( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );

		$rev_seeder      = new RevisionSeeder( new RevisionGenerator() );
		$content_seeder  = new ContentSeeder( new ContentGenerator() );
		$deleted_revs    = $rev_seeder->reset();
		$deleted_content = $content_seeder->reset();

		WP_CLI::success(
			\sprintf(
				'Deleted %d seeded revisions and %d seeded posts.',
				$deleted_revs,
				$deleted_content,
			),
		);
	}

	/**
	 * Look up IDs of seeded parent posts matching the configured post types.
	 *
	 * @param array<int, string> $post_types Post type slugs to include.
	 * @return array<int, int>
	 */
	private function find_seeded_post_ids( array $post_types ): array {
		if ( $post_types === [] ) {
			return [];
		}

		$ids = get_posts(
			// phpcs:ignore Apermo.DataStructures.ArrayComplexity.TooManyKeys -- get_posts args are a WP API shape.
			[
				'post_type'      => $post_types,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => Marker::SEEDED_POST, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => Marker::YES, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'no_found_rows'  => true,
			],
		);

		return \array_values( $ids );
	}

	/**
	 * Parse + default the revisions command flags.
	 *
	 * @param array<string, mixed> $assoc_args Raw flags from WP-CLI.
	 * @return array{
	 *     distribution:string,
	 *     seed:int,
	 *     spread_days:int,
	 *     autosave_ratio:float,
	 *     orphan_count:int,
	 *     post_types:list<string>
	 * }
	 */
	private function normalize_revisions_options( array $assoc_args ): array {
		$post_types = ContentSeeder::DEFAULT_POST_TYPES;
		if ( isset( $assoc_args['post-types'] ) && \is_string( $assoc_args['post-types'] ) && $assoc_args['post-types'] !== '' ) {
			$post_types = \array_values(
				\array_filter(
					\array_map( 'trim', \explode( ',', $assoc_args['post-types'] ) ),
					static fn( string $slug ): bool => $slug !== '',
				),
			);
		}

		// phpcs:ignore Apermo.DataStructures.ArrayComplexity.TooManyKeys -- options DTO mirroring CLI flags.
		return [
			'distribution'   => (string) ( $assoc_args['distribution'] ?? 'normal' ),
			'seed'           => (int) ( $assoc_args['seed'] ?? 0 ),
			'spread_days'    => \max( 1, (int) ( $assoc_args['spread-days'] ?? 1095 ) ),
			'autosave_ratio' => (float) ( $assoc_args['autosave-ratio'] ?? 0.1 ),
			'orphan_count'   => \max( 0, (int) ( $assoc_args['orphan-count'] ?? 20 ) ),
			'post_types'     => $post_types,
		];
	}

	/**
	 * Parse + defaulted options for the content command.
	 *
	 * @param array<string, mixed> $assoc_args Raw flags from WP-CLI.
	 * @return array{
	 *     count:int,
	 *     seed:int,
	 *     authors:int,
	 *     date_spread:int,
	 *     status_mix:string,
	 *     post_types:list<string>
	 * }
	 */
	private function normalize_content_options( array $assoc_args ): array {
		$post_types = ContentSeeder::DEFAULT_POST_TYPES;
		if ( isset( $assoc_args['post-types'] ) && \is_string( $assoc_args['post-types'] ) && $assoc_args['post-types'] !== '' ) {
			$post_types = \array_values(
				\array_filter(
					\array_map( 'trim', \explode( ',', $assoc_args['post-types'] ) ),
					static fn( string $slug ): bool => $slug !== '',
				),
			);
		}

		// phpcs:ignore Apermo.DataStructures.ArrayComplexity.TooManyKeys -- options shape is an internal DTO mirroring CLI flags.
		return [
			'count'       => (int) ( $assoc_args['count'] ?? 50 ),
			'seed'        => (int) ( $assoc_args['seed'] ?? 0 ),
			'authors'     => \max( 1, (int) ( $assoc_args['authors'] ?? 3 ) ),
			'date_spread' => \max( 1, (int) ( $assoc_args['date-spread'] ?? 1095 ) ),
			'status_mix'  => (string) ( $assoc_args['status-mix'] ?? 'publish' ),
			'post_types'  => $post_types,
		];
	}
}
