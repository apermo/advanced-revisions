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
	 * Sets up Brain Monkey.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * Tears down Brain Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stubs get_post_types to return a fixed set of revisable types.
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
	 * The add_page helper registers under Settings with the expected slug + capability.
	 */
	public function test_add_page_registers_under_settings(): void {
		Functions\when( '__' )->returnArg();
		$captured = [];
		Functions\when( 'add_options_page' )->alias(
			static function ( string $title, string $menu, string $cap, string $slug, $cb ) use ( &$captured ): void {
				unset( $cb );
				$captured = [ $title, $menu, $cap, $slug ];
			},
		);

		SettingsPage::add_page();

		self::assertSame( SettingsPage::CAPABILITY, $captured[2] );
		self::assertSame( SettingsPage::MENU_SLUG, $captured[3] );
	}

	/**
	 * Settings API is wired via register_settings.
	 */
	public function test_register_settings_wires_settings_api(): void {
		$this->stub_post_types( [ 'post', 'page' ] );
		Functions\when( '__' )->returnArg();

		$register = 0;
		$section  = 0;
		$field    = 0;
		Functions\when( 'register_setting' )->alias(
			static function () use ( &$register ): void {
				$register++;
			},
		);
		Functions\when( 'add_settings_section' )->alias(
			static function () use ( &$section ): void {
				$section++;
			},
		);
		Functions\when( 'add_settings_field' )->alias(
			static function () use ( &$field ): void {
				$field++;
			},
		);

		SettingsPage::register_settings();

		self::assertSame( 1, $register );
		self::assertSame( 1, $section );
		self::assertSame( 2, $field );
	}

	/**
	 * Section-intro rendering prints a description paragraph.
	 */
	public function test_render_section_intro_prints_description(): void {
		Functions\when( 'esc_html__' )->returnArg();

		\ob_start();
		SettingsPage::render_section_intro();
		$output = (string) \ob_get_clean();

		self::assertStringContainsString( '<p>', $output );
		self::assertStringContainsString( 'revisions', \strtolower( $output ) );
	}

	/**
	 * Limit-field rendering prints a number input for the given post type.
	 */
	public function test_render_limit_field_prints_input(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'esc_attr' )->returnArg();

		\ob_start();
		SettingsPage::render_limit_field( [ 'post_type' => 'post' ] );
		$output = (string) \ob_get_clean();

		self::assertStringContainsString( '<input type="number"', $output );
	}

	/**
	 * Page rendering bails when user lacks capability.
	 */
	public function test_render_page_bails_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		\ob_start();
		SettingsPage::render_page();
		$output = (string) \ob_get_clean();

		self::assertSame( '', $output );
	}

	/**
	 * Page rendering emits the standard wrap + form when capable.
	 */
	public function test_render_page_emits_wrap_and_form(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'settings_fields' )->justReturn( '' );
		Functions\when( 'do_settings_sections' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );

		\ob_start();
		SettingsPage::render_page();
		$output = (string) \ob_get_clean();

		self::assertStringContainsString( '<div class="wrap">', $output );
		self::assertStringContainsString( '<form', $output );
	}

	/**
	 * Non-array inputs normalize to an empty limits map (never crash).
	 */
	public function test_sanitize_handles_non_array_input(): void {
		$cleaned = SettingsPage::sanitize_settings( 'garbage' );

		self::assertSame( [ LimitService::LIMITS_KEY => [] ], $cleaned );
	}
}
