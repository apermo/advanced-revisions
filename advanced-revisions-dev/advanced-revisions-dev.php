<?php
/**
 * Plugin Name: Advanced Revisions – Dev Fixtures
 * Description: Test custom post types and seed WP-CLI commands for developing
 *              Advanced Revisions. DO NOT INSTALL IN PRODUCTION.
 * Version:     0.1.0
 * Requires PHP: 8.1
 * License:     GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Apermo\AdvancedRevisionsDev;

\defined( 'ABSPATH' ) || exit();

\spl_autoload_register(
	// phpcs:ignore Universal.FunctionDeclarations.NoLongClosures.ExceedsMaximum
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( ! \str_starts_with( $class_name, $prefix ) ) {
			return;
		}
		$relative = \substr( $class_name, \strlen( $prefix ) );
		$path     = __DIR__ . '/src/' . \str_replace( '\\', '/', $relative ) . '.php';
		if ( \is_readable( $path ) ) {
			require $path;
		}
	},
);

add_action( 'init', [ TestPostTypes::class, 'register' ] );
