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

	// Cached data from RankSphere; the per-page caches expire on their own within ten minutes.
	delete_transient( RankSphere\Insights\Insights::OVERVIEW_CACHE );

	// Bookkeeping on posts (which text, revision of what, history). SEO titles and descriptions
	// written into the posts stay: they are the site's content, and SEO plugins may read them.
	foreach ( array( RankSphere\Content\Drafts::META, RankSphere\Content\Drafts::REVISES_META, RankSphere\Content\Drafts::APPLIED_META, RankSphere\Content\TextHistory::META, RankSphere\Seo\History::META ) as $ranksphere_meta ) {
		delete_post_meta_by_key( $ranksphere_meta );
	}

	// Signatures seen (replay protection).
	$ranksphere_db = $GLOBALS['wpdb'] ?? null;

	if ( $ranksphere_db instanceof wpdb ) {
		$ranksphere_sql = $ranksphere_db->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $ranksphere_db->options, $ranksphere_db->esc_like( 'ranksphere_sig_' ) . '%' );

		if ( is_string( $ranksphere_sql ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- prepared above; the plugin's own rows, once on uninstall.
			$ranksphere_db->query( $ranksphere_sql );
		}
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
