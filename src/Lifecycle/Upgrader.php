<?php
/**
 * Upgrade routines.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Lifecycle;

use RankSphere\Support\Options;

use const RankSphere\VERSION;

/**
 * Runs upgrade steps once per new version – activation hooks do not fire on updates.
 */
final class Upgrader {

	/**
	 * Compares the stored version with the code and runs what is missing.
	 */
	public static function maybe_upgrade(): void {
		$stored = get_option( Options::VERSION, '' );

		if ( VERSION === $stored ) {
			return;
		}

		// Future schema or data migrations go here, each guarded by version_compare( $stored, 'x.y.z', '<' ).

		update_option( Options::VERSION, VERSION, false );
	}
}
