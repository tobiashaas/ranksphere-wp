<?php
/**
 * RankSphere's data for the admin: the overview and the data of one page.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Insights;

use RankSphere\Connection\ConnectionStore;
use RankSphere\Connection\RankSphereClient;

/**
 * Fetched by the server (signed, with the site token) and kept for ten minutes, per language and
 * per project – a reconnect to another project never shows the old one's figures. A failed fetch
 * is kept for two minutes, so a slow RankSphere does not slow down every admin page.
 */
final class Insights {

	/** Transient with the overview per language. */
	public const OVERVIEW_CACHE = 'ranksphere_overview';

	/** Prefix of the transients with the data of one page. */
	private const PAGE_CACHE = 'ranksphere_page_';

	private const FRESH_SECONDS = 10 * MINUTE_IN_SECONDS;

	private const RETRY_SECONDS = 2 * MINUTE_IN_SECONDS;

	/**
	 * Takes the connection store.
	 *
	 * @param ConnectionStore $store Where the connection lives.
	 */
	public function __construct( private readonly ConnectionStore $store = new ConnectionStore() ) {}

	/**
	 * The overview in the current user's language; null when the site is not connected.
	 *
	 * @param bool $fetch Ask RankSphere when nothing fresh is cached (false: cached or nothing).
	 *
	 * @return array{data: array<mixed>|null, fetched_at: int, error: string|null}|null
	 */
	public function overview( bool $fetch = true ): ?array {
		$connection = $this->store->get();

		if ( null === $connection ) {
			return null;
		}

		$lang   = self::language();
		$cached = get_transient( self::OVERVIEW_CACHE );
		$cached = is_array( $cached ) ? $cached : array();
		$entry  = self::entry( $cached[ $lang ] ?? null, $connection->project_id );

		if ( null !== $entry || ! $fetch ) {
			return $entry;
		}

		$entry           = $this->fetch( '/overview', array( 'lang' => $lang ), $connection->project_id );
		$cached[ $lang ] = $entry;
		set_transient( self::OVERVIEW_CACHE, $cached, self::FRESH_SECONDS );

		return $entry;
	}

	/**
	 * RankSphere's data for one address of this site, in the current user's language.
	 *
	 * @param string $url A permalink of this site.
	 *
	 * @return array{data: array<mixed>|null, fetched_at: int, error: string|null}|null
	 */
	public function page( string $url ): ?array {
		$connection = $this->store->get();

		if ( null === $connection ) {
			return null;
		}

		$lang  = self::language();
		$key   = self::PAGE_CACHE . md5( $url . '|' . $lang );
		$entry = self::entry( get_transient( $key ), $connection->project_id );

		if ( null !== $entry ) {
			return $entry;
		}

		$entry = $this->fetch(
			'/pages',
			array(
				'url'  => $url,
				'lang' => $lang,
			),
			$connection->project_id
		);
		set_transient( $key, $entry, self::FRESH_SECONDS );

		return $entry;
	}

	/**
	 * Forgets the cached overview ("Refresh", disconnect).
	 */
	public static function forget(): void {
		delete_transient( self::OVERVIEW_CACHE );
	}

	/**
	 * The WordPress user's language as RankSphere reads it (de_DE_formal → de_DE).
	 */
	public static function language(): string {
		return (string) preg_replace( '/[^A-Za-z_]/', '', get_user_locale() );
	}

	/**
	 * Asks RankSphere.
	 *
	 * @param string                $endpoint   Path below the API URL.
	 * @param array<string, string> $query      Query parameters.
	 * @param string                $project_id The connected project.
	 *
	 * @return array{data: array<mixed>|null, fetched_at: int, error: string|null, project_id: string}
	 */
	private function fetch( string $endpoint, array $query, string $project_id ): array {
		$connection = $this->store->get();
		$result     = null === $connection
			? new \WP_Error( 'ranksphere_not_connected', 'Not connected.' )
			: ( new RankSphereClient( $connection ) )->get( $endpoint, $query );

		return array(
			'data'       => is_wp_error( $result ) ? null : $result,
			'fetched_at' => time(),
			'error'      => is_wp_error( $result ) ? (string) $result->get_error_code() : null,
			'project_id' => $project_id,
		);
	}

	/**
	 * A cached entry when it is still valid for this project.
	 *
	 * @param mixed  $entry      The cached value.
	 * @param string $project_id The connected project.
	 *
	 * @return array{data: array<mixed>|null, fetched_at: int, error: string|null}|null
	 */
	private static function entry( mixed $entry, string $project_id ): ?array {
		if ( ! is_array( $entry ) || ( $entry['project_id'] ?? null ) !== $project_id || ! is_int( $entry['fetched_at'] ?? null ) ) {
			return null;
		}

		$age = time() - $entry['fetched_at'];
		$ok  = is_array( $entry['data'] ?? null );

		if ( $age > ( $ok ? self::FRESH_SECONDS : self::RETRY_SECONDS ) ) {
			return null;
		}

		return array(
			'data'       => $ok ? $entry['data'] : null,
			'fetched_at' => $entry['fetched_at'],
			'error'      => is_string( $entry['error'] ?? null ) ? $entry['error'] : null,
		);
	}
}
