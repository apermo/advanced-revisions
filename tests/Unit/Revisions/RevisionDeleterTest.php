<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisions\Tests\Unit\Revisions;

use Apermo\AdvancedRevisions\Revisions\RevisionDeleter;
use Apermo\AdvancedRevisions\Revisions\RevisionRepository;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for RevisionDeleter — verifies protection is honored and deletion
 * counts are reported accurately.
 */
final class RevisionDeleterTest extends TestCase {

	/**
	 * Sets up Brain Monkey.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * Tears down Brain Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Builds a repository stub that returns a fixed set of revision IDs per parent.
	 *
	 * @param array<int, int> $revision_ids Revision IDs to return.
	 */
	private function stub_repository( array $revision_ids ): RevisionRepository {
		$stub = $this->createMock( RevisionRepository::class );
		$stub->method( 'revision_ids_for_parents' )->willReturn( $revision_ids );
		return $stub;
	}

	/**
	 * Walks each revision ID during deletion and increments the count for successes.
	 */
	public function test_delete_counts_successful_deletions(): void {
		// No tags → nothing protected.
		Functions\when( 'wp_get_object_terms' )->justReturn( [] );
		Functions\when( 'wp_delete_post_revision' )->justReturn( 1 );

		$deleter = new RevisionDeleter( $this->stub_repository( [ 10, 11, 12 ] ) );

		$result = $deleter->delete_for_parents( [ 100 ] );

		self::assertSame(
			[
				'deleted' => 3,
				'skipped' => 0,
			],
			$result,
		);
	}

	/**
	 * Skips revisions that return false from wp_delete_post_revision.
	 */
	public function test_delete_skips_rows_that_fail_to_delete(): void {
		Functions\when( 'wp_get_object_terms' )->justReturn( [] );
		Functions\when( 'wp_delete_post_revision' )->alias(
			static fn( int $id ): mixed => $id === 11 ? false : 1,
		);

		$deleter = new RevisionDeleter( $this->stub_repository( [ 10, 11, 12 ] ) );

		$result = $deleter->delete_for_parents( [ 100 ] );

		self::assertSame( 2, $result['deleted'] );
	}

	/**
	 * Skips protected revisions and reports them.
	 */
	public function test_delete_respects_protection_flags(): void {
		Functions\when( 'wp_get_object_terms' )->alias(
			// phpcs:ignore Universal.FunctionDeclarations.NoLongClosures.ExceedsMaximum
			static function ( array $ids ): array {
				$rows = [];
				if ( \in_array( 11, $ids, true ) ) {
					// phpcs:ignore SlevomatCodingStandard.PHP.ForbiddenClasses.ForbiddenClass
					$row_data            = new stdClass();
					$row_data->object_id = 11;
					$row_data->term_id   = 500;
					$rows[]              = $row_data;
				}
				return $rows;
			},
		);
		Functions\when( 'get_term_meta' )->justReturn( true );
		Functions\when( 'wp_delete_post_revision' )->justReturn( 1 );

		$deleter = new RevisionDeleter( $this->stub_repository( [ 10, 11, 12 ] ) );

		$result = $deleter->delete_for_parents( [ 100 ] );

		self::assertSame(
			[
				'deleted' => 2,
				'skipped' => 1,
			],
			$result,
		);
	}

	/**
	 * Reports deletable + protected counts via preview without calling wp_delete_post_revision.
	 */
	public function test_preview_reports_counts_without_deleting(): void {
		Functions\when( 'wp_get_object_terms' )->alias(
			// phpcs:ignore Universal.FunctionDeclarations.NoLongClosures.ExceedsMaximum
			static function ( array $ids ): array {
				$rows = [];
				if ( \in_array( 10, $ids, true ) ) {
					// phpcs:ignore SlevomatCodingStandard.PHP.ForbiddenClasses.ForbiddenClass
					$row_data            = new stdClass();
					$row_data->object_id = 10;
					$row_data->term_id   = 500;
					$rows[]              = $row_data;
				}
				return $rows;
			},
		);
		Functions\when( 'get_term_meta' )->justReturn( true );
		Functions\expect( 'wp_delete_post_revision' )->never();

		$deleter = new RevisionDeleter( $this->stub_repository( [ 10, 11, 12 ] ) );

		$result = $deleter->preview( [ 100 ] );

		self::assertSame(
			[
				'deletable' => 2,
				'protected' => 1,
			],
			$result,
		);
	}
}
