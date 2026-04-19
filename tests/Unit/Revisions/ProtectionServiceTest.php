<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisions\Tests\Unit\Revisions;

use Apermo\AdvancedRevisions\Revisions\ProtectionService;
use Apermo\AdvancedRevisions\Revisions\TaxonomyRegistrar;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for ProtectionService::filter_deletable().
 */
final class ProtectionServiceTest extends TestCase {

	/**
	 * Set up Brain Monkey.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * Tear down Brain Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stub revision→term and term→protected maps.
	 *
	 * @param array<int, array<int, int>> $terms_by_revision   revision_id → list of term IDs.
	 * @param array<int, bool>            $protected_by_term   term_id → protected flag.
	 */
	private function stub_tags( array $terms_by_revision, array $protected_by_term ): void {
		Functions\when( 'wp_get_object_terms' )->alias(
			// phpcs:ignore Universal.FunctionDeclarations.NoLongClosures.ExceedsMaximum
			static function ( array $object_ids, string $taxonomy, array $args ) use ( $terms_by_revision ): array {
				unset( $taxonomy, $args );
				$rows = [];
				foreach ( $object_ids as $object_id ) {
					foreach ( $terms_by_revision[ $object_id ] ?? [] as $term_id ) {
						// phpcs:ignore SlevomatCodingStandard.PHP.ForbiddenClasses.ForbiddenClass
						$row_data            = new stdClass();
						$row_data->object_id = $object_id;
						$row_data->term_id   = $term_id;
						$rows[]              = $row_data;
					}
				}
				return $rows;
			},
		);
		Functions\when( 'get_term_meta' )->alias(
			static function ( int $term_id, string $key, bool $single ) use ( $protected_by_term ): bool {
				unset( $key, $single );
				return $protected_by_term[ $term_id ] ?? false;
			},
		);
	}

	/**
	 * Empty input returns empty array without hitting the DB.
	 */
	public function test_filter_deletable_returns_empty_for_empty_input(): void {
		self::assertSame( [], ProtectionService::filter_deletable( [] ) );
	}

	/**
	 * Revisions without any tags are always deletable.
	 */
	public function test_filter_deletable_keeps_untagged_revisions(): void {
		$this->stub_tags( [], [] );

		$result = ProtectionService::filter_deletable( [ 1, 2, 3 ] );

		self::assertSame( [ 1, 2, 3 ], $result );
	}

	/**
	 * Revisions tagged only with non-protected tags are deletable.
	 */
	public function test_filter_deletable_keeps_non_protected_tagged_revisions(): void {
		$this->stub_tags(
			[
				10 => [ 100, 101 ],
				11 => [ 101 ],
			],
			[
				100 => false,
				101 => false,
			],
		);

		$result = ProtectionService::filter_deletable( [ 10, 11 ] );

		self::assertSame( [ 10, 11 ], $result );
	}

	/**
	 * Revisions wearing at least one protected tag are removed.
	 */
	public function test_filter_deletable_drops_protected_revisions(): void {
		$this->stub_tags(
			[
				10 => [ 100 ],
				11 => [ 101 ],
				12 => [ 100, 102 ],
			],
			[
				100 => false,
				101 => true,
				102 => false,
			],
		);

		$result = ProtectionService::filter_deletable( [ 10, 11, 12 ] );

		self::assertSame( [ 10, 12 ], $result );
	}

	/**
	 * A revision with a mix of protected and unprotected tags stays protected.
	 */
	public function test_filter_deletable_respects_any_protected_tag(): void {
		$this->stub_tags(
			[ 10 => [ 100, 101 ] ],
			[
				100 => false,
				101 => true,
			],
		);

		$result = ProtectionService::filter_deletable( [ 10 ] );

		self::assertSame( [], $result );
	}

	/**
	 * Count helper reports how many IDs are protected (useful for UI).
	 */
	public function test_count_protected_reports_how_many_ids_are_protected(): void {
		$this->stub_tags(
			[
				10 => [ 100 ],
				11 => [ 101 ],
				12 => [ 102 ],
			],
			[
				100 => true,
				101 => false,
				102 => true,
			],
		);

		self::assertSame( 2, ProtectionService::count_protected( [ 10, 11, 12 ] ) );
	}

	/**
	 * Count helper uses the same taxonomy constant as the service itself.
	 */
	public function test_taxonomy_constant_matches_registrar(): void {
		self::assertSame( TaxonomyRegistrar::TAXONOMY, 'revision_tag' );
	}
}
