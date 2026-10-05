<?php
/**
 * Plugin bootstrap.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere;

use RankSphere\Admin\DashboardWidget;
use RankSphere\Admin\OverviewPage;
use RankSphere\Admin\PageBox;
use RankSphere\Admin\SettingsPage;
use RankSphere\Admin\TextsPage;
use RankSphere\Connection\Revocation;
use RankSphere\Lifecycle\Upgrader;
use RankSphere\Content\PlaceholderLock;
use RankSphere\Rest\ConnectionController;
use RankSphere\Rest\ContentController;
use RankSphere\Seo\NativeOutput;

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
		add_action( 'init', array( self::class, 'load_translations' ), 0 );

		( new ConnectionController() )->register();
		( new ContentController() )->register();
		( new Revocation() )->register();
		( new PlaceholderLock() )->register();
		( new NativeOutput() )->register();
		// Its REST route answers outside the admin too; box and script only hook admin actions.
		( new PageBox() )->register();

		// Builds from RankSphere update themselves; the WordPress.org build has no Updates directory.
		if ( is_readable( __DIR__ . '/Updates/Updater.php' ) ) {
			( new Updates\Updater() )->register();
		}

		if ( is_admin() ) {
			( new OverviewPage() )->register();
			( new TextsPage() )->register();
			( new SettingsPage() )->register();
			( new DashboardWidget() )->register();
		}
	}

	/**
	 * Translations bundled in languages/ (German for now). Once the plugin is translated on
	 * translate.wordpress.org, WordPress loads those first; for de_DE_formal and other variants
	 * the file of the same language is used.
	 */
	public static function load_translations(): void {
		$locale = determine_locale();
		$dir    = dirname( PLUGIN_FILE ) . '/languages/';
		$file   = $dir . 'ranksphere-' . $locale . '.l10n.php';

		if ( ! is_readable( $file ) ) {
			$same_language = glob( $dir . 'ranksphere-' . substr( $locale, 0, 2 ) . '_*.l10n.php' );
			$file          = is_array( $same_language ) && array() !== $same_language ? $same_language[0] : '';
		}

		if ( '' !== $file ) {
			// WordPress looks for the .l10n.php file next to the .mo name it is given.
			load_textdomain( 'ranksphere', substr( $file, 0, - strlen( '.l10n.php' ) ) . '.mo', $locale );
		}
	}
}
