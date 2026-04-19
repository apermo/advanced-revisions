<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisions\Tests\Unit\Admin;

use Apermo\AdvancedRevisions\Admin\OverviewPage;
use Apermo\AdvancedRevisions\Tests\Fixtures\WpdbDouble;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * Tests for OverviewPage — hook wiring, request parsing, and render paths.
 */
final class OverviewPageTest extends TestCase {

	/**
	 * Sets up Brain Monkey and default WP function stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'admin_url' )->alias(
			static fn( string $path = '' ): string => '/wp-admin/' . $path,
		);
		Functions\when( 'add_query_arg' )->alias(
			static fn( array $args, string $url ): string => $url . '?' . \http_build_query( $args ),
		);
		Functions\when( 'get_edit_post_link' )->justReturn( '/wp-admin/edit.php?post=42' );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'wp_admin_notice' )->justReturn( '' );
	}

	/**
	 * Tears down Brain Monkey and clears superglobals so tests can't leak state.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		$_POST = [];
		$_GET  = [];
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Registering hooks admin_menu and admin_post_*.
	 */
	public function test_register_hooks_admin_menu_and_admin_post(): void {
		Functions\expect( 'add_action' )->times( 2 );

		OverviewPage::register();
	}

	/**
	 * The add_page helper mounts the page under Tools.
	 */
	public function test_add_page_registers_under_tools(): void {
		$captured = [];
		Functions\when( 'add_management_page' )->alias(
			static function ( string $title, string $menu, string $cap, string $slug, $cb ) use ( &$captured ): void {
				unset( $cb );
				$captured = [ $title, $menu, $cap, $slug ];
			},
		);

		OverviewPage::add_page();

		self::assertSame( OverviewPage::MENU_SLUG, $captured[3] );
		self::assertSame( OverviewPage::CAPABILITY, $captured[2] );
	}

	/**
	 * The render method returns silently when the user lacks capability.
	 */
	public function test_render_bails_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		\ob_start();
		OverviewPage::render();
		$output = (string) \ob_get_clean();

		self::assertSame( '', $output );
	}

	/**
	 * Empty-state render emits the no-revisions message.
	 */
	public function test_render_shows_empty_state_when_no_rows(): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride -- test-local wpdb stub.
		$GLOBALS['wpdb'] = new WpdbDouble();

		\ob_start();
		OverviewPage::render();
		$output = (string) \ob_get_clean();

		self::assertStringContainsString( 'No posts', $output );
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * The bulk-post handler exits via wp_die on missing capability.
	 */
	public function test_handle_bulk_post_dies_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$died = false;
		Functions\when( 'wp_die' )->alias(
			static function () use ( &$died ): void {
				$died = true;
				throw new RuntimeException( 'died' );
			},
		);

		try {
			OverviewPage::handle_bulk_post();
		} catch ( RuntimeException $error ) {
			// Expected: wp_die() throws in our stub.
			unset( $error );
		}

		self::assertTrue( $died );
	}

	/**
	 * Full render path with rows and pagination covers render_form + render_row.
	 */
	public function test_render_full_table_with_rows(): void {
		// phpcs:ignore SlevomatCodingStandard.PHP.ForbiddenClasses.ForbiddenClass
		$row_data                 = new stdClass();
		$row_data->ID             = 42;
		$row_data->post_title     = 'Hello';
		$row_data->post_type      = 'post';
		$row_data->post_author    = 1;
		$row_data->revision_count = 10;
		$row_data->oldest_gmt     = '2024-01-01';

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride -- test-local wpdb stub.
		$GLOBALS['wpdb']               = new WpdbDouble();
		$GLOBALS['wpdb']->next_results = [ $row_data ];
		$GLOBALS['wpdb']->next_var     = 60;

		\ob_start();
		OverviewPage::render();
		$output = (string) \ob_get_clean();

		self::assertStringContainsString( 'Hello', $output );
		self::assertStringContainsString( '<table', $output );
		self::assertStringContainsString( 'ar_parent_ids', $output );
		self::assertStringContainsString( 'tablenav-pages', $output );
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * Success notice is rendered when ar_bulk=done is in the query string.
	 */
	public function test_render_notices_shows_success_after_bulk_delete(): void {
		$_GET = [
			'ar_bulk'    => 'done',
			'ar_deleted' => '3',
			'ar_skipped' => '1',
		];

		$notices = [];
		Functions\when( 'wp_admin_notice' )->alias(
			static function ( string $message, array $args ) use ( &$notices ): void {
				$notices[] = [
					'message' => $message,
					'args' => $args,
				];
			},
		);

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride -- test-local wpdb stub.
		$GLOBALS['wpdb'] = new WpdbDouble();

		\ob_start();
		OverviewPage::render();
		\ob_end_clean();

		self::assertCount( 1, $notices );
		self::assertSame( 'success', $notices[0]['args']['type'] );
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * The bulk-post handler redirects with ar_bulk=empty when no selection.
	 */
	public function test_handle_bulk_post_redirects_on_empty_selection(): void {
		$_POST = [
			OverviewPage::NONCE_NAME => 'valid',
		];
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		$redirected_to = '';
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( string $url ) use ( &$redirected_to ): void {
				$redirected_to = $url;
			},
		);

		OverviewPage::handle_bulk_post();

		self::assertStringContainsString( 'ar_bulk=empty', $redirected_to );
	}
}
