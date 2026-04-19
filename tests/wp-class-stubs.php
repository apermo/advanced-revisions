<?php
/**
 * Minimal stubs for WP classes used by code under unit test. Only loaded
 * when WordPress itself is not available (i.e. outside integration tests).
 *
 * @package Advanced_Revisions_Tests
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidClassName.InvalidClassName -- intentional: match core WP class names.
// phpcs:disable Squiz.Commenting.ClassComment.Missing -- stubs are private test scaffolding.
// phpcs:disable Apermo.NamingConventions.MinimumVariableNameLength.TooShort

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {

		public int $ID = 0; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

		public string $post_title = '';

		public string $post_content = '';

		public string $post_author = '0';

		public string $post_type = '';

		public string $post_status = '';

		public int $post_parent = 0;

		public string $post_date = '';

		public string $post_date_gmt = '';

		public string $post_name = '';

		public string $post_excerpt = '';

		public function __construct( mixed $post = null ) {
			unset( $post );
		}
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {

		public int $ID = 0; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

		public string $user_login = '';
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {

		/** @var array<int, mixed> */
		public array $posts = [];

		/**
		 * @param array<string, mixed> $args
		 */
		public function __construct( array $args = [] ) {
			unset( $args );
		}
	}
}
