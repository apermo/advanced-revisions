<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisionsDev\Fixtures;

/**
 * Centralizes marker meta keys and values used to tag seeded content / revisions
 * so the reset commands can never touch real data.
 */
final class Marker {

	public const SEEDED_POST     = '_ar_seeded';
	public const SEEDED_REVISION = '_ar_seeded_revision';
	public const YES             = '1';

	public const AUTHOR_LOGIN_PREFIX = 'ar_test_author_';
	public const AUTHOR_EMAIL_DOMAIN = 'example.tld';

	/**
	 * Builds the login name for test author N (1-indexed).
	 *
	 * @param int $index 1-based author index.
	 */
	public static function author_login( int $index ): string {
		return self::AUTHOR_LOGIN_PREFIX . $index;
	}

	/**
	 * Builds a deterministic email for test author N.
	 *
	 * @param int $index 1-based author index.
	 */
	public static function author_email( int $index ): string {
		return self::author_login( $index ) . '@' . self::AUTHOR_EMAIL_DOMAIN;
	}
}
