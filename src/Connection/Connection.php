<?php
/**
 * The stored connection to a RankSphere project.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Connection;

/**
 * What RankSphere handed over when it connected the site, plus who approved it. The secret and
 * the site token never leave the server: not in REST responses, not in the browser.
 */
final class Connection {

	/**
	 * Takes the stored values.
	 *
	 * @param string $project_id        RankSphere's project key.
	 * @param string $project_name      Shown in the admin.
	 * @param string $project_url       The project in RankSphere, for "Open in RankSphere".
	 * @param string $api_url           Base address of RankSphere's API for this site, without a trailing slash.
	 * @param string $secret            Shared HMAC secret (the string itself is the key).
	 * @param string $site_token        Bearer token for calls to RankSphere.
	 * @param int    $user_id           The user whose application password RankSphere uses.
	 * @param string $app_password_uuid That application password – revoking it ends the connection.
	 * @param int    $connected_at      Unix time of the (last) connection.
	 */
	public function __construct(
		public readonly string $project_id,
		public readonly string $project_name,
		public readonly string $project_url,
		public readonly string $api_url,
		public readonly string $secret,
		public readonly string $site_token,
		public readonly int $user_id,
		public readonly string $app_password_uuid,
		public readonly int $connected_at,
	) {}

	/**
	 * Rebuilds the connection from the stored option; null for anything incomplete.
	 *
	 * @param mixed $data The option value.
	 */
	public static function from_array( mixed $data ): ?self {
		if ( ! is_array( $data ) ) {
			return null;
		}

		$strings = array( 'project_id', 'project_name', 'project_url', 'api_url', 'secret', 'site_token', 'app_password_uuid' );

		foreach ( $strings as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_string( $data[ $key ] ) ) {
				return null;
			}
		}

		if ( ! isset( $data['user_id'], $data['connected_at'] ) || ! is_int( $data['user_id'] ) || ! is_int( $data['connected_at'] ) ) {
			return null;
		}

		return new self(
			$data['project_id'],
			$data['project_name'],
			$data['project_url'],
			$data['api_url'],
			$data['secret'],
			$data['site_token'],
			$data['user_id'],
			$data['app_password_uuid'],
			$data['connected_at'],
		);
	}

	/**
	 * The values to store.
	 *
	 * @return array<string, string|int>
	 */
	public function to_array(): array {
		return array(
			'project_id'        => $this->project_id,
			'project_name'      => $this->project_name,
			'project_url'       => $this->project_url,
			'api_url'           => $this->api_url,
			'secret'            => $this->secret,
			'site_token'        => $this->site_token,
			'user_id'           => $this->user_id,
			'app_password_uuid' => $this->app_password_uuid,
			'connected_at'      => $this->connected_at,
		);
	}
}
