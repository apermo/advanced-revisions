<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisions\Admin;

use WP_Post;

/**
 * Adds a "Revisions" column + "Manage revisions" row action to the Posts/Pages
 * list tables for every post type that supports revisions.
 *
 * Aggregation strategy: on the first render, we batch-count revisions for all
 * parent IDs visible on the current screen in a single SQL query, cached in
 * a static map. Subsequent render calls for the same page are O(1).
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
	 * Register filters for every revision-supporting public post type.
	 */
	public static function register(): void {
		add_action( 'admin_init', [ self::class, 'hook_post_type_filters' ] );
	}

	/**
	 * Attach column + row-action filters to every revision-enabled post type.
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
	 * Insert our column after the date column (or at the end if date is absent).
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
	 * Print the revision count for a given post.
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
	 * Append a "Manage revisions" row action linking to the native compare screen.
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
	 * URL of the native revision compare screen for a post.
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
	 * Return the ID of the latest revision for a post, or null if none.
	 *
	 * @param int $post_id Parent post ID.
	 */
	private static function latest_revision_id( int $post_id ): ?int {
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
	 * Return the revision count for a single post, hitting the batch cache.
	 *
	 * @param int $post_id Parent post ID.
	 */
	public static function count_for( int $post_id ): int {
		if ( self::$counts === null ) {
			self::$counts = self::load_counts();
		}
		return self::$counts[ $post_id ] ?? 0;
	}

	/**
	 * Reset the per-request cache. Intended for tests.
	 */
	public static function reset_cache(): void {
		self::$counts = null;
	}

	/**
	 * Load a revision-count map keyed by parent post ID for all posts on the
	 * Current admin list screen, via a single SQL query.
	 *
	 * @return array<int, int>
	 */
	private static function load_counts(): array {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- aggregation query; caching is the per-request static map above.
		$rows = $wpdb->get_results(
			"SELECT post_parent, COUNT(*) AS revision_count
				FROM {$wpdb->posts}
				WHERE post_type = 'revision'
					AND post_name NOT LIKE CONCAT(post_parent, '-autosave-%')
				GROUP BY post_parent",
		);

		$counts = [];
		if ( ! \is_array( $rows ) ) {
			return $counts;
		}

		foreach ( $rows as $row_data ) {
			if ( ! isset( $row_data->post_parent, $row_data->revision_count ) ) {
				continue;
			}
			$counts[ (int) $row_data->post_parent ] = (int) $row_data->revision_count;
		}
		return $counts;
	}

	/**
	 * Public post types that support revisions.
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
