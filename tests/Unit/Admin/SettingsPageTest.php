<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisions\Tests\Unit\Admin;

use Apermo\AdvancedRevisions\Admin\SettingsPage;
use Apermo\AdvancedRevisions\Revisions\LimitService;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for SettingsPage — focus on the sanitizer (pure logic) and hook wiring.
 * Rendering tests live at the integration / E2E layer.
 */
final class SettingsPageTest extends TestCase {

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
	 * Stub get_post_types to return a fixed set of revisable types.
	 *
	 * @param array<int, string> $types Post type slugs to consider revisable.
	 */
	private function stub_post_types( array $types ): void {
		$post_type_objects = [];
		foreach ( $types as $type ) {
			// phpcs:ignore SlevomatCodingStandard.PHP.ForbiddenClasses.ForbiddenClass -- minimal WP_Post_Type stand-in for unit test.
			$post_type_object = new stdClass();
			$post_type_object->name = $type;
			// phpcs:ignore SlevomatCodingStandard.PHP.ForbiddenClasses.ForbiddenClass
			$post_type_object->labels = new stdClass();
			$post_type_object->labels->name = \ucfirst( $type );

			$post_type_objects[ $type ] = $post_type_object;
		}

		Functions\when( 'get_post_types' )->justReturn( $post_type_objects );
		Functions\when( 'post_type_supports' )->justReturn( true );
	}

	/**
	 * Registering hooks admin_menu and admin_init.
	 */
	public function test_register_hooks_admin_menu_and_admin_init(): void {
		Functions\expect( 'add_action' )
			->once()
			->with( 'admin_menu', [ SettingsPage::class, 'add_page' ] );
		Functions\expect( 'add_action' )
			->once()
			->with( 'admin_init', [ SettingsPage::class, 'register_settings' ] );

		SettingsPage::register();
	}

	/**
	 * Sanitizer drops keys for post types that don't support revisions.
	 */
	public function test_sanitize_drops_unknown_post_types(): void {
		$this->stub_post_types( [ 'post', 'page' ] );

		$cleaned = SettingsPage::sanitize_settings(
			[
				LimitService::LIMITS_KEY => [
					'post'        => 10,
					'not_a_type'  => 5,
				],
			],
		);

		self::assertSame(
			[ LimitService::LIMITS_KEY => [ 'post' => 10 ] ],
			$cleaned,
		);
	}

	/**
	 * Sanitizer coerces string numbers and clamps below -1.
	 */
	public function test_sanitize_clamps_and_coerces(): void {
		$this->stub_post_types( [ 'post' ] );

		$cleaned = SettingsPage::sanitize_settings(
			[ LimitService::LIMITS_KEY => [ 'post' => '-99' ] ],
		);

		self::assertSame(
			[ LimitService::LIMITS_KEY => [ 'post' => -1 ] ],
			$cleaned,
		);
	}

	/**
	 * Empty string values are treated as "inherit" and dropped from storage.
	 */
	public function test_sanitize_drops_empty_strings(): void {
		$this->stub_post_types( [ 'post', 'page' ] );

		$cleaned = SettingsPage::sanitize_settings(
			[
				LimitService::LIMITS_KEY => [
					'post' => '',
					'page' => 7,
				],
			],
		);

		self::assertSame(
			[ LimitService::LIMITS_KEY => [ 'page' => 7 ] ],
			$cleaned,
		);
	}

	/**
	 * Non-array inputs normalize to an empty limits map (never crash).
	 */
	public function test_sanitize_handles_non_array_input(): void {
		$cleaned = SettingsPage::sanitize_settings( 'garbage' );

		self::assertSame( [ LimitService::LIMITS_KEY => [] ], $cleaned );
	}
}
