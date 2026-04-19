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
	 * Set up Brain Monkey and stubs.
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
