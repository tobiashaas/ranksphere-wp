<?php
/**
 * Updates from RankSphere.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Tests\Integration;

use RankSphere\Support\Options;
use RankSphere\Updates\Channel;
use RankSphere\Updates\Updater;
use WP_UnitTestCase;

use const RankSphere\PLUGIN_FILE;
use const RankSphere\VERSION;

/**
 * WordPress asks `update_plugins_ranksphere.cloud`; the answer comes from RankSphere, cached, and
 * only with a package from RankSphere itself.
 */
final class UpdaterTest extends WP_UnitTestCase {

	/** WordPress' filter for plugins updated from ranksphere.cloud. */
	private const HOOK = 'update_plugins_' . Updater::HOST;

	/**
	 * Requests sent to RankSphere.
	 *
	 * @var list<string>
	 */
	private array $requested = array();

	/**
	 * RankSphere's answer.
	 *
	 * @var array<string, mixed>
	 */
	private array $answer = array();

	public function set_up(): void {
		parent::set_up();

		delete_option( Options::UPDATE_CHANNEL );
		( new Updater() )->forget();
		$this->answer = array(
			'version'      => '1.0.0-alpha.2',
			'package'      => 'https://ranksphere.cloud/wordpress/plugin/ranksphere-1.0.0-alpha.2.zip',
			'url'          => 'https://github.com/tobiashaas/ranksphere-wp/releases/tag/v1.0.0-alpha.2',
			'requires'     => '6.6',
			'requires_php' => '8.1',
			'tested'       => '7.1',
			'changelog'    => '<ul><li>Fix</li></ul><script>alert(1)</script>',
		);

		add_filter(
			'pre_http_request',
			/**
			 * Answers like RankSphere.
			 *
			 * @param false|array<string, mixed> $preempt Ignored.
			 * @param array<string, mixed>       $args    Request arguments.
			 * @param string                     $url     Address.
			 */
			function ( $preempt, array $args, string $url ): array {
				$this->requested[] = $url;
				self::assertSame( 'RankSphere-WordPress/' . VERSION, $args['user-agent'], 'the site address stays out of the request' );

				return array(
					'headers'  => array(),
					'body'     => (string) wp_json_encode( $this->answer ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	public function test_the_channel_follows_the_installed_version_until_one_is_chosen(): void {
		self::assertSame( Channel::of( VERSION ), Updater::channel() );

		Updater::set_channel( Channel::BETA );
		self::assertSame( Channel::BETA, Updater::channel() );

		Updater::set_channel( 'nightly' );
		self::assertSame( Channel::of( VERSION ), Updater::channel() );
	}

	public function test_wordpress_gets_the_update_from_ranksphere_and_it_is_cached(): void {
		Updater::set_channel( Channel::ALPHA );
		$file = plugin_basename( PLUGIN_FILE );

		$update = $this->ask( array( 'UpdateURI' => 'https://ranksphere.cloud/wordpress/plugin' ), $file );

		self::assertIsArray( $update );
		self::assertSame( '1.0.0-alpha.2', $update['version'] );
		self::assertSame( 'https://ranksphere.cloud/wordpress/plugin/ranksphere-1.0.0-alpha.2.zip', $update['package'] );
		self::assertSame( 'ranksphere', $update['slug'] );
		self::assertCount( 1, $this->requested );
		self::assertStringContainsString( 'channel=alpha', $this->requested[0] );
		self::assertStringContainsString( 'version=' . rawurlencode( VERSION ), $this->requested[0] );

		$this->ask( array(), $file );
		self::assertCount( 1, $this->requested, 'cached' );

		self::assertFalse( $this->ask( array(), 'other/other.php' ), 'other plugins are left alone' );
	}

	public function test_check_again_under_updates_asks_ranksphere_again(): void {
		$file    = plugin_basename( PLUGIN_FILE );
		$updater = new Updater();
		$updater->register();
		self::assertSame( 9, has_action( 'load-update-core.php', array( $updater, 'force_check' ) ), 'before wp_update_plugins()' );

		$this->ask( array(), $file );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		self::assertIsInt( $admin );
		wp_set_current_user( $admin );
		$updater->force_check();
		$this->ask( array(), $file );
		self::assertCount( 1, $this->requested, 'opening Updates keeps the cached answer' );

		$_GET['force-check'] = '1';
		$updater->force_check();
		unset( $_GET['force-check'] );
		$this->ask( array(), $file );
		self::assertCount( 2, $this->requested, '"Check again" asks RankSphere' );
	}

	public function test_a_package_from_anywhere_else_is_ignored(): void {
		$this->answer['package'] = 'https://evil.example/ranksphere.zip';

		self::assertFalse( $this->ask( array(), plugin_basename( PLUGIN_FILE ) ) );
		self::assertNull( Updater::validate( array( 'version' => 'latest' ) + $this->answer ) );
	}

	/**
	 * Runs WordPress' update filter for a plugin.
	 *
	 * @param array<string, mixed> $plugin_data Plugin headers.
	 * @param string               $file        Plugin basename.
	 *
	 * @return mixed
	 */
	private function ask( array $plugin_data, string $file ) {
		return apply_filters( self::HOOK, false, $plugin_data, $file, array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- WordPress' filter for plugins from ranksphere.cloud.
	}

	public function test_view_details_shows_the_changelog_without_scripts(): void {
		$info = apply_filters( 'plugins_api', false, 'plugin_information', (object) array( 'slug' => 'ranksphere' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress' filter.

		self::assertIsObject( $info );
		self::assertSame( '1.0.0-alpha.2', $info->version ?? null );
		$sections = $info->sections ?? array();
		self::assertIsArray( $sections );
		self::assertIsString( $sections['changelog'] ?? null );
		self::assertStringStartsWith( '<ul><li>Fix</li></ul>', $sections['changelog'] );
		self::assertStringNotContainsString( '<script', $sections['changelog'], 'newer WordPress drops the script\'s text as well' );

		self::assertFalse( apply_filters( 'plugins_api', false, 'plugin_information', (object) array( 'slug' => 'other' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress' filter.
	}
}
