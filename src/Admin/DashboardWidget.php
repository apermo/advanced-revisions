<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisions\Admin;

/**
 * Registers a dashboard widget that surfaces revision-bloat stats so admins
 * notice it without visiting a dedicated page.
 *
 * Stats are expensive to compute on sites with millions of revisions, so
 * they're cached in a transient keyed on {@see self::TRANSIENT_KEY}.
 */
final class DashboardWidget {

	public const WIDGET_ID     = 'advanced_revisions_dashboard';
	public const TRANSIENT_KEY = 'advanced_revisions_dashboard_stats';
	public const CAPABILITY    = 'edit_others_posts';

	/**
	 * Transient TTL in seconds (1 hour).
	 */
	public const TTL = 3600;

	/**
	 * Register on the dashboard setup hook.
	 */
	public static function register(): void {
		add_action( 'wp_dashboard_setup', [ self::class, 'add_widget' ] );
	}

	/**
	 * Add the widget to the dashboard — gated by capability.
	 */
	public static function add_widget(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'Revisions', 'advanced-revisions' ),
			[ self::class, 'render' ],
		);
	}

	/**
	 * Render the widget body.
	 */
	public static function render(): void {
		$stats = self::stats();

		if ( $stats['total'] === 0 ) {
			echo '<p>' . esc_html__( 'No stored revisions. You are clean.', 'advanced-revisions' ) . '</p>';
			return;
		}

		\printf(
			'<p><strong>%1$s</strong> %2$s</p>',
			esc_html( number_format_i18n( $stats['total'] ) ),
			esc_html__( 'revisions stored site-wide.', 'advanced-revisions' ),
		);

		if ( $stats['est_bytes'] > 0 ) {
			\printf(
				'<p>%1$s <strong>%2$s</strong></p>',
				esc_html__( 'Estimated database footprint:', 'advanced-revisions' ),
				esc_html( size_format( $stats['est_bytes'] ) ),
			);
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
	 * Return cached stats, computing + storing them if the transient is empty.
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
	 * Flush the cached stats — call after a cleanup run so numbers refresh.
	 */
	public static function flush(): void {
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * Run the aggregation queries.
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- aggregation query; cached in transient.
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'",
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- aggregation query; cached in transient.
		$est_bytes = (int) $wpdb->get_var(
			"SELECT COALESCE(SUM(LENGTH(post_content)), 0) FROM {$wpdb->posts} WHERE post_type = 'revision'",
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- aggregation query; cached in transient.
		$rows = $wpdb->get_results(
			"SELECT p.post_title, COUNT(r.ID) AS revision_count
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->posts} r
					ON r.post_parent = p.ID AND r.post_type = 'revision'
				WHERE p.post_type != 'revision'
				GROUP BY p.ID
				ORDER BY revision_count DESC
				LIMIT 5",
		);

		$top_posts = [];
		if ( \is_array( $rows ) ) {
			foreach ( $rows as $row_data ) {
				if ( ! isset( $row_data->post_title, $row_data->revision_count ) ) {
					continue;
				}
				$top_posts[] = [
					'title' => (string) $row_data->post_title,
					'count' => (int) $row_data->revision_count,
				];
			}
		}

		return [
			'total'     => $total,
			'est_bytes' => $est_bytes,
			'top'       => $top_posts,
		];
	}
}
