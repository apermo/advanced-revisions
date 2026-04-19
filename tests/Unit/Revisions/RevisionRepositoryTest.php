<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisions\Tests\Unit\Revisions;

use Apermo\AdvancedRevisions\Revisions\RevisionRepository;
use Apermo\AdvancedRevisions\Tests\Fixtures\WpdbDouble;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Covers RevisionRepository with unit tests. Stubs a tiny $wpdb double so the
 * SQL-execution paths are covered without a real DB.
 */
final class RevisionRepositoryTest extends TestCase {

	/**
	 * Installs a tiny $wpdb double.
	 */
	protected function setUp(): void {
		parent::setUp();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride -- test-local wpdb stub.
		$GLOBALS['wpdb'] = new WpdbDouble();
	}

	/**
	 * Removes the $wpdb double.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Asserts the paginated helper returns an empty array when $wpdb is not initialized.
	 */
	public function test_paginated_returns_empty_without_wpdb(): void {
		unset( $GLOBALS['wpdb'] );

		self::assertSame( [], ( new RevisionRepository() )->paginated() );
	}

	/**
	 * Asserts the paginated helper maps $wpdb->get_results output into the overview row shape.
	 */
	public function test_paginated_maps_db_rows_to_overview_shape(): void {
		// phpcs:ignore SlevomatCodingStandard.PHP.ForbiddenClasses.ForbiddenClass
		$row_data                 = new stdClass();
		$row_data->ID             = 42;
		$row_data->post_title     = 'Hello';
		$row_data->post_type      = 'post';
		$row_data->post_author    = 3;
		$row_data->revision_count = 12;
		$row_data->oldest_gmt     = '2024-01-01 00:00:00';

		$GLOBALS['wpdb']->next_results = [ $row_data ];

		$rows = ( new RevisionRepository() )->paginated();

		self::assertCount( 1, $rows );
		self::assertSame( 42, $rows[0]['id'] );
		self::assertSame( 'Hello', $rows[0]['title'] );
		self::assertSame( 'post', $rows[0]['post_type'] );
		self::assertSame( 3, $rows[0]['author'] );
		self::assertSame( 12, $rows[0]['revisions'] );
		self::assertSame( '2024-01-01 00:00:00', $rows[0]['oldest_gmt'] );
	}

	/**
	 * Asserts the paginated helper clamps per_page into a safe range.
	 */
	public function test_paginated_clamps_per_page(): void {
		$GLOBALS['wpdb']->next_results = [];

		self::assertSame( [], ( new RevisionRepository() )->paginated( 0, 1 ) );
		self::assertSame( [], ( new RevisionRepository() )->paginated( 99_999, 1 ) );
	}

	/**
	 * Asserts the total_parents helper returns 0 when $wpdb is not available.
	 */
	public function test_total_parents_returns_zero_without_wpdb(): void {
		unset( $GLOBALS['wpdb'] );

		self::assertSame( 0, ( new RevisionRepository() )->total_parents() );
	}

	/**
	 * Asserts the total_parents helper returns the DB count as int.
	 */
	public function test_total_parents_returns_db_count(): void {
		$GLOBALS['wpdb']->next_var = 17;

		self::assertSame( 17, ( new RevisionRepository() )->total_parents() );
	}

	/**
	 * Asserts the revision_ids_for_parents helper short-circuits on empty input.
	 */
	public function test_revision_ids_for_parents_returns_empty_for_empty_input(): void {
		self::assertSame( [], ( new RevisionRepository() )->revision_ids_for_parents( [] ) );
	}

	/**
	 * Asserts the revision_ids_for_parents helper returns an int list from $wpdb->get_col.
	 */
	public function test_revision_ids_for_parents_casts_db_values_to_int(): void {
		$GLOBALS['wpdb']->next_col = [ '101', '102', 103 ];

		$ids = ( new RevisionRepository() )->revision_ids_for_parents( [ 10, 11 ] );

		self::assertSame( [ 101, 102, 103 ], $ids );
	}

	/**
	 * Asserts a missing $wpdb on revision_ids_for_parents returns an empty list.
	 */
	public function test_revision_ids_for_parents_returns_empty_without_wpdb(): void {
		unset( $GLOBALS['wpdb'] );

		self::assertSame( [], ( new RevisionRepository() )->revision_ids_for_parents( [ 1 ] ) );
	}
}
