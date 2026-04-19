<?php
/**
 * Minimal $wpdb test double used by unit tests that exercise SQL-executing code.
 *
 * This file is excluded from PHPCS via phpcs.xml.dist; the relaxed style here
 * keeps the wpdb API shape small and readable.
 *
 * @package Advanced_Revisions_Tests
 */

declare(strict_types=1);

namespace Apermo\AdvancedRevisions\Tests\Fixtures;

// phpcs:disable

final class WpdbDouble {

	public string $posts = 'wp_posts';

	public array $next_results = [];

	public int $next_var = 0;

	public array $next_col = [];

	public function prepare( string $query, mixed ...$args ): string {
		unset( $args );
		return $query;
	}

	public function get_results( string $query ): array {
		unset( $query );
		return $this->next_results;
	}

	public function get_var( string $query ): int {
		unset( $query );
		return $this->next_var;
	}

	public function get_col( string $query ): array {
		unset( $query );
		return $this->next_col;
	}
}
