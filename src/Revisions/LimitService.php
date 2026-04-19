<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisions\Revisions;

use WP_Post;

/**
 * Applies per-post-type revision limits by hooking `wp_revisions_to_keep`.
 *
 * Options shape (under option name {@see self::OPTION_NAME}):
 *
 *     [
 *         'limits' => [ 'post' => 10, 'page' => -1, 'product' => 0 ],
 *     ]
 *
 * Where the integer value is passed straight to core as the "revisions to keep"
 * count. Core treats -1 as "unlimited" and 0 as "disable revisions".
 *
 * A post type with no entry in `limits` falls through to WP_POST_REVISIONS.
 */
final class LimitService {

	public const OPTION_NAME = 'advanced_revisions_settings';

	public const LIMITS_KEY = 'limits';

	public const UNLIMITED = -1;

	/**
	 * Register the filter. Safe to call multiple times — the filter is idempotent.
	 */
	public static function register(): void {
		add_filter( 'wp_revisions_to_keep', [ self::class, 'filter_revisions_to_keep' ], 10, 2 );
	}

	/**
	 * Override the revision count for a given post based on its type.
	 *
	 * @param int     $num  Current revision count (from core or an earlier filter).
	 * @param WP_Post $post Post being saved.
	 */
	public static function filter_revisions_to_keep( int $num, WP_Post $post ): int {
		$configured = self::limit_for_type( $post->post_type );
		if ( $configured === null ) {
			return $num;
		}
		return $configured;
	}

	/**
	 * Read the configured limit for a post type. Returns null when no override
	 * is set, meaning "use core's default / WP_POST_REVISIONS".
	 *
	 * @param string $post_type Post type slug.
	 */
	public static function limit_for_type( string $post_type ): ?int {
		$limits = self::stored_limits();
		if ( ! \array_key_exists( $post_type, $limits ) ) {
			return null;
		}
		return $limits[ $post_type ];
	}

	/**
	 * Read the limits map from the settings option.
	 *
	 * @return array<string, int>
	 */
	public static function stored_limits(): array {
		$option = get_option( self::OPTION_NAME, [] );
		if ( ! \is_array( $option ) ) {
			return [];
		}
		$limits = $option[ self::LIMITS_KEY ] ?? [];
		if ( ! \is_array( $limits ) ) {
			return [];
		}

		$sanitized = [];
		foreach ( $limits as $post_type => $value ) {
			if ( ! \is_string( $post_type ) ) {
				continue;
			}
			$sanitized[ $post_type ] = \max( self::UNLIMITED, (int) $value );
		}
		return $sanitized;
	}
}
