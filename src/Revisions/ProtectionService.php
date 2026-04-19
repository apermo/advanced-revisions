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

		$protected_lookup = \array_flip( self::protected_revision_ids( $revision_ids ) );
		if ( $protected_lookup === [] ) {
			return \array_values( $revision_ids );
		}

		return \array_values(
			\array_filter(
				$revision_ids,
				static fn( int $id ): bool => ! isset( $protected_lookup[ $id ] ),
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
	 * Strategy:
	 * 1. Batch-fetch revision→term relationships (single DB hit via wp_get_object_terms).
	 * 2. Build a map of term_id → protected flag by priming term meta once.
	 * 3. Walk the input, marking any revision that wears a protected term.
	 *
	 * @param array<int, int> $revision_ids Revision IDs to check.
	 * @return array<int, int> Protected subset.
	 */
	private static function protected_revision_ids( array $revision_ids ): array {
		$terms_by_revision = self::terms_by_revision( $revision_ids );
		if ( $terms_by_revision === [] ) {
			return [];
		}

		$unique_term_ids = self::unique_term_ids( $terms_by_revision );
		$protected_terms = self::protected_term_lookup( $unique_term_ids );
		if ( $protected_terms === [] ) {
			return [];
		}

		$protected = [];
		foreach ( $terms_by_revision as $revision_id => $term_ids ) {
			foreach ( $term_ids as $term_id ) {
				if ( isset( $protected_terms[ $term_id ] ) ) {
					$protected[] = $revision_id;
					break;
				}
			}
		}
		return $protected;
	}

	/**
	 * Fetch term IDs for each revision in one call. Revisions without any
	 * revision_tag terms are omitted from the result.
	 *
	 * @param array<int, int> $revision_ids Revision IDs.
	 * @return array<int, array<int, int>>
	 */
	private static function terms_by_revision( array $revision_ids ): array {
		$result = [];
		foreach ( $revision_ids as $revision_id ) {
			$terms = wp_get_object_terms(
				$revision_id,
				TaxonomyRegistrar::TAXONOMY,
				[ 'fields' => 'ids' ],
			);
			if ( ! \is_array( $terms ) || $terms === [] ) {
				continue;
			}
			$result[ $revision_id ] = \array_map( 'intval', $terms );
		}
		return $result;
	}

	/**
	 * Unique term IDs across all fetched revisions.
	 *
	 * @param array<int, array<int, int>> $terms_by_revision Per-revision term ID list.
	 * @return array<int, int>
	 */
	private static function unique_term_ids( array $terms_by_revision ): array {
		$ids = [];
		foreach ( $terms_by_revision as $term_ids ) {
			foreach ( $term_ids as $term_id ) {
				$ids[ $term_id ] = $term_id;
			}
		}
		return \array_values( $ids );
	}

	/**
	 * Build a lookup of term_id → true for terms flagged protected.
	 *
	 * @param array<int, int> $term_ids Unique term IDs to inspect.
	 * @return array<int, bool>
	 */
	private static function protected_term_lookup( array $term_ids ): array {
		$lookup = [];
		foreach ( $term_ids as $term_id ) {
			$flag = get_term_meta( $term_id, TaxonomyRegistrar::PROTECTED_META, true );
			if ( (bool) $flag ) {
				$lookup[ $term_id ] = true;
			}
		}
		return $lookup;
	}
}
