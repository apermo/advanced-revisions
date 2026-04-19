<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisions\Revisions;

/**
 * Registers the `revision_tag` taxonomy against the `revision` post type plus
 * its `protected` term-meta flag, and the per-revision note meta.
 *
 * Tags and the protection flag power {@see ProtectionService}, which every
 * cleanup path (#3, #5, #6, #7) calls before deleting rows.
 */
final class TaxonomyRegistrar {

	public const TAXONOMY       = 'revision_tag';
	public const PROTECTED_META = 'protected';
	public const NOTE_META      = '_advanced_revisions_note';

	/**
	 * Register the taxonomy, term meta, and post meta on init.
	 */
	public static function register(): void {
		add_action( 'init', [ self::class, 'register_taxonomy' ] );
		add_action( 'init', [ self::class, 'register_meta' ] );
	}

	/**
	 * Register the custom taxonomy against the built-in `revision` post type.
	 */
	public static function register_taxonomy(): void {
		register_taxonomy(
			self::TAXONOMY,
			'revision',
			// phpcs:ignore Apermo.DataStructures.ArrayComplexity.TooManyKeys -- register_taxonomy args are a WP API shape.
			[
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => false,
				'show_admin_column' => false,
				'show_in_rest'      => true,
				'rewrite'           => false,
				'hierarchical'      => false,
				'labels'            => self::labels(),
				'capabilities'      => [
					'manage_terms' => 'manage_options',
					'edit_terms'   => 'manage_options',
					'delete_terms' => 'manage_options',
					'assign_terms' => 'edit_others_posts',
				],
			],
		);
	}

	/**
	 * Register term meta for the `protected` flag and post meta for per-revision notes.
	 */
	public static function register_meta(): void {
		register_term_meta(
			self::TAXONOMY,
			self::PROTECTED_META,
			[
				'type'              => 'boolean',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => static fn( $value ): bool => (bool) $value,
			],
		);

		register_post_meta(
			'revision',
			self::NOTE_META,
			[
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
		);
	}

	/**
	 * Human-readable labels for the taxonomy.
	 *
	 * @return array<string, string>
	 */
	private static function labels(): array {
		// phpcs:disable Apermo.WordPress.NoHardcodedTableNames.Found -- these are UI labels, not SQL.
		// phpcs:ignore Apermo.DataStructures.ArrayComplexity.TooManyKeys -- taxonomy labels are a WP API shape.
		return [
			'name'          => __( 'Revision Tags', 'advanced-revisions' ),
			'singular_name' => __( 'Revision Tag', 'advanced-revisions' ),
			'search_items'  => __( 'Search Revision Tags', 'advanced-revisions' ),
			'all_items'     => __( 'All Revision Tags', 'advanced-revisions' ),
			'edit_item'     => __( 'Edit Revision Tag', 'advanced-revisions' ),
			'update_item'   => __( 'Update Revision Tag', 'advanced-revisions' ),
			'add_new_item'  => __( 'Add New Revision Tag', 'advanced-revisions' ),
			'new_item_name' => __( 'New Revision Tag Name', 'advanced-revisions' ),
			'menu_name'     => __( 'Revision Tags', 'advanced-revisions' ),
		];
	}
}
