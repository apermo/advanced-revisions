<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisions\Tests\Unit\Admin;

use Apermo\AdvancedRevisions\Admin\DashboardWidget;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DashboardWidget — focus on caching behavior and hook wiring.
 */
final class DashboardWidgetTest extends TestCase {

	/**
	 * Sets up Brain Monkey and stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * Tears down Brain Monkey and clears $wpdb.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Registering hooks into wp_dashboard_setup.
	 */
	public function test_register_hooks_wp_dashboard_setup(): void {
		Functions\expect( 'add_action' )
			->once()
			->with( 'wp_dashboard_setup', [ DashboardWidget::class, 'add_widget' ] );

		DashboardWidget::register();
	}

	/**
	 * Adding the widget is capability-gated; low-privilege users see nothing.
	 */
	public function test_add_widget_is_gated_by_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'wp_add_dashboard_widget' )->never();

		DashboardWidget::add_widget();
	}

	/**
	 * Cached transients short-circuit the expensive compute path.
	 */
	public function test_stats_returns_cached_transient_when_present(): void {
		$cached = [
			'total'     => 42,
			'est_bytes' => 1024,
			'top'       => [],
		];
		Functions\when( 'get_transient' )->justReturn( $cached );
		Functions\expect( 'set_transient' )->never();

		$result = DashboardWidget::stats();

		self::assertSame( $cached, $result );
	}

	/**
	 * Flushing deletes the cache transient.
	 */
	public function test_flush_deletes_the_transient(): void {
		Functions\expect( 'delete_transient' )
			->once()
			->with( DashboardWidget::TRANSIENT_KEY );

		DashboardWidget::flush();
	}

	/**
	 * The add_widget helper registers the widget when capability passes.
	 */
	public function test_add_widget_registers_when_capable(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( '__' )->returnArg();
		$called = false;
		Functions\when( 'wp_add_dashboard_widget' )->alias(
			static function () use ( &$called ): void {
				$called = true;
			},
		);

		DashboardWidget::add_widget();

		self::assertTrue( $called );
	}

	/**
	 * Empty-state copy is emitted when no revisions exist.
	 */
	public function test_render_empty_state(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'get_transient' )->justReturn(
			[
				'total'     => 0,
				'est_bytes' => 0,
				'top'       => [],
			],
		);

		\ob_start();
		DashboardWidget::render();
		$output = (string) \ob_get_clean();

		self::assertStringContainsString( 'No stored revisions', $output );
	}

	/**
	 * Populated stats render revision totals and the top-posts list.
	 */
	public function test_render_populated_stats(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'number_format_i18n' )->returnArg();
		Functions\when( 'size_format' )->returnArg();
		Functions\when( '_n' )->alias(
			static fn( string $single, string $plural, int $count ): string => $count === 1 ? $single : $plural,
		);
		Functions\when( 'get_transient' )->justReturn(
			[
				'total'     => 42,
				'est_bytes' => 1024,
				'top'       => [
					[
						'title' => 'About',
						'count' => 10,
					],
					[
						'title' => 'Contact',
						'count' => 5,
					],
				],
			],
		);

		\ob_start();
		DashboardWidget::render();
		$output = (string) \ob_get_clean();

		self::assertStringContainsString( 'About', $output );
		self::assertStringContainsString( 'Contact', $output );
	}

	/**
	 * Malformed cached data (missing keys) is treated as a miss.
	 */
	public function test_stats_treats_malformed_cache_as_miss(): void {
		Functions\when( 'get_transient' )->justReturn( [ 'total' => 10 ] );
		Functions\when( 'set_transient' )->justReturn( true );

		// Force $wpdb to null so compute() returns zeros rather than hitting the DB.
		unset( $GLOBALS['wpdb'] );

		$result = DashboardWidget::stats();

		self::assertSame(
			[
				'total'     => 0,
				'est_bytes' => 0,
				'top'       => [],
			],
			$result,
		);
	}
}
