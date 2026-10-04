<?php
/**
 * Calls from the site to RankSphere.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Connection;

use RankSphere\Security\RequestVerifier;
use RankSphere\Security\Signature;

use const RankSphere\VERSION;

/**
 * Always from the server (`wp_remote_*`), never from the browser: bearer site token plus the
 * same HMAC signature RankSphere uses towards the site.
 */
final class RankSphereClient {

	/**
	 * Takes the connection to talk to.
	 *
	 * @param Connection $connection The connection.
	 */
	public function __construct( private readonly Connection $connection ) {}

	/**
	 * Tells RankSphere the site ended the connection. Best effort: the site disconnects either
	 * way, and RankSphere notices a dead connection on its next call.
	 *
	 * @param string $reason   One of the ConnectionStore::REASON_* constants.
	 * @param bool   $blocking Wait for the answer (admin action) or not (hooks during other work).
	 */
	public function notify_disconnect( string $reason, bool $blocking ): void {
		$this->post( '/disconnect', array( 'reason' => $reason ), $blocking );
	}

	/**
	 * A signed GET that expects a JSON object back.
	 *
	 * @param string                $endpoint Path below the API URL, with a leading slash.
	 * @param array<string, string> $query    Query parameters (signed with the path).
	 *
	 * @return array<mixed>|\WP_Error
	 */
	public function get( string $endpoint, array $query = array() ): array|\WP_Error {
		$url  = $this->connection->api_url . $endpoint;
		$now  = time();
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$path = is_string( $path ) ? $path : '/';

		try {
			$signature = ( new Signature( $this->connection->secret ) )->sign( $now, 'GET', Signature::canonical_path( $path, $query ), '' );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'ranksphere_bad_secret', $e->getMessage() );
		}

		$response = wp_safe_remote_get(
			array() === $query ? $url : $url . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ),
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'user-agent'  => 'RankSphere-WordPress/' . VERSION,
				'headers'     => array(
					'Authorization'                   => 'Bearer ' . $this->connection->site_token,
					'Accept'                          => 'application/json',
					RequestVerifier::HEADER_TIMESTAMP => (string) $now,
					RequestVerifier::HEADER_SIGNATURE => $signature,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status || ! is_array( $data ) ) {
			return new \WP_Error( 'ranksphere_http_' . $status, 'RankSphere answered with status ' . $status . '.', array( 'status' => $status ) );
		}

		return $data;
	}

	/**
	 * A signed POST.
	 *
	 * @param string               $endpoint Path below the API URL, with a leading slash.
	 * @param array<string, mixed> $payload  JSON body.
	 * @param bool                 $blocking Whether to wait for the answer.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function post( string $endpoint, array $payload, bool $blocking ): array|\WP_Error {
		$url  = $this->connection->api_url . $endpoint;
		$body = (string) wp_json_encode( $payload );
		$now  = time();
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$path = is_string( $path ) ? $path : '/';

		try {
			$signature = ( new Signature( $this->connection->secret ) )->sign( $now, 'POST', Signature::canonical_path( $path, array() ), $body );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'ranksphere_bad_secret', $e->getMessage() );
		}

		return wp_safe_remote_post(
			$url,
			array(
				'blocking'   => $blocking,
				'timeout'    => $blocking ? 10 : 3,
				'user-agent' => 'RankSphere-WordPress/' . VERSION,
				'headers'    => array(
					'Authorization'                   => 'Bearer ' . $this->connection->site_token,
					'Content-Type'                    => 'application/json',
					'Accept'                          => 'application/json',
					RequestVerifier::HEADER_TIMESTAMP => (string) $now,
					RequestVerifier::HEADER_SIGNATURE => $signature,
				),
				'body'       => $body,
			)
		);
	}
}
