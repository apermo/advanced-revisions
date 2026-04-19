<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisionsDev\Fixtures;

/**
 * Produces plausible-looking post content arrays deterministically from a
 * Randomizer seed. Output is used by ContentSeeder to feed wp_insert_post().
 *
 * Vocabulary is small on purpose — fixtures need to look distinct, not
 * Pulitzer-worthy.
 */
final class ContentGenerator {

	private const TITLE_TEMPLATES = [
		'How to %s your %s',
		'The complete guide to %s',
		'%s in practice: lessons from a %s team',
		'Why %s matters for your %s',
		'%s: a practical review',
		'Five common %s mistakes (and how to avoid them)',
		'Before you %s — read this',
		'Introducing %s for %s',
	];

	private const VERBS = [ 'configure', 'optimize', 'deploy', 'debug', 'design', 'refactor', 'test', 'document', 'automate', 'ship' ];

	private const NOUNS = [ 'API', 'workflow', 'pipeline', 'architecture', 'deployment', 'review', 'meeting', 'process', 'system', 'dashboard' ];

	private const AUDIENCES = [ 'distributed team', 'growing startup', 'small business', 'enterprise', 'solo developer' ];

	private const BODY_PARAGRAPHS = [
		'This is fixture content generated for test purposes. It exists to exercise revision comparison and bulk-cleanup flows against realistic-looking post bodies.',
		'Lorem ipsum would be easier, but having words of varied length in the body makes revision diffs easier to eyeball during manual QA.',
		'When you see this content, it means the Advanced Revisions dev plugin is active. Deactivate the plugin to hide these posts from the admin.',
		'Fixture posts carry a marker meta key so the reset-content command can find and delete them without touching any real editorial content.',
		'Revisions seeded on top of this post will mutate a word or two per save, producing a visible trail in the native revision compare screen.',
	];

	/**
	 * Builds one post data array (WP_Post shape, ready for wp_insert_post).
	 *
	 * @param Randomizer $rng           Deterministic PRNG.
	 * @param string     $post_type     Target post type slug.
	 * @param int        $author_id     User ID to assign as post_author.
	 * @param string     $post_status   post_status value.
	 * @param string     $post_date_gmt GMT timestamp 'Y-m-d H:i:s'.
	 * @return array<string, mixed>
	 */
	public function post( Randomizer $rng, string $post_type, int $author_id, string $post_status, string $post_date_gmt ): array {
		$title = $this->title( $rng );

		return [
			'post_type'     => $post_type,
			'post_status'   => $post_status,
			'post_author'   => $author_id,
			'post_title'    => $title,
			'post_content'  => $this->body( $rng ),
			'post_excerpt'  => $this->excerpt( $rng ),
			'post_date_gmt' => $post_date_gmt,
			'post_date'     => $post_date_gmt,
		];
	}

	/**
	 * Generates a plausible post title.
	 *
	 * @param Randomizer $rng Deterministic PRNG.
	 */
	public function title( Randomizer $rng ): string {
		$template   = (string) $rng->pick( self::TITLE_TEMPLATES );
		$slot_count = \substr_count( $template, '%s' );
		$slots      = [];

		for ( $i = 0; $i < $slot_count; $i++ ) {
			$slots[] = $this->slot_word( $rng, $i );
		}

		return \vsprintf( $template, $slots );
	}

	/**
	 * Generates a short body composed of 2-4 vocabulary paragraphs.
	 *
	 * @param Randomizer $rng Deterministic PRNG.
	 */
	public function body( Randomizer $rng ): string {
		$count      = $rng->int_between( 2, 4 );
		$indices    = [];
		$total_pool = \count( self::BODY_PARAGRAPHS );

		for ( $i = 0; $i < $count; $i++ ) {
			$indices[] = $rng->int_between( 0, $total_pool - 1 );
		}

		$paragraphs = [];
		foreach ( $indices as $index ) {
			$paragraphs[] = self::BODY_PARAGRAPHS[ $index ];
		}

		return \implode( "\n\n", $paragraphs );
	}

	/**
	 * Returns the first 160 chars of body as the excerpt, or blank 20% of the time.
	 *
	 * @param Randomizer $rng Deterministic PRNG.
	 */
	public function excerpt( Randomizer $rng ): string {
		if ( $rng->probability() < 0.2 ) {
			return '';
		}
		return \substr( $this->body( $rng ), 0, 160 );
	}

	/**
	 * Picks a vocabulary word appropriate for slot position in a title template.
	 * Slot 0 tends to be a verb/noun; later slots lean toward nouns/audiences.
	 *
	 * @param Randomizer $rng        Deterministic PRNG.
	 * @param int        $slot_index Zero-based template placeholder index.
	 */
	private function slot_word( Randomizer $rng, int $slot_index ): string {
		if ( $slot_index === 0 ) {
			$pool = \array_merge( self::VERBS, self::NOUNS );
		} elseif ( $slot_index === 1 ) {
			$pool = self::NOUNS;
		} else {
			$pool = self::AUDIENCES;
		}

		return (string) $rng->pick( $pool );
	}
}
