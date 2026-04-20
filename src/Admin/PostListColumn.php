<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisions\Admin;

use WP_Post;

/**
 * Adds a "Revisions" column + "Manage revisions" row action to the Posts/Pages
 * list tables for every post type that supports revisions.
 *
 * Aggregation strategy: on the first render, a single SQL query batch-fetches
 * the revision count AND the latest revision ID for every parent visible on
 * the current screen, cached in two static maps. Subsequent render calls for
 * the same page — including the per-row compare_url() calls — are O(1) lookups
 * instead of N+1 wp_get_post_revisions() queries.
 */
final class PostListColumn {

	public const COLUMN_KEY = 'advanced_revisions_count';

	/**
	 * Cached revision counts keyed by parent post ID for the current request.
	 *
	 * @var array<int, int>|null
	 */
	private static ?array $counts = null;

	/**
	 * Cached latest revision ID keyed by parent post ID for the current request.
	 * Populated alongside $counts by load_aggregates(); queried by compare_url()
	 * to avoid a wp_get_post_revisions() call per rendered row.
	 *
	 * @var array<int, int>|null
	 */
	private static ?array $latest_ids = null;

	/**
	 * Registers filters for every revision-supporting public post type.
	 */
	public static function register(): void {
		add_action( 'admin_init', [ self::class, 'hook_post_type_filters' ] );
	}

	/**
	 * Attaches column + row-action filters to every revision-enabled post type.
	 */
	public static function hook_post_type_filters(): void {
		foreach ( self::supported_post_types() as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", [ self::class, 'add_column' ] );
			add_action( "manage_{$post_type}_posts_custom_column", [ self::class, 'render_column' ], 10, 2 );
		}

		add_filter( 'post_row_actions', [ self::class, 'add_row_action' ], 10, 2 );
		add_filter( 'page_row_actions', [ self::class, 'add_row_action' ], 10, 2 );
	}

	/**
	 * Inserts our column after the date column (or at the end if date is absent).
	 *
	 * @param array<string, string> $columns Existing columns map.
	 * @return array<string, string>
	 */
	public static function add_column( array $columns ): array {
		$label = __( 'Revisions', 'advanced-revisions' );

		if ( ! \array_key_exists( 'date', $columns ) ) {
			$columns[ self::COLUMN_KEY ] = $label;
			return $columns;
		}

		$rebuilt = [];
		foreach ( $columns as $key => $value ) {
			$rebuilt[ $key ] = $value;
			if ( $key === 'date' ) {
				$rebuilt[ self::COLUMN_KEY ] = $label;
			}
		}
		return $rebuilt;
	}

	/**
	 * Prints the revision count for a given post.
	 *
	 * @param string $column  Current column slug being rendered.
	 * @param int    $post_id Parent post ID for this row.
	 */
	public static function render_column( string $column, int $post_id ): void {
		if ( $column !== self::COLUMN_KEY ) {
			return;
		}

		$count = self::count_for( $post_id );
		if ( $count === 0 ) {
			echo '<span aria-hidden="true">—</span>';
			return;
		}

		\printf(
			'<a href="%1$s">%2$s</a>',
			esc_url( self::compare_url( $post_id ) ),
			esc_html( (string) $count ),
		);
	}

	/**
	 * Appends a "Manage revisions" row action linking to the native compare screen.
	 *
	 * @param array<string, string> $actions Existing row actions.
	 * @param \WP_Post              $post    Post being rendered.
	 * @return array<string, string>
	 */
	public static function add_row_action( array $actions, WP_Post $post ): array {
		if ( self::count_for( $post->ID ) === 0 ) {
			return $actions;
		}

		$actions['advanced_revisions_manage'] = \sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( self::compare_url( $post->ID ) ),
			esc_html__( 'Manage revisions', 'advanced-revisions' ),
		);

		return $actions;
	}

	/**
	 * Returns the URL of the native revision compare screen for a post.
	 *
	 * Note: revision.php expects a revision ID (post_type=revision), not the
	 * parent post ID. If we can find the latest revision, link directly to it;
	 * otherwise fall back to the post edit screen which has a native "Browse"
	 * link to revisions.
	 *
	 * @param int $post_id Parent post ID.
	 */
	public static function compare_url( int $post_id ): string {
		$latest_revision = self::latest_revision_id( $post_id );

		if ( $latest_revision !== null ) {
			return add_query_arg(
				[ 'revision' => $latest_revision ],
				admin_url( 'revision.php' ),
			);
		}

		$edit = get_edit_post_link( $post_id, 'raw' );
		return \is_string( $edit ) && $edit !== '' ? $edit : admin_url();
	}

	/**
	 * Returns the ID of the latest revision for a post, or null if none.
	 *
	 * Hits the per-request $latest_ids cache (populated by load_aggregates() for
	 * every post on the current screen) so a list-table render is one SQL query
	 * for every column + row-action URL combined, not one per row.
	 *
	 * Falls back to wp_get_post_revisions() for post_ids not on the current
	 * screen (external callers: CLI, bulk-action previews).
	 *
	 * @param int $post_id Parent post ID.
	 */
	private static function latest_revision_id( int $post_id ): ?int {
		if ( self::$latest_ids === null ) {
			self::$counts = self::load_aggregates();
		}
		if ( isset( self::$latest_ids[ $post_id ] ) ) {
			return self::$latest_ids[ $post_id ];
		}

		$revisions = wp_get_post_revisions(
			$post_id,
			[
				'numberposts' => 1,
				'order'       => 'DESC',
				'orderby'     => 'post_date_gmt',
			],
		);

		if ( $revisions === [] ) {
			return null;
		}

		$first = \reset( $revisions );
		if ( ! $first instanceof WP_Post ) {
			return null;
		}
		return $first->ID;
	}

	/**
	 * Returns the revision count for a single post, hitting the batch cache.
	 *
	 * @param int $post_id Parent post ID.
	 */
	public static function count_for( int $post_id ): int {
		if ( self::$counts === null ) {
			self::$counts = self::load_aggregates();
		}
		return self::$counts[ $post_id ] ?? 0;
	}

	/**
	 * Resets the per-request cache. Intended for tests.
	 */
	public static function reset_cache(): void {
		self::$counts     = null;
		self::$latest_ids = null;
	}

	/**
	 * Loads the revision-count map keyed by parent post ID, and populates the
	 * parallel $latest_ids cache in the same round-trip.
	 *
	 * Scoped to the post IDs on the current admin list screen (via the main
	 * $wp_query) so we don't aggregate across every revision on the site. If
	 * no screen-scope is available, returns an empty map — the column will
	 * render "—" until a screen-scoped query is available.
	 *
	 * MAX(ID) approximates "latest revision" under the assumption that
	 * revision rows are inserted monotonically (WP core always does). In the
	 * edge case of post restoration reconciling older rows, this may not be
	 * the strictly-latest-by-date row; the link still goes to a valid revision
	 * for the same parent, which is the only correctness requirement.
	 *
	 * @return array<int, int>
	 */
	private static function load_aggregates(): array {
		global $wpdb, $wp_query;
		self::$latest_ids = [];
		if ( ! isset( $wpdb ) ) {
			return [];
		}

		$post_ids = self::current_screen_post_ids( $wp_query ?? null );
		if ( $post_ids === [] ) {
			return [];
		}

		$placeholders = \implode( ', ', \array_fill( 0, \count( $post_ids ), '%d' ) );
		// Literal LIKE pattern; no user input to escape.
		$autosave_like = '-autosave-%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- {$placeholders} is a fixed %d repeater; merged args feed wpdb::prepare.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_parent, COUNT(*) AS revision_count, MAX(ID) AS latest_id
					FROM %i
					WHERE post_type = 'revision'
						AND post_parent IN ({$placeholders})
						AND post_name NOT LIKE CONCAT(post_parent, %s)
					GROUP BY post_parent",
				\array_merge(
					[ $wpdb->posts ],
					$post_ids,
					[ $autosave_like ],
				),
			),
		);
		// phpcs:enable

		$counts = [];
		if ( ! \is_array( $rows ) ) {
			return $counts;
		}

		foreach ( $rows as $row_data ) {
			if ( ! isset( $row_data->post_parent, $row_data->revision_count, $row_data->latest_id ) ) {
				continue;
			}
			$parent_id                      = (int) $row_data->post_parent;
			$counts[ $parent_id ]           = (int) $row_data->revision_count;
			self::$latest_ids[ $parent_id ] = (int) $row_data->latest_id;
		}
		return $counts;
	}

	/**
	 * Extracts post IDs from the main $wp_query's current page of results.
	 *
	 * @param mixed $wp_query Global $wp_query reference (WP_Query or null).
	 * @return array<int, int>
	 */
	private static function current_screen_post_ids( mixed $wp_query ): array {
		if ( ! \is_object( $wp_query ) || ! isset( $wp_query->posts ) || ! \is_array( $wp_query->posts ) ) {
			return [];
		}

		$ids = [];
		foreach ( $wp_query->posts as $post ) {
			if ( \is_object( $post ) && isset( $post->ID ) ) {
				$ids[] = (int) $post->ID;
			} elseif ( \is_int( $post ) ) {
				$ids[] = $post;
			}
		}
		return $ids;
	}

	/**
	 * Returns public post types that support revisions.
	 *
	 * @return array<int, string>
	 */
	private static function supported_post_types(): array {
		$types = [];
		foreach ( get_post_types( [ 'public' => true ], 'names' ) as $post_type ) {
			if ( post_type_supports( $post_type, 'revisions' ) ) {
				$types[] = $post_type;
			}
		}
		return $types;
	}
}
