<?php
/**
 * Updates from RankSphere (builds outside WordPress.org only).
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Updates;

use RankSphere\Support\App;
use RankSphere\Support\Options;

use const RankSphere\PLUGIN_FILE;
use const RankSphere\VERSION;

/**
 * WordPress' own mechanism for plugins hosted elsewhere (5.8+): the header `Update URI` names
 * ranksphere.cloud, WordPress asks `update_plugins_ranksphere.cloud`, and the update then appears
 * under Plugins like any other – including auto-updates. The answer comes from RankSphere
 * (GET /api/wordpress/v1/plugin) and is cached; the request carries the plugin version and the
 * channel, not the site's address.
 *
 * The WordPress.org build leaves this directory out (bin/build-zip.sh --wporg): plugins there are
 * updated by WordPress.org only.
 */
final class Updater {

	/** Host of the `Update URI` header – WordPress builds the filter name from it. */
	public const HOST = 'ranksphere.cloud';

	public const SLUG = 'ranksphere';

	/** Cached answer per channel. */
	private const CACHE = 'ranksphere_update_';

	private const CACHE_SECONDS = 6 * HOUR_IN_SECONDS;

	/** After a failed check: try again in an hour, not on every page load. */
	private const RETRY_SECONDS = HOUR_IN_SECONDS;

	/**
	 * Hooks the update check, the "View details" dialog and the channel setting.
	 */
	public function register(): void {
		// Loaded now, not on first use: `forget` runs after an update, when this request still runs
		// the old code but the files on disk are already the new ones – maybe a build without them.
		class_exists( Channel::class );

		add_filter( 'update_plugins_' . self::HOST, array( $this, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'information' ), 20, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'forget' ) );
		// "Check again" under Dashboard → Updates asks RankSphere too, not the cached answer.
		// Before wp_update_plugins(), which WordPress hooks to the same action.
		add_action( 'load-update-core.php', array( $this, 'force_check' ), 9 );
	}

	/**
	 * The channel this site follows: chosen in the admin, otherwise the stage of the installed
	 * version (an alpha keeps getting alphas).
	 */
	public static function channel(): string {
		return Channel::sanitize( get_option( Options::UPDATE_CHANNEL ), Channel::of( VERSION ) );
	}

	/**
	 * Stores another channel and makes WordPress look again.
	 *
	 * @param string $channel One of Channel::ALL.
	 */
	public static function set_channel( string $channel ): void {
		update_option( Options::UPDATE_CHANNEL, Channel::sanitize( $channel, Channel::of( VERSION ) ), false );
		( new self() )->forget();
		delete_site_transient( 'update_plugins' );
	}

	/**
	 * Filter `update_plugins_ranksphere.cloud`.
	 *
	 * @param array<string, mixed>|false $update      Update data from an earlier callback.
	 * @param array<string, mixed>       $plugin_data The plugin's headers.
	 * @param string                     $plugin_file Plugin basename.
	 *
	 * @return array<string, mixed>|false
	 */
	public function check( $update, array $plugin_data, string $plugin_file ) {
		if ( plugin_basename( PLUGIN_FILE ) !== $plugin_file ) {
			return $update;
		}

		$release = $this->release();

		if ( null === $release ) {
			return $update;
		}

		return array(
			'id'           => $plugin_data['UpdateURI'] ?? 'https://' . self::HOST . '/wordpress/plugin',
			'slug'         => self::SLUG,
			'plugin'       => $plugin_file,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => $release['requires'],
			'requires_php' => $release['requires_php'],
			'tested'       => $release['tested'],
		);
	}

	/**
	 * Filter `plugins_api`: the "View details" dialog for this plugin.
	 *
	 * @param false|object|array<mixed> $result Earlier result.
	 * @param string                    $action The requested action.
	 * @param object                    $args   Its arguments.
	 *
	 * @return false|object|array<mixed>
	 */
	public function information( $result, string $action, object $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$release = $this->release();

		if ( null === $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'RankSphere',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://ranksphere.cloud">RankSphere</a>',
			'homepage'      => $release['url'],
			'requires'      => $release['requires'],
			'requires_php'  => $release['requires_php'],
			'tested'        => $release['tested'],
			'download_link' => $release['package'],
			'sections'      => array( 'changelog' => wp_kses_post( $release['changelog'] ) ),
		);
	}

	/**
	 * Action `load-update-core.php`: on "Check again" (force-check) the cached answer is dropped.
	 */
	public function force_check(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WordPress' own link has no nonce; this only drops a cache.
		if ( isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) {
			$this->forget();
		}
	}

	/**
	 * Forgets the cached answers (after an update or a channel change).
	 */
	public function forget(): void {
		foreach ( Channel::ALL as $channel ) {
			delete_transient( self::CACHE . $channel );
		}
	}

	/**
	 * The newest release in this site's channel, cached.
	 *
	 * @return array{version: string, package: string, url: string, requires: string, requires_php: string, tested: string, changelog: string}|null
	 */
	public function release(): ?array {
		$channel = self::channel();
		$cached  = get_transient( self::CACHE . $channel );

		if ( is_array( $cached ) ) {
			return self::validate( $cached['release'] ?? null );
		}

		$release = $this->fetch( $channel );
		set_transient( self::CACHE . $channel, array( 'release' => $release ), null === $release ? self::RETRY_SECONDS : self::CACHE_SECONDS );

		return $release;
	}

	/**
	 * Asks RankSphere.
	 *
	 * @param string $channel The channel.
	 *
	 * @return array{version: string, package: string, url: string, requires: string, requires_php: string, tested: string, changelog: string}|null
	 */
	private function fetch( string $channel ): ?array {
		$response = wp_safe_remote_get(
			add_query_arg(
				array(
					'channel' => $channel,
					'version' => VERSION,
				),
				App::url() . '/api/wordpress/v1/plugin'
			),
			array(
				'timeout'    => 10,
				// WordPress' default user agent contains the site's address; RankSphere does not need it.
				'user-agent' => 'RankSphere-WordPress/' . VERSION,
				'headers'    => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		return self::validate( json_decode( wp_remote_retrieve_body( $response ), true ) );
	}

	/**
	 * Accepts only a complete answer whose package comes from RankSphere itself.
	 *
	 * @param mixed $data Decoded JSON or cached value.
	 *
	 * @return array{version: string, package: string, url: string, requires: string, requires_php: string, tested: string, changelog: string}|null
	 */
	public static function validate( mixed $data ): ?array {
		if ( ! is_array( $data ) ) {
			return null;
		}

		$text = static fn ( string $key ): string => isset( $data[ $key ] ) && is_string( $data[ $key ] ) ? $data[ $key ] : '';

		$version = $text( 'version' );
		$package = $text( 'package' );

		if ( 1 !== preg_match( '/^\d+\.\d+\.\d+(-(alpha|beta|rc)\.\d+)?$/', $version ) ) {
			return null;
		}

		$app = wp_parse_url( App::url() );

		if ( ! is_array( $app ) || wp_parse_url( $package, PHP_URL_HOST ) !== ( $app['host'] ?? null ) || wp_parse_url( $package, PHP_URL_SCHEME ) !== ( $app['scheme'] ?? null ) ) {
			return null;
		}

		return array(
			'version'      => $version,
			'package'      => $package,
			'url'          => '' !== $text( 'url' ) ? $text( 'url' ) : App::url(),
			'requires'     => $text( 'requires' ),
			'requires_php' => $text( 'requires_php' ),
			'tested'       => $text( 'tested' ),
			'changelog'    => $text( 'changelog' ),
		);
	}
}
