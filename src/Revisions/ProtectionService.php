<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisions\Revisions;

/**
 * Central protection check consumed by every cleanup path (#3, #5, #6, #7).
 *
 * Given a list of revision IDs, {@see self::filter_deletable()} returns the
 * subset that is NOT wearing a tag with the `protected` term meta flag set.
 *
 * Protection is a tag attribute (not a per-revision flag) so policy is
 * declarative: toggle `protected` on a tag and every revision wearing it
 * becomes safe immediately; untoggle and they're eligible again.
 */
final class ProtectionService {

	/**
	 * Return the subset of $revision_ids that is safe to delete.
	 *
	 * An ID is removed from the input when at least one tag attached to it
	 * has its `protected` term meta set to a truthy value.
	 *
	 * @param array<int, int> $revision_ids Revision post IDs to filter.
	 * @return array<int, int> Same IDs, minus any that are protected.
	 */
	public static function filter_deletable( array $revision_ids ): array {
		if ( $revision_ids === [] ) {
			return [];
		}

		$protected = self::protected_revision_ids( $revision_ids );
		if ( $protected === [] ) {
			return \array_values( $revision_ids );
		}

		return \array_values(
			\array_filter(
				$revision_ids,
				static fn( int $id ): bool => ! \in_array( $id, $protected, true ),
			),
		);
	}

	/**
	 * How many of the given IDs are protected. Handy for confirmation UIs.
	 *
	 * @param array<int, int> $revision_ids Revision IDs to count.
	 */
	public static function count_protected( array $revision_ids ): int {
		if ( $revision_ids === [] ) {
			return 0;
		}
		return \count( self::protected_revision_ids( $revision_ids ) );
	}

	/**
	 * Return the subset of IDs that are currently protected.
	 *
	 * Strategy: walk each ID, look up its revision_tag terms, and if any of
	 * those terms has the `protected` term meta set truthy, flag the ID.
	 *
	 * @param array<int, int> $revision_ids Revision IDs to check.
	 * @return array<int, int> Protected subset.
	 */
	private static function protected_revision_ids( array $revision_ids ): array {
		$protected = [];

		foreach ( $revision_ids as $revision_id ) {
			if ( self::is_revision_protected( $revision_id ) ) {
				$protected[] = $revision_id;
			}
		}

		return $protected;
	}

	/**
	 * True when this revision wears at least one tag with the protected flag.
	 *
	 * @param int $revision_id Revision post ID.
	 */
	private static function is_revision_protected( int $revision_id ): bool {
		$terms = wp_get_object_terms( $revision_id, TaxonomyRegistrar::TAXONOMY, [ 'fields' => 'ids' ] );

		if ( ! \is_array( $terms ) || $terms === [] ) {
			return false;
		}

		foreach ( $terms as $term_id ) {
			$flag = get_term_meta( $term_id, TaxonomyRegistrar::PROTECTED_META, true );
			if ( (bool) $flag ) {
				return true;
			}
		}

		return false;
	}
}
