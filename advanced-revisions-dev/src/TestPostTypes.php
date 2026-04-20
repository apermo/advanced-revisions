<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisionsDev;

/**
 * Registers test custom post types for exercising Advanced Revisions against a
 * realistic post-type matrix. Loaded only when the dev plugin is activated;
 * never present in production installs.
 */
final class TestPostTypes {

	/**
	 * Registers all test post types and their revisioned meta keys.
	 */
	public static function register(): void {
		foreach ( self::definitions() as $slug => $args ) {
			register_post_type( $slug, $args );
		}

		register_post_meta(
			'ar_test_product',
			'_ar_test_product_price',
			[
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			],
		);
	}

	/**
	 * Returns post type definitions keyed by slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function definitions(): array {
		return [
			'ar_test_article' => self::article(),
			'ar_test_page'    => self::page(),
			'ar_test_product' => self::product(),
			'ar_test_note'    => self::note(),
			'ar_test_private' => self::private_doc(),
		];
	}

	/**
	 * Defines a flat public post type with full revision support.
	 *
	 * @return array<string, mixed>
	 */
	private static function article(): array {
		return self::base( 'Articles', 'Article', 'ar_test_article' ) + [
			'hierarchical' => false,
			'supports'     => [ 'title', 'editor', 'excerpt', 'author', 'revisions' ],
		];
	}

	/**
	 * Defines a hierarchical public post type that exercises parent/child admin UX.
	 *
	 * @return array<string, mixed>
	 */
	private static function page(): array {
		return self::base( 'Test Pages', 'Test Page', 'ar_test_page' ) + [
			'hierarchical' => true,
			'supports'     => [ 'title', 'editor', 'page-attributes', 'revisions' ],
		];
	}

	/**
	 * Defines a post type with revisioned custom meta for exercising meta-revisioning (#9).
	 *
	 * @return array<string, mixed>
	 */
	private static function product(): array {
		return self::base( 'Products', 'Product', 'ar_test_product' ) + [
			'hierarchical' => false,
			'supports'     => [ 'title', 'editor', 'author', 'custom-fields', 'revisions' ],
		];
	}

	/**
	 * Defines a post type with revisions explicitly disabled — negative coverage.
	 *
	 * @return array<string, mixed>
	 */
	private static function note(): array {
		return self::base( 'Notes', 'Note', 'ar_test_note' ) + [
			'hierarchical' => false,
			'supports'     => [ 'title', 'editor' ],
		];
	}

	/**
	 * Defines a non-public post type that exercises capability gating.
	 *
	 * @return array<string, mixed>
	 */
	private static function private_doc(): array {
		return self::base( 'Private Docs', 'Private Doc', 'ar_test_private', false ) + [
			'publicly_queryable' => false,
			'hierarchical'       => false,
			'supports'           => [ 'title', 'editor', 'revisions' ],
		];
	}

	/**
	 * Returns shared defaults applied to every test post type.
	 *
	 * @param string $name            Plural label.
	 * @param string $singular        Singular label.
	 * @param string $capability_type Custom capability type so tests are not polluted by core-post caps.
	 * @param bool   $is_public       Whether the CPT is public; defaults to true.
	 * @return array<string, mixed>
	 */
	private static function base( string $name, string $singular, string $capability_type, bool $is_public = true ): array {
		return [
			'labels'          => [
				'name'          => $name,
				'singular_name' => $singular,
			],
			'public'          => $is_public,
			'show_ui'         => true,
			'show_in_menu'    => true,
			'show_in_rest'    => true,
			'capability_type' => $capability_type,
			'map_meta_cap'    => true,
		];
	}
}
