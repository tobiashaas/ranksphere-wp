<?php
/**
 * Plugin Name:       RankSphere
 * Plugin URI:        https://ranksphere.cloud
 * Description:       Connects the site to RankSphere: drafts written in RankSphere arrive as WordPress drafts, SEO titles and descriptions stay in sync with the active SEO plugin, and the RankSphere overview appears in the admin.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            RankSphere
 * Author URI:        https://ranksphere.cloud
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ranksphere
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere;

defined( 'ABSPATH' ) || exit;

const VERSION     = '0.1.0';
const PLUGIN_FILE = __FILE__;

// Composer's autoloader – built into the release zip, nothing is loaded from outside the plugin.
if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( current_user_can( 'activate_plugins' ) ) {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__( 'RankSphere is incomplete: the vendor directory is missing. Please reinstall the plugin.', 'ranksphere' )
				);
			}
		}
	);

	return;
}

require_once __DIR__ . '/vendor/autoload.php';

// Registered at file scope – inside another hook they would never fire.
register_activation_hook( __FILE__, array( Lifecycle\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Lifecycle\Deactivator::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
