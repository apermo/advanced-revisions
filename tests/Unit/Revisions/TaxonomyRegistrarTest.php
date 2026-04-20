<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisions\Tests\Unit\Revisions;

use Apermo\AdvancedRevisions\Revisions\TaxonomyRegistrar;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TaxonomyRegistrar — focus on hook wiring and register_taxonomy
 * args. Full registration semantics are exercised by integration tests.
 */
final class TaxonomyRegistrarTest extends TestCase {

	/**
	 * Sets up Brain Monkey.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	/**
	 * Tears down Brain Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Verifies register() wires both taxonomy and meta callbacks on init.
	 */
	public function test_register_hooks_init_callbacks(): void {
		Functions\expect( 'add_action' )
			->once()
			->with( 'init', [ TaxonomyRegistrar::class, 'register_taxonomy' ] );
		Functions\expect( 'add_action' )
			->once()
			->with( 'init', [ TaxonomyRegistrar::class, 'register_meta' ] );

		TaxonomyRegistrar::register();
	}

	/**
	 * Asserts the taxonomy is registered against the revision post type with the expected shape.
	 */
	public function test_register_taxonomy_uses_revision_post_type(): void {
		$captured = [];
		Functions\when( 'register_taxonomy' )->alias(
			static function ( string $taxonomy, $object_type, array $args ) use ( &$captured ): void {
				$captured = [
					'taxonomy' => $taxonomy,
					'object'   => $object_type,
					'args'     => $args,
				];
			},
		);

		TaxonomyRegistrar::register_taxonomy();

		self::assertSame( 'revision_tag', $captured['taxonomy'] );
		self::assertSame( 'revision', $captured['object'] );
		self::assertFalse( $captured['args']['public'] );
		self::assertTrue( $captured['args']['show_in_rest'] );
		self::assertTrue( $captured['args']['show_ui'] );
		self::assertFalse( $captured['args']['hierarchical'] );
	}

	/**
	 * Asserts meta registration covers the protected flag term meta.
	 */
	public function test_register_meta_registers_protected_flag(): void {
		$term_meta = [];
		Functions\when( 'register_term_meta' )->alias(
			static function ( string $taxonomy, string $key, array $args ) use ( &$term_meta ): void {
				$term_meta[] = [
					'taxonomy' => $taxonomy,
					'key'      => $key,
					'args'     => $args,
				];
			},
		);

		TaxonomyRegistrar::register_meta();

		self::assertCount( 1, $term_meta );
		self::assertSame( 'revision_tag', $term_meta[0]['taxonomy'] );
		self::assertSame( 'protected', $term_meta[0]['key'] );
	}
}
