<?php
/**
 * Release channels.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Updates;

/**
 * Alpha, beta and release candidates come before a stable release (1.0.0-alpha.1 < 1.0.0-beta.1 <
 * 1.0.0-rc.1 < 1.0.0 – PHP's version_compare orders them like that). A channel receives its own
 * stage and every more stable one. Pure PHP, unit-tested on its own.
 */
final class Channel {

	public const STABLE = 'stable';

	public const RC = 'rc';

	public const BETA = 'beta';

	public const ALPHA = 'alpha';

	/** Most stable first. */
	public const ALL = array( self::STABLE, self::RC, self::BETA, self::ALPHA );

	/**
	 * The stage of a version: "1.0.0-beta.2" → beta, "1.0.0" → stable.
	 *
	 * @param string $version Plugin version.
	 */
	public static function of( string $version ): string {
		if ( 1 === preg_match( '/-(alpha|beta|rc)\b/i', $version, $match ) ) {
			return strtolower( $match[1] );
		}

		return self::STABLE;
	}

	/**
	 * Whether a channel receives a version.
	 *
	 * @param string $channel One of ALL.
	 * @param string $version Offered version.
	 */
	public static function accepts( string $channel, string $version ): bool {
		$wanted = array_search( self::sanitize( $channel, self::STABLE ), self::ALL, true );
		$stage  = array_search( self::of( $version ), self::ALL, true );

		return false !== $wanted && false !== $stage && $stage <= $wanted;
	}

	/**
	 * A valid channel, or the fallback.
	 *
	 * @param mixed  $channel  Stored or submitted value.
	 * @param string $fallback Used when the value is no channel.
	 */
	public static function sanitize( mixed $channel, string $fallback ): string {
		return is_string( $channel ) && in_array( $channel, self::ALL, true ) ? $channel : $fallback;
	}
}
