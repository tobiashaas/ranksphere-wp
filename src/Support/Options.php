<?php
/**
 * Option names in one place.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Support;

/**
 * Every option the plugin stores starts with `ranksphere_` – uninstall removes exactly these.
 */
final class Options {

	/** The plugin version whose upgrade routines have run. */
	public const VERSION = 'ranksphere_version';

	/** The connection to RankSphere (project, endpoint, signing secret); empty when not connected. */
	public const CONNECTION = 'ranksphere_connection';

	/** Why and when the last connection ended, for the admin page. */
	public const DISCONNECTED = 'ranksphere_disconnected';

	/** Release channel for updates from RankSphere: stable, rc, beta or alpha. */
	public const UPDATE_CHANNEL = 'ranksphere_update_channel';

	/**
	 * All options, for uninstall.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array( self::VERSION, self::CONNECTION, self::DISCONNECTED, self::UPDATE_CHANNEL );
	}
}
