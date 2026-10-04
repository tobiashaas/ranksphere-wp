<?php
/**
 * The plugin inside a real WordPress.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Tests\Integration;

use RankSphere\Admin\OverviewPage;
use RankSphere\Plugin;
use RankSphere\Admin\SettingsPage;
use RankSphere\Lifecycle\Deactivator;
use RankSphere\Lifecycle\Upgrader;
use RankSphere\Support\Options;
use WP_UnitTestCase;

use const RankSphere\VERSION;

/**
 * Boots without side effects, stores its version, opens its page only for administrators.
 */
final class PluginTest extends WP_UnitTestCase {

	public function test_the_plugin_is_loaded_and_records_its_version(): void {
		delete_option( Options::VERSION );

		Upgrader::maybe_upgrade();

		self::assertSame( VERSION, get_option( Options::VERSION ) );
	}

	public function test_only_administrators_can_open_the_admin_page(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		self::assertIsInt( $admin );
		wp_set_current_user( $admin );
		( new OverviewPage() )->add_menu();
		( new SettingsPage() )->add_menu();
		self::assertStringContainsString( 'page=' . SettingsPage::SLUG, menu_page_url( SettingsPage::SLUG, false ) );

		ob_start();
		( new SettingsPage() )->render();
		self::assertStringContainsString( 'not connected to RankSphere yet', (string) ob_get_clean() );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		self::assertIsInt( $editor );
		wp_set_current_user( $editor );

		$this->expectException( \WPDieException::class );
		( new SettingsPage() )->render();
	}

	public function test_german_admins_get_the_bundled_translation(): void {
		// de_DE_formal has no file of its own: the German one is used.
		$locale = static fn (): string => 'de_DE_formal';
		add_filter( 'determine_locale', $locale );

		Plugin::load_translations();

		self::assertSame( 'Übersicht', __( 'Overview', 'ranksphere' ) );
		/* translators: %d: number of steps. */
		self::assertSame( '3 weitere Schritte in RankSphere', sprintf( _n( '%d more step in RankSphere', '%d more steps in RankSphere', 3, 'ranksphere' ), 3 ) );

		remove_filter( 'determine_locale', $locale );
		unload_textdomain( 'ranksphere' );
		self::assertSame( 'Overview', __( 'Overview', 'ranksphere' ) );
	}

	public function test_deactivation_stops_scheduled_work(): void {
		wp_schedule_event( time(), 'daily', Deactivator::CRON_HOOKS[0] );

		Deactivator::deactivate();

		self::assertFalse( wp_next_scheduled( Deactivator::CRON_HOOKS[0] ) );
	}
}
