<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisionsDev\Tests\Unit\Fixtures;

use Apermo\AdvancedRevisionsDev\Fixtures\ContentGenerator;
use Apermo\AdvancedRevisionsDev\Fixtures\ContentSeeder;
use Apermo\AdvancedRevisionsDev\Fixtures\Marker;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ContentSeeder::seed(). The reset() path exercises WP_Query
 * which is awkward to stub in unit tests — it's covered by integration tests.
 */
final class ContentSeederTest extends TestCase {

	/**
	 * Captured wp_insert_post payloads from the active test.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $inserted = [];

	/**
	 * Captured update_post_meta calls from the active test.
	 *
	 * @var list<array{id:int,key:string,value:string}>
	 */
	private array $meta_updates = [];

	/**
	 * Post ID counter handed out by the stubbed wp_insert_post.
	 *
	 * @var int
	 */
	private int $next_post_id = 1;

	/**
	 * User ID counter handed out by the stubbed wp_insert_user.
	 *
	 * @var int
	 */
	private int $next_user_id = 100;

	/**
	 * Sets up Brain Monkey and default WP function stubs for every test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->inserted     = [];
		$this->meta_updates = [];
		$this->next_post_id = 1;
		$this->next_user_id = 100;

		Functions\when( 'wp_insert_post' )->alias(
			function ( array $data ): int {
				$id = $this->next_post_id;
				$this->next_post_id++;
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
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'wp_insert_user' )->alias(
			function ( array $args ): int {
				unset( $args );
				$id = $this->next_user_id;
				$this->next_user_id++;
				return $id;
			},
		);
		Functions\when( 'wp_generate_password' )->justReturn( 'fake-password-for-tests' );
	}

	/**
	 * Tears down Brain Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Seeder inserts the requested count of posts per target post type.
	 */
	public function test_seed_inserts_requested_count_per_post_type(): void {
		$seeder = new ContentSeeder( new ContentGenerator() );

		$totals = $seeder->seed(
			[
				'count'       => 3,
				'seed'        => 42,
				'authors'     => 2,
				'date_spread' => 30,
				'status_mix'  => 'publish',
				'post_types'  => [ 'ar_test_article', 'ar_test_page' ],
			],
		);

		self::assertSame(
			[
				'ar_test_article' => 3,
				'ar_test_page'    => 3,
			],
			$totals,
		);
		self::assertCount( 6, $this->inserted );
	}

	/**
	 * Every inserted post is tagged with the seeded-post marker meta.
	 */
	public function test_seed_tags_every_inserted_post_with_marker_meta(): void {
		$seeder = new ContentSeeder( new ContentGenerator() );

		$seeder->seed(
			[
				'count'       => 2,
				'seed'        => 42,
				'authors'     => 1,
				'date_spread' => 30,
				'status_mix'  => 'publish',
				'post_types'  => [ 'ar_test_article' ],
			],
		);

		self::assertCount( 2, $this->meta_updates );
		foreach ( $this->meta_updates as $update ) {
			self::assertSame( Marker::SEEDED_POST, $update['key'] );
			self::assertSame( Marker::YES, $update['value'] );
		}
	}

	/**
	 * The selected post type is passed through to wp_insert_post.
	 */
	public function test_seed_passes_correct_post_type_to_wp_insert_post(): void {
		$seeder = new ContentSeeder( new ContentGenerator() );

		$seeder->seed(
			[
				'count'       => 1,
				'seed'        => 42,
				'authors'     => 1,
				'date_spread' => 30,
				'status_mix'  => 'publish',
				'post_types'  => [ 'ar_test_product', 'ar_test_note' ],
			],
		);

		self::assertSame( 'ar_test_product', $this->inserted[0]['post_type'] );
		self::assertSame( 'ar_test_note', $this->inserted[1]['post_type'] );
	}

	/**
	 * Post status comes from the configured status-mix preset.
	 */
	public function test_seed_assigns_post_status_from_preset(): void {
		$seeder = new ContentSeeder( new ContentGenerator() );

		$seeder->seed(
			[
				'count'       => 5,
				'seed'        => 42,
				'authors'     => 1,
				'date_spread' => 30,
				'status_mix'  => 'publish',
				'post_types'  => [ 'ar_test_article' ],
			],
		);

		foreach ( $this->inserted as $post ) {
			self::assertSame( 'publish', $post['post_status'] );
		}
	}

	/**
	 * Missing authors are created via wp_insert_user.
	 */
	public function test_seed_creates_authors_when_none_exist(): void {
		$inserted_users = 0;
		Functions\when( 'wp_insert_user' )->alias(
			static function ( array $args ) use ( &$inserted_users ): int {
				unset( $args );
				$id = 100 + $inserted_users;
				$inserted_users++;
				return $id;
			},
		);

		$seeder = new ContentSeeder( new ContentGenerator() );
		$seeder->seed(
			[
				'count'       => 1,
				'seed'        => 42,
				'authors'     => 4,
				'date_spread' => 30,
				'status_mix'  => 'publish',
				'post_types'  => [ 'ar_test_article' ],
			],
		);

		self::assertSame( 4, $inserted_users );
	}

	/**
	 * When wp_insert_post silently returns 0, the post is skipped and no meta is written.
	 */
	public function test_seed_skips_posts_when_wp_insert_post_returns_error(): void {
		Functions\when( 'wp_insert_post' )->justReturn( 0 );

		$seeder = new ContentSeeder( new ContentGenerator() );
		$totals = $seeder->seed(
			[
				'count'       => 3,
				'seed'        => 42,
				'authors'     => 1,
				'date_spread' => 30,
				'status_mix'  => 'publish',
				'post_types'  => [ 'ar_test_article' ],
			],
		);

		self::assertSame( [ 'ar_test_article' => 0 ], $totals );
		self::assertSame( [], $this->meta_updates );
	}

	/**
	 * An empty post-types list returns an empty totals map without inserting anything.
	 */
	public function test_seed_with_empty_post_types_returns_empty(): void {
		$seeder = new ContentSeeder( new ContentGenerator() );

		$totals = $seeder->seed(
			[
				'count'       => 10,
				'seed'        => 42,
				'authors'     => 1,
				'date_spread' => 30,
				'status_mix'  => 'publish',
				'post_types'  => [],
			],
		);

		self::assertSame( [], $totals );
		self::assertSame( [], $this->inserted );
	}
}
