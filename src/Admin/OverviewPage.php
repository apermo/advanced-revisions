<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisions\Admin;

use Apermo\AdvancedRevisions\Revisions\RevisionDeleter;
use Apermo\AdvancedRevisions\Revisions\RevisionRepository;

/**
 * Tools → Revisions page. Lists posts with stored revisions and lets admins
 * bulk-delete revisions across selected rows. Protected tags (from #13) are
 * honored via {@see RevisionDeleter}.
 */
final class OverviewPage {

	public const MENU_SLUG           = 'advanced-revisions-overview';
	public const REQUIRED_CAPABILITY = 'delete_others_posts';
	public const NONCE_ACTION        = 'advanced_revisions_overview_bulk';
	public const NONCE_NAME          = 'advanced_revisions_overview_nonce';
	public const BULK_FIELD          = 'ar_bulk_delete';

	/**
	 * Returns the capability required to view or act on the overview.
	 *
	 * Runs through the `advanced_revisions_bulk_delete_capability` filter so
	 * sites with custom role maps can widen or narrow access without editing
	 * the plugin. Per-parent authorization is additionally enforced in
	 * handle_bulk_post() via current_user_can('edit_post', $parent_id).
	 */
	public static function required_capability(): string {
		/**
		 * Filters the capability required to view or act on the Tools → Revisions
		 * overview page. Per-parent authorization still runs via
		 * current_user_can('edit_post', $parent_id) inside handle_bulk_post().
		 *
		 * @param string $capability Default: 'delete_others_posts'.
		 * @return string Capability string; falsy returns fall back to the default.
		 */
		$cap = apply_filters(
			'advanced_revisions_bulk_delete_capability',
			self::REQUIRED_CAPABILITY,
		);
		// @phpstan-ignore-next-line function.alreadyNarrowedType -- apply_filters can return any value via third-party hooks despite the declared return type.
		return \is_string( $cap ) && $cap !== '' ? $cap : self::REQUIRED_CAPABILITY;
	}

	/**
	 * Wires the admin-menu and request-handling hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_page' ] );
		add_action( 'admin_post_' . self::MENU_SLUG, [ self::class, 'handle_bulk_post' ] );
	}

	/**
	 * Registers the page under Tools.
	 */
	public static function add_page(): void {
		add_management_page(
			__( 'Revisions Overview', 'advanced-revisions' ),
			__( 'Revisions', 'advanced-revisions' ),
			self::required_capability(),
			self::MENU_SLUG,
			[ self::class, 'render' ],
		);
	}

	/**
	 * Renders the overview table.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::required_capability() ) ) {
			return;
		}

		$page       = self::current_page();
		$repository = new RevisionRepository();
		$rows       = $repository->paginated( RevisionRepository::DEFAULT_PER_PAGE, $page );
		$total      = $repository->total_parents();

		self::render_notices();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Revisions Overview', 'advanced-revisions' ) . '</h1>';
		echo '<p>';
		echo esc_html__( 'Posts with stored revisions, heaviest first. Parent posts are never touched by bulk deletion.', 'advanced-revisions' );
		echo '</p>';

		if ( $rows === [] ) {
			echo '<p>' . esc_html__( 'No posts currently have stored revisions.', 'advanced-revisions' ) . '</p>';
			echo '</div>';
			return;
		}

		self::render_form( $rows, $page, $total );

		echo '</div>';
	}

	/**
	 * Handles the bulk-delete POST submission from the overview form.
	 */
	public static function handle_bulk_post(): void {
		if ( ! current_user_can( self::required_capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'advanced-revisions' ) );
		}

		$nonce = isset( $_POST[ self::NONCE_NAME ] ) && \is_string( $_POST[ self::NONCE_NAME ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Invalid request.', 'advanced-revisions' ) );
		}

		$selected = self::selected_parent_ids();
		if ( $selected === [] ) {
			wp_safe_redirect( self::page_url( [ 'ar_bulk' => 'empty' ] ) );
			exit();
		}

		// Per-parent authorization. A user who can reach this screen still
		// shouldn't be able to delete revisions for parents they can't edit
		// (custom caps, post-type-specific roles). Unauthorized IDs drop out
		// and are counted separately in the completion notice.
		$authorized = [];
		$denied     = 0;
		foreach ( $selected as $parent_id ) {
			if ( current_user_can( 'edit_post', $parent_id ) ) {
				$authorized[] = $parent_id;
				continue;
			}
			$denied++;
		}

		if ( $authorized === [] ) {
			wp_safe_redirect(
				self::page_url(
					[
						'ar_bulk'   => 'done',
						'ar_denied' => (string) $denied,
					],
				),
			);
			exit();
		}

		$deleter = new RevisionDeleter( new RevisionRepository() );
		$result  = $deleter->delete_for_parents( $authorized );

		// Invalidate aggregate caches so the dashboard widget reflects reality.
		DashboardWidget::flush();

		wp_safe_redirect(
			self::page_url(
				[
					'ar_bulk'    => 'done',
					'ar_deleted' => (string) $result['deleted'],
					'ar_skipped' => (string) $result['skipped'],
					'ar_denied'  => (string) $denied,
				],
			),
		);
		exit();
	}

	/**
	 * Parses 1-based page number from the query string, defaulting to 1.
	 */
	private static function current_page(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination parameter.
		$paged = isset( $_GET['paged'] ) && \is_scalar( $_GET['paged'] )
			? (int) $_GET['paged'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: 1;
		return \max( 1, $paged );
	}

	/**
	 * Renders completion notices after a bulk action redirects back.
	 */
	private static function render_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice state parsed from query args after redirect.
		if ( ! isset( $_GET['ar_bulk'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = \is_string( $_GET['ar_bulk'] ) ? sanitize_key( wp_unslash( $_GET['ar_bulk'] ) ) : '';

		if ( $state === 'empty' ) {
			wp_admin_notice(
				esc_html__( 'No posts were selected.', 'advanced-revisions' ),
				[ 'type' => 'warning' ],
			);
			return;
		}

		if ( $state === 'done' ) {
			wp_admin_notice(
				self::done_notice_message(),
				[ 'type' => 'success' ],
			);
		}
	}

	/**
	 * Assembles the done-state notice message from the redirect's query args.
	 *
	 * Reports deletion count plus, when non-zero, the number of revisions
	 * skipped due to tag-based protection and the number of posts the current
	 * user wasn't authorized to edit. Integer-cast input is safe to interpolate
	 * into the translated sentence; no raw strings reach the output.
	 */
	private static function done_notice_message(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- parsed from redirect query args.
		$deleted = isset( $_GET['ar_deleted'] ) ? (int) $_GET['ar_deleted'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$skipped = isset( $_GET['ar_skipped'] ) ? (int) $_GET['ar_skipped'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$denied = isset( $_GET['ar_denied'] ) ? (int) $_GET['ar_denied'] : 0;

		$parts = [
			\sprintf(
				/* translators: %d: number of deleted revisions */
				esc_html__( 'Deleted %d revisions.', 'advanced-revisions' ),
				$deleted,
			),
		];
		if ( $skipped > 0 ) {
			$parts[] = \sprintf(
				/* translators: %d: number of protected revisions that were skipped */
				esc_html__( '%d protected revisions were skipped.', 'advanced-revisions' ),
				$skipped,
			);
		}
		if ( $denied > 0 ) {
			$parts[] = \sprintf(
				/* translators: %d: number of posts the user lacked permission to edit */
				esc_html__( '%d posts were skipped because you lack permission to edit them.', 'advanced-revisions' ),
				$denied,
			);
		}
		return \implode( ' ', $parts );
	}

	/**
	 * Renders the overview form body.
	 *
	 * @param array<int, array<string, mixed>> $rows  Overview rows from RevisionRepository.
	 * @param int                              $page  Current page (1-based).
	 * @param int                              $total Total parent posts with revisions (for paginator).
	 */
	private static function render_form( array $rows, int $page, int $total ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::MENU_SLUG ) . '" />';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		echo '<p><button type="submit" class="button button-primary" name="' . esc_attr( self::BULK_FIELD ) . '" value="delete">';
		echo esc_html__( 'Delete revisions for selected posts', 'advanced-revisions' );
		echo '</button></p>';

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<td class="manage-column column-cb check-column">';
		echo '<label class="screen-reader-text" for="cb-select-all-1">' . esc_html__( 'Select all posts', 'advanced-revisions' ) . '</label>';
		echo '<input type="checkbox" id="cb-select-all-1" />';
		echo '</td>';
		echo '<th scope="col">' . esc_html__( 'Title', 'advanced-revisions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Type', 'advanced-revisions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Revisions', 'advanced-revisions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Oldest', 'advanced-revisions' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row_data ) {
			self::render_row( $row_data );
		}

		echo '</tbody></table>';

		$total_pages = (int) \ceil( $total / RevisionRepository::DEFAULT_PER_PAGE );
		if ( $total_pages > 1 ) {
			self::render_pagination( $page, $total_pages );
		}

		echo '</form>';
	}

	/**
	 * Renders one row of the table.
	 *
	 * @param array<string, mixed> $row_data One row from RevisionRepository::paginated().
	 */
	private static function render_row( array $row_data ): void {
		$edit_link = (string) get_edit_post_link( $row_data['id'] );

		$checkbox_id = 'cb-select-' . (int) $row_data['id'];

		echo '<tr>';
		\printf(
			'<th class="check-column"><label class="screen-reader-text" for="%1$s">%2$s</label><input type="checkbox" id="%1$s" name="ar_parent_ids[]" value="%3$s" /></th>',
			esc_attr( $checkbox_id ),
			esc_html(
				\sprintf(
					/* translators: %s: post title */
					__( 'Select %s', 'advanced-revisions' ),
					$row_data['title'] !== '' ? $row_data['title'] : __( '(no title)', 'advanced-revisions' ),
				),
			),
			esc_attr( (string) $row_data['id'] ),
		);

		echo '<td>';
		if ( $edit_link !== '' ) {
			\printf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $edit_link ),
				esc_html( $row_data['title'] !== '' ? $row_data['title'] : __( '(no title)', 'advanced-revisions' ) ),
			);
		} else {
			echo esc_html( $row_data['title'] );
		}
		echo '</td>';

		echo '<td>' . esc_html( $row_data['post_type'] ) . '</td>';
		echo '<td>' . esc_html( (string) $row_data['revisions'] ) . '</td>';

		$oldest = $row_data['oldest_gmt'] !== '' ? $row_data['oldest_gmt'] : '—';
		echo '<td>' . esc_html( $oldest ) . '</td>';
		echo '</tr>';
	}

	/**
	 * Renders prev/next pagination links.
	 *
	 * @param int $page        Current 1-based page number.
	 * @param int $total_pages Total pages available.
	 */
	private static function render_pagination( int $page, int $total_pages ): void {
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo '<span class="displaying-num">' . esc_html(
			\sprintf(
				/* translators: 1: current page, 2: total pages */
				__( 'Page %1$d of %2$d', 'advanced-revisions' ),
				$page,
				$total_pages,
			),
		) . '</span> ';

		if ( $page > 1 ) {
			echo '<a class="button" href="' . esc_url( self::page_url( [ 'paged' => (string) ( $page - 1 ) ] ) ) . '">' . esc_html__( '« Prev', 'advanced-revisions' ) . '</a> ';
		}
		if ( $page < $total_pages ) {
			echo '<a class="button" href="' . esc_url( self::page_url( [ 'paged' => (string) ( $page + 1 ) ] ) ) . '">' . esc_html__( 'Next »', 'advanced-revisions' ) . '</a>';
		}
		echo '</div></div>';
	}

	/**
	 * Builds an absolute URL to the overview page with extra query args.
	 *
	 * @param array<string, string> $extra Additional query args.
	 */
	private static function page_url( array $extra = [] ): string {
		$args = \array_merge( [ 'page' => self::MENU_SLUG ], $extra );
		return add_query_arg( $args, admin_url( 'tools.php' ) );
	}

	/**
	 * Parses the selected parent post IDs from $_POST['ar_parent_ids'].
	 *
	 * @return array<int, int>
	 */
	private static function selected_parent_ids(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller (handle_bulk_post) verifies nonce before this runs.
		if ( ! isset( $_POST['ar_parent_ids'] ) || ! \is_array( $_POST['ar_parent_ids'] ) ) {
			return [];
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- values cast to int below; nonce verified by caller.
		$raw_ids = $_POST['ar_parent_ids'];

		$ids = [];
		foreach ( $raw_ids as $raw ) {
			if ( \is_scalar( $raw ) ) {
				$ids[] = (int) $raw;
			}
		}
		return \array_values( \array_filter( $ids, static fn( int $id ): bool => $id > 0 ) );
	}
}
