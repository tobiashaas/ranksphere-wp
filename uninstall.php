<?php
/**
 * Removes everything the plugin stored – and nothing else.
 *
 * @package RankSphere
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Deletes the plugin's options on one site.
 */
$ranksphere_cleanup = static function (): void {
	foreach ( RankSphere\Support\Options::all() as $ranksphere_option ) {
		delete_option( $ranksphere_option );
	}
};

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $ranksphere_site_id ) {
		switch_to_blog( (int) $ranksphere_site_id );
		$ranksphere_cleanup();
		restore_current_blog();
	}
} else {
	$ranksphere_cleanup();
}
