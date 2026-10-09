<?php
/**
 * Checks that a REST request really comes from the connected RankSphere project.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Security;

use RankSphere\Connection\Connection;
use RankSphere\Connection\ConnectionStore;
use WP_Error;
use WP_REST_Request;

/**
 * Two locks, both required: the request is authenticated with the application password that was
 * approved for RankSphere (as the user who approved it), and it carries a fresh HMAC signature
 * made with the connection's secret. A signature is accepted once – a captured request cannot
 * be sent again, not even within the time window.
 */
final class RequestVerifier {

	public const HEADER_TIMESTAMP = 'X-RankSphere-Timestamp';

	public const HEADER_SIGNATURE = 'X-RankSphere-Signature';

	/**
	 * Takes the connection store.
	 *
	 * @param ConnectionStore $store Where the connection lives.
	 */
	public function __construct( private readonly ConnectionStore $store = new ConnectionStore() ) {}

	/**
	 * The connection the request belongs to, or why it is refused.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function verify( WP_REST_Request $request ): Connection|WP_Error {
		$connection = $this->store->get();

		if ( null === $connection ) {
			return new WP_Error( 'ranksphere_not_connected', __( 'This site is not connected to RankSphere.', 'ranksphere' ), array( 'status' => 403 ) );
		}

		$signature = $this->signature_error( $request, $connection );

		if ( null !== $signature ) {
			return $signature;
		}

		if ( get_current_user_id() !== $connection->user_id || rest_get_authenticated_app_password() !== $connection->app_password_uuid ) {
			return new WP_Error( 'ranksphere_forbidden', __( 'Only the application password approved for RankSphere may do this.', 'ranksphere' ), array( 'status' => 403 ) );
		}

		return $connection;
	}

	/**
	 * Null when the signature is valid, fresh and unused; otherwise the error.
	 *
	 * @param WP_REST_Request $request    The request.
	 * @param Connection      $connection The connection whose secret signs.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	private function signature_error( WP_REST_Request $request, Connection $connection ): ?WP_Error {
		$timestamp = (string) $request->get_header( self::HEADER_TIMESTAMP );
		$signature = strtolower( (string) $request->get_header( self::HEADER_SIGNATURE ) );
		$refused   = new WP_Error( 'ranksphere_bad_signature', __( 'The request signature is missing, invalid or expired.', 'ranksphere' ), array( 'status' => 401 ) );

		if ( 1 !== preg_match( '/^\d{1,12}$/', $timestamp ) || 1 !== preg_match( '/^[0-9a-f]{64}$/', $signature ) ) {
			return $refused;
		}

		$query = $request->get_query_params();
		unset( $query['rest_route'] );
		$path = Signature::canonical_path( $request->get_route(), $query );

		try {
			$valid = ( new Signature( $connection->secret ) )->verify( $signature, (int) $timestamp, $request->get_method(), $path, (string) $request->get_body(), time() );
		} catch ( \InvalidArgumentException ) {
			$valid = false;
		}

		if ( ! $valid ) {
			return $refused;
		}

		// Each signature only once: remembered a little longer than it could be valid.
		return self::first_use( substr( $signature, 0, 40 ) ) ? null : $refused;
	}

	/**
	 * Whether a signature is used for the first time – atomically, so two copies of a request sent
	 * at the same moment cannot both pass. A persistent object cache adds atomically; without one
	 * the options table's unique name does (INSERT IGNORE), cleaned up now and then.
	 *
	 * @param string $key Start of the signature (hex).
	 */
	public static function first_use( string $key ): bool {
		$name = 'ranksphere_sig_' . $key;

		if ( wp_using_ext_object_cache() ) {
			return wp_cache_add( $name, 1, 'ranksphere_signatures', 2 * Signature::TOLERANCE );
		}

		$wpdb = $GLOBALS['wpdb'] ?? null;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		$insert = $wpdb->prepare( "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $wpdb->options, $name, (string) time() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- prepared above; an atomic insert is the point, nothing to cache.
		$inserted = is_string( $insert ) ? $wpdb->query( $insert ) : false;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- not security relevant, only spreads the cleanup.
		if ( 1 === mt_rand( 1, 50 ) ) {
			$expired = $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s AND option_value < %d', $wpdb->options, $wpdb->esc_like( 'ranksphere_sig_' ) . '%', time() - 2 * Signature::TOLERANCE );

			if ( is_string( $expired ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- prepared above; removes the plugin's own expired rows.
				$wpdb->query( $expired );
			}
		}

		return 1 === $inserted;
	}
}
