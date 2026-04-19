<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisions\Tests\Unit\Revisions;

use Apermo\AdvancedRevisions\Revisions\LimitService;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * Tests for LimitService's wp_revisions_to_keep filter behavior.
 */
final class LimitServiceTest extends TestCase {

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
	 * Build a WP_Post stub with the given post_type.
	 *
	 * @param string $post_type Post type slug.
	 */
	private function post_of( string $post_type ): WP_Post {
		$post            = new WP_Post();
		$post->post_type = $post_type;
		return $post;
	}

	/**
	 * With no stored limits, filter returns the original count unchanged.
	 */
	public function test_filter_returns_original_when_no_limits_configured(): void {
		Functions\when( 'get_option' )->justReturn( [] );

		$result = LimitService::filter_revisions_to_keep( 5, $this->post_of( 'post' ) );

		self::assertSame( 5, $result );
	}

	/**
	 * A configured limit for the post's type overrides the original count.
	 */
	public function test_filter_overrides_with_configured_limit(): void {
		Functions\when( 'get_option' )->justReturn(
			[ LimitService::LIMITS_KEY => [ 'post' => 12 ] ],
		);

		$result = LimitService::filter_revisions_to_keep( 5, $this->post_of( 'post' ) );

		self::assertSame( 12, $result );
	}

	/**
	 * A configured limit for a different type does not affect this post.
	 */
	public function test_filter_leaves_non_matching_types_alone(): void {
		Functions\when( 'get_option' )->justReturn(
			[ LimitService::LIMITS_KEY => [ 'page' => 2 ] ],
		);

		$result = LimitService::filter_revisions_to_keep( 5, $this->post_of( 'post' ) );

		self::assertSame( 5, $result );
	}

	/**
	 * A limit of 0 disables revisions for the post type.
	 */
	public function test_filter_applies_zero_to_disable_revisions(): void {
		Functions\when( 'get_option' )->justReturn(
			[ LimitService::LIMITS_KEY => [ 'post' => 0 ] ],
		);

		$result = LimitService::filter_revisions_to_keep( 5, $this->post_of( 'post' ) );

		self::assertSame( 0, $result );
	}

	/**
	 * A limit of -1 signals unlimited, matching core's WP_POST_REVISIONS convention.
	 */
	public function test_filter_applies_minus_one_for_unlimited(): void {
		Functions\when( 'get_option' )->justReturn(
			[ LimitService::LIMITS_KEY => [ 'post' => -1 ] ],
		);

		$result = LimitService::filter_revisions_to_keep( 5, $this->post_of( 'post' ) );

		self::assertSame( -1, $result );
	}

	/**
	 * Malformed option data (non-array) is ignored.
	 */
	public function test_filter_ignores_malformed_option_data(): void {
		Functions\when( 'get_option' )->justReturn( 'not-an-array' );

		$result = LimitService::filter_revisions_to_keep( 5, $this->post_of( 'post' ) );

		self::assertSame( 5, $result );
	}

	/**
	 * Non-string post_type keys in stored limits are stripped by sanitization.
	 */
	public function test_stored_limits_drops_non_string_keys(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				LimitService::LIMITS_KEY => [
					'post' => 10,
					0      => 20,
				],
			],
		);

		$limits = LimitService::stored_limits();

		self::assertArrayHasKey( 'post', $limits );
		self::assertArrayNotHasKey( 0, $limits );
	}

	/**
	 * The limit_for_type helper returns null when no override is stored.
	 */
	public function test_limit_for_type_returns_null_when_unset(): void {
		Functions\when( 'get_option' )->justReturn( [ LimitService::LIMITS_KEY => [] ] );

		self::assertNull( LimitService::limit_for_type( 'post' ) );
	}

	/**
	 * Registering hooks the filter on wp_revisions_to_keep.
	 */
	public function test_register_hooks_the_filter(): void {
		Functions\expect( 'add_filter' )
			->once()
			->with( 'wp_revisions_to_keep', [ LimitService::class, 'filter_revisions_to_keep' ], 10, 2 );

		LimitService::register();
	}
}
