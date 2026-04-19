<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisions\Admin;

use Apermo\AdvancedRevisions\Revisions\LimitService;
use WP_Post;

/**
 * Adds a "Revision retention" meta box to the post edit screen. Lets editors
 * override the per-post-type limit for one specific post — useful for
 * high-churn landing pages or legally-sensitive content.
 *
 * Precedence: per-post override (this class) > per-type limit (Settings page)
 * > WP_POST_REVISIONS fallback. See {@see LimitService::filter_revisions_to_keep()}.
 */
final class RevisionLimitMetaBox {

	public const NONCE_ACTION = 'advanced_revisions_override_save';

	public const NONCE_NAME = 'advanced_revisions_override_nonce';

	public const CAPABILITY = 'edit_others_posts';

	/**
	 * Register the add/save hooks and expose the meta to REST.
	 */
	public static function register(): void {
		add_action( 'add_meta_boxes', [ self::class, 'add_meta_box' ] );
		add_action( 'save_post', [ self::class, 'save' ], 10, 2 );
		add_action( 'init', [ self::class, 'register_post_meta' ] );
	}

	/**
	 * Expose the override meta to REST so block-editor clients can manage it.
	 */
	public static function register_post_meta(): void {
		foreach ( self::revisable_post_types() as $post_type ) {
			register_post_meta(
				$post_type,
				LimitService::PER_POST_META_KEY,
				[
					'type'          => 'integer',
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => static fn(): bool => current_user_can( self::CAPABILITY ),
				],
			);
		}
	}

	/**
	 * Add the meta box to every revision-supporting public post type.
	 */
	public static function add_meta_box(): void {
		foreach ( self::revisable_post_types() as $post_type ) {
			add_meta_box(
				'advanced_revisions_override',
				__( 'Revision retention', 'advanced-revisions' ),
				[ self::class, 'render' ],
				$post_type,
				'side',
				'low',
			);
		}
	}

	/**
	 * Render the meta box body.
	 *
	 * @param WP_Post $post Current post.
	 */
	public static function render( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$current    = LimitService::per_post_override( $post->ID );
		$value_attr = $current === null ? '' : (string) $current;

		\printf(
			'<p><label for="%1$s">%2$s</label></p>',
			esc_attr( LimitService::PER_POST_META_KEY ),
			esc_html__( 'Override the revision limit for this post:', 'advanced-revisions' ),
		);
		\printf(
			'<input type="number" min="-1" step="1" id="%1$s" name="%1$s" value="%2$s" class="small-text" />',
			esc_attr( LimitService::PER_POST_META_KEY ),
			esc_attr( $value_attr ),
		);
		echo '<p class="description">';
		echo esc_html__( 'Leave blank to use the post type default. -1 = unlimited, 0 = disable revisions for this post.', 'advanced-revisions' );
		echo '</p>';
	}

	/**
	 * Persist the submitted override on save_post.
	 *
	 * @param int     $post_id Post being saved.
	 * @param WP_Post $post    Post object.
	 */
	public static function save( int $post_id, WP_Post $post ): void {
		if ( \defined( 'DOING_AUTOSAVE' ) && \DOING_AUTOSAVE ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_NAME ] ) && \is_string( $_POST[ self::NONCE_NAME ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) )
			: '';
		if ( $nonce === '' || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY, $post_id ) ) {
			return;
		}

		if ( ! \array_key_exists( LimitService::PER_POST_META_KEY, $_POST ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- numeric value; coerced to int via (int) cast before storage (line 137).
		$raw_value = $_POST[ LimitService::PER_POST_META_KEY ];
		$raw       = \is_scalar( $raw_value )
			? (string) $raw_value
			: '';

		unset( $post );

		if ( \trim( $raw ) === '' ) {
			delete_post_meta( $post_id, LimitService::PER_POST_META_KEY );
			return;
		}

		update_post_meta(
			$post_id,
			LimitService::PER_POST_META_KEY,
			\max( LimitService::UNLIMITED, (int) $raw ),
		);
	}

	/**
	 * Public post types that support revisions.
	 *
	 * @return array<int, string>
	 */
	private static function revisable_post_types(): array {
		$types = [];
		foreach ( get_post_types( [ 'public' => true ], 'names' ) as $post_type ) {
			if ( post_type_supports( $post_type, 'revisions' ) ) {
				$types[] = $post_type;
			}
		}
		return $types;
	}
}
