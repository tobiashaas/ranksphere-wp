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

	/**
	 * All options, for uninstall.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array( self::VERSION, self::CONNECTION );
	}
}
