<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisions\Revisions;

/**
 * Runs aggregation queries for the Tools → Revisions overview.
 *
 * Queries are raw SQL on $wpdb->posts; they're purpose-built aggregates
 * (COUNT / MIN) that WP_Query can't express cleanly. The overview caches
 * the full page result set per request.
 */
class RevisionRepository {

	/**
	 * Default rows returned per page on the overview.
	 */
	public const DEFAULT_PER_PAGE = 20;

	/**
	 * Returns paginated rows for the overview table.
	 *
	 * Each row: parent post ID, title, type, author ID, revision count,
	 * oldest revision GMT timestamp.
	 *
	 * @param int $per_page Rows per page (clamped to [1, 500]).
	 * @param int $page     1-based page number.
	 * @return array<int, array{id:int, title:string, post_type:string, author:int, revisions:int, oldest_gmt:string}>
	 */
	public function paginated( int $per_page = self::DEFAULT_PER_PAGE, int $page = 1 ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return [];
		}

		$limit  = \max( 1, \min( 500, $per_page ) );
		$offset = \max( 0, ( $page - 1 ) * $limit );
		// Literal LIKE pattern; no user input to escape.
		$autosave_like = '-autosave-%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- direct aggregation is the purpose of this helper; callers cache.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_type, p.post_author,
					COUNT(r.ID) AS revision_count,
					MIN(r.post_date_gmt) AS oldest_gmt
				FROM %i p
				INNER JOIN %i r
					ON r.post_parent = p.ID
					AND r.post_type = 'revision'
					AND r.post_name NOT LIKE CONCAT(p.ID, %s)
				WHERE p.post_type != 'revision'
					AND p.post_status IN ('publish', 'draft', 'pending', 'private', 'future')
				GROUP BY p.ID
				ORDER BY revision_count DESC, p.ID ASC
				LIMIT %d OFFSET %d",
				[
					$wpdb->posts,
					$wpdb->posts,
					$autosave_like,
					$limit,
					$offset,
				],
			),
		);

		if ( ! \is_array( $rows ) ) {
			return [];
		}

		$result = [];
		foreach ( $rows as $row_data ) {
			$result[] = [
				'id'         => (int) $row_data->ID,
				'title'      => (string) $row_data->post_title,
				'post_type'  => (string) $row_data->post_type,
				'author'     => (int) $row_data->post_author,
				'revisions'  => (int) $row_data->revision_count,
				'oldest_gmt' => (string) $row_data->oldest_gmt,
			];
		}
		return $result;
	}

	/**
	 * Counts parent posts with at least one revision — used for paginator.
	 */
	public function total_parents(): int {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}

		// Literal LIKE pattern; no user input to escape.
		$autosave_like = '-autosave-%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- aggregation count; cached per-request.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT r.post_parent)
					FROM %i r
					WHERE r.post_type = 'revision'
						AND r.post_name NOT LIKE CONCAT(r.post_parent, %s)",
				[
					$wpdb->posts,
					$autosave_like,
				],
			),
		);

		return (int) $count;
	}

	/**
	 * Returns every revision ID belonging to the given parent post IDs.
	 *
	 * @param array<int, int> $parent_ids Parent post IDs.
	 * @return array<int, int> Revision IDs.
	 */
	public function revision_ids_for_parents( array $parent_ids ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || $parent_ids === [] ) {
			return [];
		}

		$placeholders = \implode( ', ', \array_fill( 0, \count( $parent_ids ), '%d' ) );
		// Literal LIKE pattern; no user input to escape.
		$autosave_like = '-autosave-%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- {$placeholders} is a fixed %d repeater; merged args feed wpdb::prepare.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM %i
					WHERE post_type = 'revision'
					AND post_parent IN ({$placeholders})
					AND post_name NOT LIKE CONCAT(post_parent, %s)",
				\array_merge(
					[ $wpdb->posts ],
					$parent_ids,
					[ $autosave_like ],
				),
			),
		);
		// phpcs:enable

		if ( ! \is_array( $ids ) ) {
			return [];
		}

		return \array_map( 'intval', $ids );
	}
}
