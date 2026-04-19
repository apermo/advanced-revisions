<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisionsDev\Fixtures;

use WP_Query;
use WP_User;

/**
 * Inserts seeded content into WordPress. Everything this class creates carries
 * a marker meta key so {@see self::reset()} can delete exactly what was seeded
 * without touching real editorial content.
 */
final class ContentSeeder {

	/**
	 * Default target post types when none are specified.
	 *
	 * @var list<string>
	 */
	public const DEFAULT_POST_TYPES = [
		'ar_test_article',
		'ar_test_page',
		'ar_test_product',
		'ar_test_note',
		'ar_test_private',
	];

	/**
	 * Allowed post_status values per --status-mix preset.
	 *
	 * @var array<string, list<string>>
	 */
	private const STATUS_PRESETS = [
		'publish' => [ 'publish' ],
		'mixed'   => [ 'publish', 'draft', 'pending', 'private' ],
		'all'     => [ 'publish', 'draft', 'pending', 'private', 'future' ],
	];

	/**
	 * Injects the shared ContentGenerator.
	 *
	 * @param ContentGenerator $generator Used to produce wp_insert_post shapes.
	 */
	public function __construct(
		private readonly ContentGenerator $generator,
	) {
	}

	/**
	 * Runs a seed pass. Returns a per-post-type count of inserted posts.
	 *
	 * @param array<string, mixed> $options Shape: count:int, seed:int, authors:int, date_spread:int, status_mix:string, post_types:list<string>.
	 * @return array<string, int>
	 */
	public function seed( array $options ): array {
		$rng        = new Randomizer( $options['seed'] );
		$author_ids = $this->ensure_authors( $options['authors'] );
		$statuses   = self::STATUS_PRESETS[ $options['status_mix'] ] ?? self::STATUS_PRESETS['publish'];
		// Anchor "now" to a caller-provided timestamp when given, so --seed=N
		// produces byte-identical dates across runs. Without an anchor we fall
		// back to time() — documented as time-relative in that case.
		$now_gmt = isset( $options['now'] ) && \is_int( $options['now'] ) && $options['now'] > 0
			? $options['now']
			: \time();
		$spread  = \max( 1, $options['date_spread'] ) * 86_400;

		$totals = [];
		foreach ( $options['post_types'] as $post_type ) {
			$totals[ $post_type ] = 0;
			for ( $i = 0; $i < $options['count']; $i++ ) {
				$author_id = $author_ids[ $rng->int_between( 0, \count( $author_ids ) - 1 ) ];
				$status    = (string) $rng->pick( $statuses );
				$offset    = $rng->int_between( 0, $spread );
				$date_gmt  = \gmdate( 'Y-m-d H:i:s', $now_gmt - $offset );

				$post_data = $this->generator->post( $rng, $post_type, $author_id, $status, $date_gmt );
				$post_id   = wp_insert_post( $post_data, true );

				if ( is_wp_error( $post_id ) ) {
					continue;
				}

				update_post_meta( $post_id, Marker::SEEDED_POST, Marker::YES );
				$totals[ $post_type ]++;
			}
		}

		return $totals;
	}

	/**
	 * Deletes every post (and its revisions) that was created by the seeder.
	 *
	 * @return int Number of parent posts deleted.
	 */
	public function reset(): int {
		$query = new WP_Query(
			[
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => Marker::SEEDED_POST, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => Marker::YES, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'no_found_rows'  => true,
			],
		);

		$deleted = 0;
		foreach ( $query->posts as $post_id ) {
			if ( ! \is_int( $post_id ) ) {
				continue;
			}
			$result = wp_delete_post( $post_id, true );
			if ( $result === false || $result === null ) {
				continue;
			}
			$deleted++;
		}

		return $deleted;
	}

	/**
	 * Ensures N test authors exist. Creates any that are missing.
	 *
	 * @param int $count Number of authors to ensure.
	 * @return list<int>
	 */
	private function ensure_authors( int $count ): array {
		$ids = [];
		for ( $i = 1; $i <= $count; $i++ ) {
			$login    = Marker::author_login( $i );
			$existing = get_user_by( 'login', $login );
			if ( $existing instanceof WP_User ) {
				$ids[] = $existing->ID;
				continue;
			}

			$new_id = wp_insert_user(
				[
					'user_login' => $login,
					'user_email' => Marker::author_email( $i ),
					'user_pass'  => wp_generate_password( 20 ),
					'role'       => 'editor',
					'first_name' => 'Test',
					'last_name'  => 'Author ' . $i,
				],
			);

			if ( is_wp_error( $new_id ) ) {
				continue;
			}
			$ids[] = $new_id;
		}

		return $ids;
	}
}
