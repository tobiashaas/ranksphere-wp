<?php
/**
 * Where RankSphere lives.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Support;

/**
 * The RankSphere web app. Filterable for development (`ranksphere_app_url`), so a local RankSphere
 * can be connected; production sites never need it.
 */
final class App {

	public const DEFAULT_URL = 'https://ranksphere.cloud';

	/**
	 * Base URL without a trailing slash.
	 */
	public static function url(): string {
		$url = apply_filters( 'ranksphere_app_url', self::DEFAULT_URL );

		return untrailingslashit( is_string( $url ) && '' !== $url ? $url : self::DEFAULT_URL );
	}

	/**
	 * Starts the connection in RankSphere: sign in, pick the project, approve in WordPress.
	 */
	public static function connect_url(): string {
		return add_query_arg( 'site', rawurlencode( home_url( '/' ) ), self::url() . '/wordpress/connect' );
	}
}
