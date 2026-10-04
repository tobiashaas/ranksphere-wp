<?php
/**
 * Plugin bootstrap.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere;

use RankSphere\Admin\SettingsPage;
use RankSphere\Connection\Revocation;
use RankSphere\Lifecycle\Upgrader;
use RankSphere\Rest\ConnectionController;

/**
 * Registers the plugin's hooks once WordPress has loaded all plugins. Nothing runs at file load
 * time, and admin-only code is only hooked in the admin.
 */
final class Plugin {

	/**
	 * Hooked to `plugins_loaded`.
	 */
	public static function boot(): void {
		Upgrader::maybe_upgrade();

		( new ConnectionController() )->register();
		( new Revocation() )->register();

		// Builds from RankSphere update themselves; the WordPress.org build has no Updates directory.
		if ( is_readable( __DIR__ . '/Updates/Updater.php' ) ) {
			( new Updates\Updater() )->register();
		}

		if ( is_admin() ) {
			( new SettingsPage() )->register();
		}
	}
}
