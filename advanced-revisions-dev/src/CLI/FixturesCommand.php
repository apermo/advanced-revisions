<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisionsDev\CLI;

use Apermo\AdvancedRevisionsDev\Fixtures\ContentGenerator;
use Apermo\AdvancedRevisionsDev\Fixtures\ContentSeeder;
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
