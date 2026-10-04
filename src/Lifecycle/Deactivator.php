<?php
/**
 * Deactivation.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Lifecycle;

/**
 * Runs on deactivation: stops scheduled work, keeps settings (uninstall removes them).
 */
final class Deactivator {

	/** Cron hooks the plugin schedules. */
	public const CRON_HOOKS = array( 'ranksphere_sync' );

	/**
	 * Hooked via register_deactivation_hook().
	 */
	public static function deactivate(): void {
		foreach ( self::CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
}
