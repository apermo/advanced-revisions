<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisions\Revisions;

/**
 * Deletes revisions for a set of parent post IDs, honoring the protection
 * flag from {@see ProtectionService}.
 */
final class RevisionDeleter {

	/**
	 * Injects the revision repository.
	 *
	 * @param RevisionRepository $repository Provides revision IDs per parent.
	 */
	public function __construct(
		private readonly RevisionRepository $repository,
	) {
	}

	/**
	 * Previews deletable vs. protected revision counts for a set of parent
	 * posts without deleting anything. Caller can use this to populate a
	 * confirmation UI.
	 *
	 * @param array<int, int> $parent_ids Parent post IDs.
	 * @return array{deletable:int, protected:int}
	 */
	public function preview( array $parent_ids ): array {
		$revision_ids = $this->repository->revision_ids_for_parents( $parent_ids );
		$deletable    = ProtectionService::filter_deletable( $revision_ids );

		return [
			'deletable' => \count( $deletable ),
			'protected' => ProtectionService::count_protected( $revision_ids ),
		];
	}

	/**
	 * Deletes every unprotected revision belonging to the given parent posts.
	 *
	 * @param array<int, int> $parent_ids Parent post IDs.
	 * @return array{deleted:int, skipped:int}
	 */
	public function delete_for_parents( array $parent_ids ): array {
		$revision_ids = $this->repository->revision_ids_for_parents( $parent_ids );
		$deletable    = ProtectionService::filter_deletable( $revision_ids );
		$protected    = \count( $revision_ids ) - \count( $deletable );

		$deleted = 0;
		foreach ( $deletable as $revision_id ) {
			$result = wp_delete_post_revision( $revision_id );
			if ( $result === false || $result === null ) {
				continue;
			}
			$deleted++;
		}

		return [
			'deleted' => $deleted,
			'skipped' => $protected,
		];
	}
}
