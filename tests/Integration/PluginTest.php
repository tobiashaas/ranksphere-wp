<?php
/**
 * The plugin inside a real WordPress.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Tests\Integration;

use RankSphere\Admin\SettingsPage;
use RankSphere\Lifecycle\Deactivator;
use RankSphere\Lifecycle\Upgrader;
use RankSphere\Support\Options;
use WP_UnitTestCase;

use const RankSphere\VERSION;

/**
 * Boots without side effects, stores its version, offers its page only to administrators.
 */
final class PluginTest extends WP_UnitTestCase {

	public function test_the_plugin_is_loaded_and_records_its_version(): void {
		delete_option( Options::VERSION );

		Upgrader::maybe_upgrade();

		self::assertSame( VERSION, get_option( Options::VERSION ) );
	}

	public function test_only_administrators_get_the_admin_page(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		self::assertIsInt( $editor );
		wp_set_current_user( $editor );
		( new SettingsPage() )->add_menu();
		self::assertEmpty( menu_page_url( SettingsPage::SLUG, false ) );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		self::assertIsInt( $admin );
		wp_set_current_user( $admin );
		( new SettingsPage() )->add_menu();
		self::assertStringContainsString( 'page=' . SettingsPage::SLUG, menu_page_url( SettingsPage::SLUG, false ) );
	}

	public function test_deactivation_stops_scheduled_work(): void {
		wp_schedule_event( time(), 'daily', Deactivator::CRON_HOOKS[0] );

		Deactivator::deactivate();

		self::assertFalse( wp_next_scheduled( Deactivator::CRON_HOOKS[0] ) );
	}
}
