<?php
/**
 * Plugin Name: Advanced Revisions
 * Plugin URI:  https://github.com/apermo/advanced-revisions
 * Description: Advanced features for WordPress revisions: configurable limits per post type, an admin overview, and bulk deletion tools.
 * Version:     0.1.0
 * Author:      Christoph Daum
 * Author URI:  https://apermo.de
 * License:     GPL-2.0-or-later
 * Text Domain: advanced-revisions
 * Requires at least: 6.4
 * Requires PHP: 8.1
 */

declare(strict_types=1);

namespace Apermo\AdvancedRevisions;

\defined( 'ABSPATH' ) || exit();

if ( ! \file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		// phpcs:ignore Universal.FunctionDeclarations.NoLongClosures.ExceedsMaximum
		static function (): void {
			wp_admin_notice(
				wp_kses(
					\sprintf(
						/* translators: %s: composer install command */
						__( 'Please run %s to install the required dependencies.', 'advanced-revisions' ),
						'<code>composer install</code>',
					),
					[ 'code' => [] ],
				),
				[ 'type' => 'error' ],
			);
		},
	);
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

Plugin::init( __FILE__ );
