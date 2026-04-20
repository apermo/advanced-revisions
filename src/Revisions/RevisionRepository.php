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
	 * Parent post_status values the overview considers. Applied identically in
	 * paginated() and total_parents() so the paginator denominator matches the
	 * row query — orphan/trash/auto-draft parents are excluded from both.
	 *
	 * @var list<string>
	 */
	private const OVERVIEW_STATUSES = [ 'publish', 'draft', 'pending', 'private', 'future' ];

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
		$autosave_like    = '-autosave-%';
		$status_in_clause = '(' . \implode( ',', \array_fill( 0, \count( self::OVERVIEW_STATUSES ), '%s' ) ) . ')';

		// Non-aggregated p.* columns are listed in GROUP BY alongside p.ID so the
		// query is portable to MySQL configurations with ONLY_FULL_GROUP_BY, which
		// do not universally honor functional-dependency detection (MariaDB, older
		// MySQL 5.7, managed hosts with custom sql_mode).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- {$status_in_clause} is a fixed %s repeater over OVERVIEW_STATUSES; merged args feed wpdb::prepare.
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
					AND p.post_status IN {$status_in_clause}
				GROUP BY p.ID, p.post_title, p.post_type, p.post_author
				ORDER BY revision_count DESC, p.ID ASC
				LIMIT %d OFFSET %d",
				\array_merge(
					[ $wpdb->posts, $wpdb->posts, $autosave_like ],
					self::OVERVIEW_STATUSES,
					[ $limit, $offset ],
				),
			),
		);
		// phpcs:enable

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
	 *
	 * Joins the parent row and mirrors paginated()'s post_type + post_status
	 * filters so the paginator denominator matches the numerator: orphan
	 * revisions (parent deleted) and parents in trash/auto-draft don't inflate
	 * the page count.
	 */
	public function total_parents(): int {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}

		// Literal LIKE pattern; no user input to escape.
		$autosave_like    = '-autosave-%';
		$status_in_clause = '(' . \implode( ',', \array_fill( 0, \count( self::OVERVIEW_STATUSES ), '%s' ) ) . ')';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- {$status_in_clause} is a fixed %s repeater over OVERVIEW_STATUSES; merged args feed wpdb::prepare.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT r.post_parent)
					FROM %i r
					INNER JOIN %i p ON p.ID = r.post_parent
					WHERE r.post_type = 'revision'
						AND r.post_name NOT LIKE CONCAT(r.post_parent, %s)
						AND p.post_type != 'revision'
						AND p.post_status IN {$status_in_clause}",
				\array_merge(
					[ $wpdb->posts, $wpdb->posts, $autosave_like ],
					self::OVERVIEW_STATUSES,
				),
			),
		);
		// phpcs:enable

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
