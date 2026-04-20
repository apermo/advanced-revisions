<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisions\Admin;

use wpdb;

/**
 * Registers a dashboard widget that surfaces revision-bloat stats so admins
 * notice it without visiting a dedicated page.
 *
 * Stats are expensive to compute on sites with millions of revisions, so
 * they're cached in a transient keyed on {@see self::TRANSIENT_KEY}.
 */
final class DashboardWidget {

	public const WIDGET_ID           = 'advanced_revisions_dashboard';
	public const TRANSIENT_KEY       = 'advanced_revisions_dashboard_stats';
	public const REQUIRED_CAPABILITY = 'edit_others_posts';

	/**
	 * Transient TTL in seconds (1 hour).
	 */
	public const TTL = 3600;

	/**
	 * Registers on the dashboard setup hook.
	 */
	public static function register(): void {
		add_action( 'wp_dashboard_setup', [ self::class, 'add_widget' ] );
	}

	/**
	 * Adds the widget to the dashboard — gated by capability.
	 */
	public static function add_widget(): void {
		if ( ! current_user_can( self::REQUIRED_CAPABILITY ) ) {
			return;
		}
		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'Revisions', 'advanced-revisions' ),
			[ self::class, 'render' ],
		);
	}

	/**
	 * Renders the widget body.
	 */
	public static function render(): void {
		$stats = self::stats();

		if ( $stats['total'] === 0 ) {
			echo '<p>' . esc_html__( 'No stored revisions.', 'advanced-revisions' ) . '</p>';
			return;
		}

		echo '<p>' . wp_kses(
			\sprintf(
				/* translators: %s: formatted revision count, wrapped in <strong> */
				_n(
					'<strong>%s</strong> revision stored site-wide.',
					'<strong>%s</strong> revisions stored site-wide.',
					$stats['total'],
					'advanced-revisions',
				),
				esc_html( number_format_i18n( $stats['total'] ) ),
			),
			[ 'strong' => [] ],
		) . '</p>';

		if ( $stats['est_bytes'] > 0 ) {
			echo '<p>' . wp_kses(
				\sprintf(
					/* translators: %s: formatted database size, wrapped in <strong> */
					__( 'Estimated database footprint: <strong>%s</strong>', 'advanced-revisions' ),
					esc_html( size_format( $stats['est_bytes'] ) ),
				),
				[ 'strong' => [] ],
			) . '</p>';
		}

		if ( $stats['top'] !== [] ) {
			echo '<p>' . esc_html__( 'Heaviest posts:', 'advanced-revisions' ) . '</p>';
			echo '<ol>';
			foreach ( $stats['top'] as $entry ) {
				\printf(
					'<li>%1$s <em>(%2$s)</em></li>',
					esc_html( $entry['title'] ),
					esc_html(
						\sprintf(
							/* translators: %d: revision count */
							_n(
								'%d revision',
								'%d revisions',
								$entry['count'],
								'advanced-revisions',
							),
							$entry['count'],
						),
					),
				);
			}
			echo '</ol>';
		}
	}

	/**
	 * Returns cached stats, computing + storing them if the transient is empty.
	 *
	 * @return array{total:int, est_bytes:int, top:array<int, array{title:string, count:int}>}
	 */
	public static function stats(): array {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( \is_array( $cached ) && isset( $cached['total'], $cached['est_bytes'], $cached['top'] ) ) {
			return [
				'total'     => (int) $cached['total'],
				'est_bytes' => (int) $cached['est_bytes'],
				'top'       => \is_array( $cached['top'] ) ? $cached['top'] : [],
			];
		}

		$fresh = self::compute();
		set_transient( self::TRANSIENT_KEY, $fresh, self::TTL );
		return $fresh;
	}

	/**
	 * Flushes the cached stats — call after a cleanup run so numbers refresh.
	 */
	public static function flush(): void {
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * Runs the aggregation queries.
	 *
	 * Every query excludes autosaves via the `post_name NOT LIKE '<parent>-autosave-%'`
	 * pattern so the widget totals match what OverviewPage and PostListColumn
	 * display — both of which already exclude autosaves.
	 *
	 * @return array{total:int, est_bytes:int, top:array<int, array{title:string, count:int}>}
	 */
	private static function compute(): array {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return [
				'total'     => 0,
				'est_bytes' => 0,
				'top'       => [],
			];
		}

		return [
			'total'     => self::compute_total( $wpdb ),
			'est_bytes' => self::compute_est_bytes( $wpdb ),
			'top'       => self::compute_top_posts( $wpdb ),
		];
	}

	/**
	 * Counts every non-autosave revision row site-wide.
	 *
	 * @param wpdb $wpdb WordPress database abstraction.
	 */
	private static function compute_total( wpdb $wpdb ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- aggregation query; cached in transient.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i
					WHERE post_type = 'revision'
						AND post_name NOT LIKE CONCAT(post_parent, %s)",
				[ $wpdb->posts, '-autosave-%' ],
			),
		);
	}

	/**
	 * Estimates database footprint of stored revisions via SUM(LENGTH(post_content)).
	 *
	 * @param wpdb $wpdb WordPress database abstraction.
	 */
	private static function compute_est_bytes( wpdb $wpdb ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- aggregation query; cached in transient.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(LENGTH(post_content)), 0) FROM %i
					WHERE post_type = 'revision'
						AND post_name NOT LIKE CONCAT(post_parent, %s)",
				[ $wpdb->posts, '-autosave-%' ],
			),
		);
	}

	/**
	 * Returns the top five parent posts ranked by non-autosave revision count.
	 *
	 * p.post_title is listed in GROUP BY so the query stays portable to MySQL
	 * configurations with ONLY_FULL_GROUP_BY that don't honor
	 * functional-dependency detection (MariaDB, managed hosts).
	 *
	 * @param wpdb $wpdb WordPress database abstraction.
	 * @return array<int, array{title:string, count:int}>
	 */
	private static function compute_top_posts( wpdb $wpdb ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- aggregation query; cached in transient.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.post_title, COUNT(r.ID) AS revision_count
					FROM %i p
					INNER JOIN %i r
						ON r.post_parent = p.ID
						AND r.post_type = 'revision'
						AND r.post_name NOT LIKE CONCAT(p.ID, %s)
					WHERE p.post_type != 'revision'
					GROUP BY p.ID, p.post_title
					ORDER BY revision_count DESC
					LIMIT 5",
				[ $wpdb->posts, $wpdb->posts, '-autosave-%' ],
			),
		);

		$top_posts = [];
		if ( ! \is_array( $rows ) ) {
			return $top_posts;
		}
		foreach ( $rows as $row_data ) {
			if ( ! isset( $row_data->post_title, $row_data->revision_count ) ) {
				continue;
			}
			$top_posts[] = [
				'title' => (string) $row_data->post_title,
				'count' => (int) $row_data->revision_count,
			];
		}
		return $top_posts;
	}
}
