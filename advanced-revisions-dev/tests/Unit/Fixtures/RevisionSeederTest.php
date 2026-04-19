<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisionsDev\Tests\Unit\Fixtures;

use Apermo\AdvancedRevisionsDev\Fixtures\Marker;
use Apermo\AdvancedRevisionsDev\Fixtures\RevisionGenerator;
use Apermo\AdvancedRevisionsDev\Fixtures\RevisionSeeder;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * Unit tests for RevisionSeeder::seed(). reset() uses WP_Query and is covered
 * by integration tests.
 */
final class RevisionSeederTest extends TestCase {

	/**
	 * Captured wp_insert_post payloads.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $inserted = [];

	/**
	 * Captured update_post_meta calls.
	 *
	 * @var list<array{id:int,key:string,value:string}>
	 */
	private array $meta_updates = [];

	/**
	 * Row ID counter.
	 *
	 * @var int
	 */
	private int $next_id = 9001;

	/**
	 * Set up Brain Monkey and default WP function stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->inserted     = [];
		$this->meta_updates = [];
		$this->next_id      = 9001;

		Functions\when( 'wp_insert_post' )->alias(
			function ( array $data ): int {
				$id = $this->next_id;
				$this->next_id++;
				$data['_assigned_id'] = $id;
				$this->inserted[]     = $data;
				return $id;
			},
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'update_post_meta' )->alias(
			function ( int $id, string $key, string $value ): void {
				$this->meta_updates[] = [
					'id'    => $id,
					'key'   => $key,
					'value' => $value,
				];
			},
		);
		Functions\when( 'get_post' )->alias(
			// phpcs:ignore Universal.FunctionDeclarations.NoLongClosures.ExceedsMaximum
			static function ( int $id ): WP_Post {
				$post               = new WP_Post();
				$post->ID           = $id;
				$post->post_type    = 'ar_test_article';
				$post->post_title   = 'Parent #' . $id;
				$post->post_content = 'Parent body #' . $id;
				$post->post_author  = '1';
				return $post;
			},
		);
	}

	/**
	 * Tear down Brain Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Every inserted revision row carries the seeded-revision marker meta.
	 */
	public function test_seed_tags_rows_with_marker_meta(): void {
		$seeder = new RevisionSeeder( new RevisionGenerator() );

		$seeder->seed(
			[ 100 ],
			[
				'distribution'   => 'normal',
				'seed'           => 42,
				'spread_days'    => 30,
				'autosave_ratio' => 0.0,
				'orphan_count'   => 0,
			],
		);

		self::assertCount( \count( $this->inserted ), $this->meta_updates );
		foreach ( $this->meta_updates as $update ) {
			self::assertSame( Marker::SEEDED_REVISION, $update['key'] );
			self::assertSame( Marker::YES, $update['value'] );
		}
	}

	/**
	 * Distribution preset bounds the revision count for each post.
	 */
	public function test_sparse_distribution_produces_at_most_five_rows_per_post(): void {
		$seeder = new RevisionSeeder( new RevisionGenerator() );

		$stats = $seeder->seed(
			[ 100 ],
			[
				'distribution'   => 'sparse',
				'seed'           => 42,
				'spread_days'    => 30,
				'autosave_ratio' => 0.0,
				'orphan_count'   => 0,
			],
		);

		self::assertGreaterThanOrEqual( 0, $stats['revisions'] );
		self::assertLessThanOrEqual( 5, $stats['revisions'] );
	}

	/**
	 * Autosave ratio routes some rows to autosave post_name pattern.
	 */
	public function test_autosave_ratio_produces_autosave_suffix_names(): void {
		$seeder = new RevisionSeeder( new RevisionGenerator() );

		$seeder->seed(
			[ 100 ],
			[
				'distribution'   => 'heavy',
				'seed'           => 42,
				'spread_days'    => 30,
				'autosave_ratio' => 0.5,
				'orphan_count'   => 0,
			],
		);

		$has_autosave_name = false;
		foreach ( $this->inserted as $row_data ) {
			if ( \str_contains( (string) $row_data['post_name'], '-autosave-v' ) ) {
				$has_autosave_name = true;
				break;
			}
		}

		self::assertTrue( $has_autosave_name, 'Expected at least one autosave row with -autosave-v post_name' );
	}

	/**
	 * Every non-orphan row links to the correct parent post ID.
	 */
	public function test_seed_uses_post_type_revision_and_inherit_status(): void {
		$seeder = new RevisionSeeder( new RevisionGenerator() );

		$seeder->seed(
			[ 100 ],
			[
				'distribution'   => 'normal',
				'seed'           => 42,
				'spread_days'    => 30,
				'autosave_ratio' => 0.0,
				'orphan_count'   => 0,
			],
		);

		foreach ( $this->inserted as $row_data ) {
			self::assertSame( 'revision', $row_data['post_type'] );
			self::assertSame( 'inherit', $row_data['post_status'] );
			self::assertSame( 100, $row_data['post_parent'] );
		}
	}

	/**
	 * Orphan count produces that many rows with a non-existent post_parent.
	 */
	public function test_orphan_count_creates_orphan_rows(): void {
		$seeder = new RevisionSeeder( new RevisionGenerator() );

		$stats = $seeder->seed(
			[],
			[
				'distribution'   => 'normal',
				'seed'           => 42,
				'spread_days'    => 30,
				'autosave_ratio' => 0.0,
				'orphan_count'   => 7,
			],
		);

		self::assertSame( 7, $stats['orphans'] );
		$orphan_rows = \array_filter(
			$this->inserted,
			static fn( array $row_data ): bool => (int) $row_data['post_parent'] >= 999_000_000,
		);
		self::assertCount( 7, $orphan_rows );
	}

	/**
	 * Posts with no matching get_post() (nullable return) are skipped silently.
	 */
	public function test_seed_skips_missing_posts(): void {
		Functions\when( 'get_post' )->justReturn( null );

		$seeder = new RevisionSeeder( new RevisionGenerator() );
		$stats  = $seeder->seed(
			[ 100, 200 ],
			[
				'distribution'   => 'normal',
				'seed'           => 42,
				'spread_days'    => 30,
				'autosave_ratio' => 0.0,
				'orphan_count'   => 0,
			],
		);

		self::assertSame( 0, $stats['revisions'] );
		self::assertSame( 0, $stats['autosaves'] );
		self::assertSame( [], $this->inserted );
	}
}
