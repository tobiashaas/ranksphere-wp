<?php
/**
 * What RankSphere did with a post's text.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Content;

/**
 * The text side of a post's history (the SEO side is Seo\History): written by RankSphere, a
 * revision saved as draft, a revision taken over into the original, RankSphere's text put into a
 * draft. Entries that replaced content point to the WordPress revision with the content before –
 * WordPress' own screen compares and restores it, nothing is stored twice. Protected post meta,
 * deleted with the post, the newest MAX entries.
 */
final class TextHistory {

	public const META = '_ranksphere_text_history';

	public const MAX = 20;

	/** A new post written by RankSphere. */
	public const CREATED = 'created';

	/** RankSphere's text put into this (unpublished) post. */
	public const REPLACED = 'replaced';

	/** A revision of this (published) post saved as separate draft. */
	public const REVISION = 'revision';

	/** A revision taken over into this post in the editor. */
	public const APPLIED = 'applied';

	/**
	 * Records an event.
	 *
	 * @param int    $post_id     The post.
	 * @param string $type        One of the constants.
	 * @param int    $text        RankSphere's text id (0 unknown).
	 * @param int    $revision    The revision draft (REVISION, APPLIED), else 0.
	 * @param int    $wp_revision The WordPress revision holding the content before (REPLACED, APPLIED), else 0.
	 */
	public function record( int $post_id, string $type, int $text = 0, int $revision = 0, int $wp_revision = 0 ): void {
		$entries   = $this->all( $post_id );
		$entries[] = array(
			'type'        => $type,
			'at'          => time(),
			'user_id'     => get_current_user_id(),
			'text'        => $text,
			'revision'    => $revision,
			'wp_revision' => $wp_revision,
		);

		update_post_meta( $post_id, self::META, wp_slash( array_slice( $entries, -self::MAX ) ) );
	}

	/**
	 * All entries, oldest first.
	 *
	 * @param int $post_id The post.
	 *
	 * @return list<array{type: string, at: int, user_id: int, text: int, revision: int, wp_revision: int}>
	 */
	public function all( int $post_id ): array {
		$stored  = get_post_meta( $post_id, self::META, true );
		$entries = array();

		foreach ( is_array( $stored ) ? $stored : array() as $entry ) {
			if ( ! is_array( $entry ) || ! is_string( $entry['type'] ?? null ) ) {
				continue;
			}

			$entries[] = array(
				'type'        => $entry['type'],
				'at'          => is_int( $entry['at'] ?? null ) ? $entry['at'] : 0,
				'user_id'     => is_int( $entry['user_id'] ?? null ) ? $entry['user_id'] : 0,
				'text'        => is_int( $entry['text'] ?? null ) ? $entry['text'] : 0,
				'revision'    => is_int( $entry['revision'] ?? null ) ? $entry['revision'] : 0,
				'wp_revision' => is_int( $entry['wp_revision'] ?? null ) ? $entry['wp_revision'] : 0,
			);
		}

		return $entries;
	}

	/**
	 * Stores the post's current content as WordPress revision before RankSphere replaces it, and
	 * returns that revision (0 when the post type keeps no revisions).
	 *
	 * @param int $post_id The post.
	 */
	public static function keep_current( int $post_id ): int {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! wp_revisions_enabled( $post ) ) {
			return 0;
		}

		$saved = wp_save_post_revision( $post_id );

		if ( is_int( $saved ) && $saved > 0 ) {
			return $saved;
		}

		// Nothing changed since the latest revision: that one holds the content.
		$latest = wp_get_post_revisions(
			$post_id,
			array(
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		$first  = reset( $latest );

		return is_int( $first ) ? $first : ( $first instanceof \WP_Post ? $first->ID : 0 );
	}
}
