<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisionsDev\Tests\Unit;

use Apermo\AdvancedRevisionsDev\TestPostTypes;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TestPostTypes.
 */
final class TestPostTypesTest extends TestCase {

	/**
	 * Captured register_post_type calls from the last TestPostTypes::register() run.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $registered = [];

	/**
	 * Captured register_post_meta calls from the last TestPostTypes::register() run.
	 *
	 * @var list<array{type: string, key: string, args: array<string, mixed>}>
	 */
	private array $meta = [];

	/**
	 * Sets up Brain Monkey and default function stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->registered = [];
		$this->meta       = [];

		Functions\when( 'register_post_type' )->alias(
			function ( string $type, array $args ): void {
				$this->registered[ $type ] = $args;
			},
		);
		Functions\when( 'register_post_meta' )->alias(
			function ( string $type, string $key, array $args ): void {
				$this->meta[] = [
					'type' => $type,
					'key'  => $key,
					'args' => $args,
				];
			},
		);
	}

	/**
	 * Tears down Brain Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * All five test post types are registered.
	 */
	public function test_register_registers_five_post_types(): void {
		TestPostTypes::register();

		$this->assertCount( 5, $this->registered );
		$this->assertSame(
			[ 'ar_test_article', 'ar_test_page', 'ar_test_product', 'ar_test_note', 'ar_test_private' ],
			\array_keys( $this->registered ),
		);
	}

	/**
	 * Article supports revisions.
	 */
	public function test_article_supports_revisions(): void {
		TestPostTypes::register();

		$this->assertContains( 'revisions', $this->registered['ar_test_article']['supports'] );
	}

	/**
	 * Page is hierarchical.
	 */
	public function test_page_is_hierarchical(): void {
		TestPostTypes::register();

		$this->assertTrue( $this->registered['ar_test_page']['hierarchical'] );
	}

	/**
	 * Product registers a revisioned meta key for exercising #9.
	 */
	public function test_product_registers_meta(): void {
		TestPostTypes::register();

		$this->assertCount( 1, $this->meta );
		$this->assertSame( 'ar_test_product', $this->meta[0]['type'] );
		$this->assertSame( '_ar_test_product_price', $this->meta[0]['key'] );
		$this->assertTrue( $this->meta[0]['args']['show_in_rest'] );
	}

	/**
	 * Note intentionally excludes revisions so "revisions disabled" scenarios are covered.
	 */
	public function test_note_does_not_support_revisions(): void {
		TestPostTypes::register();

		$this->assertNotContains( 'revisions', $this->registered['ar_test_note']['supports'] );
	}

	/**
	 * Private doc is not public so capability gating can be exercised.
	 */
	public function test_private_is_not_public(): void {
		TestPostTypes::register();

		$this->assertFalse( $this->registered['ar_test_private']['public'] );
		$this->assertFalse( $this->registered['ar_test_private']['publicly_queryable'] );
	}

	/**
	 * Every registered type uses its own capability type so tests aren't polluted
	 * by core-post capabilities.
	 */
	public function test_each_type_has_own_capability_type(): void {
		TestPostTypes::register();

		foreach ( $this->registered as $slug => $args ) {
			$this->assertSame( $slug, $args['capability_type'], "CPT {$slug} should use its own capability_type" );
			$this->assertTrue( $args['map_meta_cap'], "CPT {$slug} should map meta caps" );
		}
	}

	/**
	 * All types are REST-visible so the block editor can use them.
	 */
	public function test_all_types_are_rest_visible(): void {
		TestPostTypes::register();

		foreach ( $this->registered as $slug => $args ) {
			$this->assertTrue( $args['show_in_rest'], "CPT {$slug} should be REST-visible" );
		}
	}
}
