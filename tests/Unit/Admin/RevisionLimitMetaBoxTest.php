<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisions\Tests\Unit\Admin;

use Apermo\AdvancedRevisions\Admin\RevisionLimitMetaBox;
use Apermo\AdvancedRevisions\Revisions\LimitService;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * Tests for RevisionLimitMetaBox — hooks, register_post_meta, save flow.
 */
final class RevisionLimitMetaBoxTest extends TestCase {

	/**
	 * Captured update_post_meta calls.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $updated_meta = [];

	/**
	 * Captured delete_post_meta keys.
	 *
	 * @var array<int, string>
	 */
	private array $deleted_meta = [];

	/**
	 * Sets up Brain Monkey and default WP function stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->updated_meta = [];
		$this->deleted_meta = [];

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'get_post_types' )->justReturn( [ 'post', 'page' ] );
		Functions\when( 'post_type_supports' )->justReturn( true );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'update_post_meta' )->alias(
			function ( int $id, string $key, int $value ): void {
				$this->updated_meta[] = [
					'id'    => $id,
					'key'   => $key,
					'value' => $value,
				];
			},
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( int $id, string $key ): void {
				$this->deleted_meta[] = $key;
				unset( $id );
			},
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
	}

	/**
	 * Tears down Brain Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Registering hooks add_meta_boxes, save_post, and init.
	 */
	public function test_register_hooks_three_actions(): void {
		Functions\expect( 'add_action' )
			->times( 3 );

		RevisionLimitMetaBox::register();
	}

	/**
	 * Meta registration runs for each revisable post type.
	 */
	public function test_register_post_meta_runs_for_each_type(): void {
		$registrations = [];
		Functions\when( 'register_post_meta' )->alias(
			static function ( string $type, string $key, array $args ) use ( &$registrations ): void {
				$registrations[] = $type;
				unset( $key, $args );
			},
		);

		RevisionLimitMetaBox::register_post_meta();

		self::assertSame( [ 'post', 'page' ], $registrations );
	}

	/**
	 * Meta box is added for each revisable post type.
	 */
	public function test_add_meta_box_runs_for_each_type(): void {
		$calls = [];
		Functions\when( 'add_meta_box' )->alias(
			static function ( string $id, string $title, $callback, string $screen ) use ( &$calls ): void {
				unset( $id, $title, $callback );
				$calls[] = $screen;
			},
		);

		RevisionLimitMetaBox::add_meta_box();

		self::assertSame( [ 'post', 'page' ], $calls );
	}

	/**
	 * Rendering emits the nonce field and input markup.
	 */
	public function test_render_emits_expected_markup(): void {
		$post     = new WP_Post();
		$post->ID = 42;

		\ob_start();
		RevisionLimitMetaBox::render( $post );
		$output = (string) \ob_get_clean();

		self::assertStringContainsString( '<input type="number"', $output );
		self::assertStringContainsString( LimitService::PER_POST_META_KEY, $output );
	}

	/**
	 * Save bails on missing nonce.
	 */
	public function test_save_bails_without_nonce(): void {
		$_POST = [];
		$post  = new WP_Post();

		RevisionLimitMetaBox::save( 42, $post );

		self::assertSame( [], $this->updated_meta );
	}

	/**
	 * Save deletes meta when the submitted value is blank.
	 */
	public function test_save_deletes_meta_on_blank_value(): void {
		$_POST = [
			RevisionLimitMetaBox::NONCE_NAME => 'abc',
			LimitService::PER_POST_META_KEY  => '',
		];
		$post     = new WP_Post();
		$post->ID = 42;

		RevisionLimitMetaBox::save( 42, $post );

		self::assertSame( [ LimitService::PER_POST_META_KEY ], $this->deleted_meta );
	}

	/**
	 * Save writes meta when the submitted value is a valid number.
	 */
	public function test_save_writes_meta_when_value_present(): void {
		$_POST = [
			RevisionLimitMetaBox::NONCE_NAME => 'abc',
			LimitService::PER_POST_META_KEY  => '7',
		];
		$post     = new WP_Post();
		$post->ID = 42;

		RevisionLimitMetaBox::save( 42, $post );

		self::assertCount( 1, $this->updated_meta );
		self::assertSame( 7, $this->updated_meta[0]['value'] );
	}

	/**
	 * Save clamps values below -1.
	 */
	public function test_save_clamps_below_minus_one(): void {
		$_POST = [
			RevisionLimitMetaBox::NONCE_NAME => 'abc',
			LimitService::PER_POST_META_KEY  => '-99',
		];
		$post     = new WP_Post();
		$post->ID = 42;

		RevisionLimitMetaBox::save( 42, $post );

		self::assertSame( -1, $this->updated_meta[0]['value'] );
	}
}
