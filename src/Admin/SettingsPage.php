<?php

declare(strict_types=1);

namespace Apermo\AdvancedRevisions\Admin;

use Apermo\AdvancedRevisions\Revisions\LimitService;

/**
 * Renders the **Settings → Revisions** page. Uses the Settings API so the page
 * looks and behaves exactly like native WordPress configuration screens.
 *
 * One setting for now: per-post-type revision limits. More sections will be
 * added as v0.1.0+ features land.
 */
final class SettingsPage {

	public const MENU_SLUG = 'advanced-revisions';

	public const SECTION_ID = 'advanced_revisions_limits_section';

	public const CAPABILITY = 'manage_options';

	/**
	 * Wires the admin-menu and admin-init hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_page' ] );
		add_action( 'admin_init', [ self::class, 'register_settings' ] );
	}

	/**
	 * Adds the page under Settings.
	 */
	public static function add_page(): void {
		add_options_page(
			__( 'Advanced Revisions', 'advanced-revisions' ),
			__( 'Revisions', 'advanced-revisions' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			[ self::class, 'render_page' ],
		);
	}

	/**
	 * Registers the settings, section, and field.
	 */
	public static function register_settings(): void {
		register_setting(
			self::MENU_SLUG,
			LimitService::OPTION_NAME,
			[
				'type'              => 'array',
				'sanitize_callback' => [ self::class, 'sanitize_settings' ],
				'default'           => [ LimitService::LIMITS_KEY => [] ],
			],
		);

		add_settings_section(
			self::SECTION_ID,
			__( 'Revisions per post type', 'advanced-revisions' ),
			[ self::class, 'render_section_intro' ],
			self::MENU_SLUG,
		);

		foreach ( self::revisable_post_types() as $post_type => $label ) {
			add_settings_field(
				'limit_' . $post_type,
				$label,
				[ self::class, 'render_limit_field' ],
				self::MENU_SLUG,
				self::SECTION_ID,
				[ 'post_type' => $post_type ],
			);
		}
	}

	/**
	 * Renders the short intro above the per-post-type fields.
	 */
	public static function render_section_intro(): void {
		echo '<p>';
		echo esc_html__(
			'Set how many revisions to keep for each post type. Leave blank to inherit the site-wide WP_POST_REVISIONS default. Use -1 for unlimited and 0 to disable revisions.',
			'advanced-revisions',
		);
		echo '</p>';
	}

	/**
	 * Renders one numeric input for a single post type.
	 *
	 * @param array<string, mixed> $args Callback args — 'post_type' key required.
	 */
	public static function render_limit_field( array $args ): void {
		$post_type = isset( $args['post_type'] ) && \is_string( $args['post_type'] )
			? $args['post_type']
			: '';
		if ( $post_type === '' ) {
			return;
		}

		$current    = LimitService::limit_for_type( $post_type );
		$value_attr = $current === null ? '' : (string) $current;
		$name_attr  = LimitService::OPTION_NAME . '[' . LimitService::LIMITS_KEY . '][' . $post_type . ']';

		\printf(
			'<input type="number" min="-1" step="1" name="%1$s" value="%2$s" class="small-text" />',
			esc_attr( $name_attr ),
			esc_attr( $value_attr ),
		);
	}

	/**
	 * Renders the settings page shell — Settings API does the form rendering.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Advanced Revisions', 'advanced-revisions' ) . '</h1>';
		echo '<form method="post" action="options.php">';
		settings_fields( self::MENU_SLUG );
		do_settings_sections( self::MENU_SLUG );
		submit_button();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Sanitize submitted settings — clamp to int, drop unknown post types,
	 * remove blank entries so defaults fall through cleanly.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string, array<string, int>>
	 */
	public static function sanitize_settings( mixed $input ): array {
		if ( ! \is_array( $input ) ) {
			return [ LimitService::LIMITS_KEY => [] ];
		}

		$submitted = $input[ LimitService::LIMITS_KEY ] ?? [];
		if ( ! \is_array( $submitted ) ) {
			$submitted = [];
		}

		$known   = \array_keys( self::revisable_post_types() );
		$cleaned = [];
		foreach ( $submitted as $post_type => $raw ) {
			if ( ! \is_string( $post_type ) || ! \in_array( $post_type, $known, true ) ) {
				continue;
			}
			if ( $raw === '' || $raw === null ) {
				continue;
			}
			$cleaned[ $post_type ] = \max( LimitService::UNLIMITED, (int) $raw );
		}

		return [ LimitService::LIMITS_KEY => $cleaned ];
	}

	/**
	 * Public post types that actually support revisions, keyed slug → label.
	 *
	 * @return array<string, string>
	 */
	private static function revisable_post_types(): array {
		$types = [];
		foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $post_type ) {
			if ( ! post_type_supports( $post_type->name, 'revisions' ) ) {
				continue;
			}
			$types[ $post_type->name ] = $post_type->labels->name ?? $post_type->name;
		}
		return $types;
	}
}
