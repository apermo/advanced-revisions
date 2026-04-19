<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisions\Revisions;

/**
 * Aggregation queries for the Tools → Revisions overview.
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
	 * Return paginated rows for the overview table.
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders -- static LIKE pattern with CONCAT of parent ID (int); no user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_type, p.post_author,
					COUNT(r.ID) AS revision_count,
					MIN(r.post_date_gmt) AS oldest_gmt
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->posts} r
					ON r.post_parent = p.ID
					AND r.post_type = 'revision'
					AND r.post_name NOT LIKE CONCAT(p.ID, '-autosave-%')
				WHERE p.post_type != 'revision'
					AND p.post_status IN ('publish', 'draft', 'pending', 'private', 'future')
				GROUP BY p.ID
				ORDER BY revision_count DESC, p.ID ASC
				LIMIT %d OFFSET %d",
				$limit,
				$offset,
			),
		);
		// phpcs:enable

		if ( ! \is_array( $rows ) ) {
			return [];
		}

		$result = [];
		foreach ( $rows as $row_data ) {
			// phpcs:ignore Apermo.DataStructures.ArrayComplexity.TooManyKeys -- overview row shape.
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
	 * Total number of parent posts that have at least one revision — used for paginator.
	 */
	public function total_parents(): int {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- aggregation count; cached per-request.
		$count = $wpdb->get_var(
			"SELECT COUNT(DISTINCT r.post_parent)
				FROM {$wpdb->posts} r
				WHERE r.post_type = 'revision'
					AND r.post_name NOT LIKE CONCAT(r.post_parent, '-autosave-%')",
		);

		return (int) $count;
	}

	/**
	 * Return every revision ID belonging to the given parent post IDs.
	 *
	 * @param array<int, int> $parent_ids Parent post IDs.
	 * @return array<int, int> Revision IDs.
	 */
	public function revision_ids_for_parents( array $parent_ids ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || $parent_ids === [] ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders -- %d placeholders built from a fixed count of parent IDs.
		$placeholders = \implode( ',', \array_fill( 0, \count( $parent_ids ), '%d' ) );

		$query = \sprintf(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent IN (%s)",
			$placeholders,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above from a fixed count of trusted int IDs; values passed through wpdb::prepare.
		$ids = $wpdb->get_col(
			$wpdb->prepare( $query, ...$parent_ids ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		if ( ! \is_array( $ids ) ) {
			return [];
		}

		return \array_map( 'intval', $ids );
	}
}
