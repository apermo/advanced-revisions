<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisions\Tests\Unit\Admin;

use Apermo\AdvancedRevisions\Admin\PostListColumn;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use WP_Post;

/**
 * Tests for PostListColumn — focus on the column insertion logic, row-action
 * assembly, and URL building. SQL aggregation is exercised in integration.
 */
final class PostListColumnTest extends TestCase {

	/**
	 * Sets up Brain Monkey + reset the per-request cache.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		PostListColumn::reset_cache();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'wp_get_post_revisions' )->justReturn( [] );
		Functions\when( 'get_edit_post_link' )->justReturn( '' );
		Functions\when( 'admin_url' )->alias(
			static fn( string $path = '' ): string => 'https://example.tld/wp-admin/' . $path,
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string {
				return $url . '?' . \http_build_query( $args );
			},
		);
	}

	/**
	 * Tears down Brain Monkey, resets cache, and clears $wpdb.
	 */
	protected function tearDown(): void {
		PostListColumn::reset_cache();
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The add_column helper appends when no 'date' column exists.
	 */
	public function test_add_column_appends_when_date_absent(): void {
		$columns = [
			'cb'    => '<input />',
			'title' => 'Title',
		];

		$result = PostListColumn::add_column( $columns );

		self::assertArrayHasKey( PostListColumn::COLUMN_KEY, $result );
		self::assertSame(
			[ 'cb', 'title', PostListColumn::COLUMN_KEY ],
			\array_keys( $result ),
		);
	}

	/**
	 * The add_column helper inserts immediately after 'date' when present.
	 */
	public function test_add_column_inserts_after_date(): void {
		$columns = [
			'cb'    => '<input />',
			'title' => 'Title',
			'date'  => 'Date',
		];

		$result = PostListColumn::add_column( $columns );

		self::assertSame(
			[ 'cb', 'title', 'date', PostListColumn::COLUMN_KEY ],
			\array_keys( $result ),
		);
	}

	/**
	 * Row action is skipped for posts with zero revisions so the row stays clean.
	 */
	public function test_add_row_action_skips_zero_count_posts(): void {
		Functions\when( 'admin_url' )->justReturn( 'https://example.tld/wp-admin/revision.php' );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.tld/wp-admin/revision.php?from=42&to=42' );

		$post     = new WP_Post();
		$post->ID = 42;

		$actions = PostListColumn::add_row_action( [ 'edit' => 'Edit' ], $post );

		self::assertSame( [ 'edit' => 'Edit' ], $actions );
	}

	/**
	 * Row action appends the Manage revisions link when the count is positive.
	 */
	public function test_add_row_action_appends_link_when_revisions_exist(): void {
		// Inject a non-zero count without running SQL by priming the cache
		// via reflection of the static property.
		$reflection = new ReflectionClass( PostListColumn::class );
		$prop       = $reflection->getProperty( 'counts' );
		$prop->setValue( null, [ 42 => 7 ] );

		$post     = new WP_Post();
		$post->ID = 42;

		$actions = PostListColumn::add_row_action( [ 'edit' => 'Edit' ], $post );

		self::assertArrayHasKey( 'advanced_revisions_manage', $actions );
		self::assertStringContainsString( 'Manage revisions', $actions['advanced_revisions_manage'] );
	}

	/**
	 * The compare_url helper links to revision.php with a real revision ID.
	 */
	public function test_compare_url_builds_admin_revision_php_url(): void {
		$revision     = new WP_Post();
		$revision->ID = 9001;
		Functions\when( 'wp_get_post_revisions' )->justReturn( [ $revision ] );

		$url = PostListColumn::compare_url( 42 );

		self::assertStringContainsString( 'revision.php', $url );
		self::assertStringContainsString( 'revision=9001', $url );
	}

	/**
	 * The count_for helper returns 0 when no count is cached for a post.
	 */
	public function test_count_for_returns_zero_when_unknown(): void {
		// Isolate: ensure no $wpdb is leaking across tests.
		unset( $GLOBALS['wpdb'] );

		self::assertSame( 0, PostListColumn::count_for( 999 ) );
	}
}
