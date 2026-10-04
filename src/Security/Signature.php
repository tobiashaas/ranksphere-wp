<?php
/**
 * Request signatures between RankSphere and the site.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Security;

/**
 * HMAC-SHA256 over timestamp, method, path and body with the secret both sides agreed on when
 * connecting. A signature older than the tolerance is rejected, so a captured request cannot be
 * replayed later. Pure PHP – no WordPress functions – so it is unit-tested on its own.
 */
final class Signature {

	/** Seconds a signed request stays valid (clock drift included). */
	public const TOLERANCE = 300;

	/**
	 * Takes the secret agreed on when connecting.
	 *
	 * @param string $secret Shared secret, at least 32 bytes.
	 *
	 * @throws \InvalidArgumentException When the secret is too short to be safe.
	 */
	public function __construct( private readonly string $secret ) {
		if ( strlen( $secret ) < 32 ) {
			throw new \InvalidArgumentException( 'The signing secret must be at least 32 bytes long.' );
		}
	}

	/**
	 * The signature for a request.
	 *
	 * @param int    $timestamp Unix time the request was signed.
	 * @param string $method    HTTP method, e.g. POST.
	 * @param string $path      Request path including the query string.
	 * @param string $body      Raw request body.
	 */
	public function sign( int $timestamp, string $method, string $path, string $body ): string {
		return hash_hmac( 'sha256', $timestamp . "\n" . strtoupper( $method ) . "\n" . $path . "\n" . $body, $this->secret );
	}

	/**
	 * The path both sides sign: the path itself plus the query with its keys sorted, so the order
	 * a client sends parameters in does not matter. For calls into WordPress the path is the REST
	 * route (`/ranksphere/v1/status`) – independent of permalinks and of a subdirectory install.
	 *
	 * @param string                  $path  Path with a leading slash, no query.
	 * @param array<array-key, mixed> $query Query parameters.
	 */
	public static function canonical_path( string $path, array $query ): string {
		if ( array() === $query ) {
			return $path;
		}

		ksort( $query, SORT_STRING );

		return $path . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Whether a received signature is valid and fresh.
	 *
	 * @param string $signature Hex signature from the request.
	 * @param int    $timestamp Timestamp from the request.
	 * @param string $method    HTTP method.
	 * @param string $path      Request path including the query string.
	 * @param string $body      Raw request body.
	 * @param int    $now       Current Unix time.
	 */
	public function verify( string $signature, int $timestamp, string $method, string $path, string $body, int $now ): bool {
		if ( abs( $now - $timestamp ) > self::TOLERANCE ) {
			return false;
		}

		return hash_equals( $this->sign( $timestamp, $method, $path, $body ), $signature );
	}
}
