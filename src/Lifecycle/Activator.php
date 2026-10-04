<?php
/**
 * Activation.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Lifecycle;

/**
 * Runs on activation. Contacts nothing outside the site: data only goes to RankSphere once an
 * administrator connects the site (WordPress.org guideline 7).
 */
final class Activator {

	/**
	 * Hooked via register_activation_hook().
	 */
	public static function activate(): void {
		Upgrader::maybe_upgrade();
	}
}
