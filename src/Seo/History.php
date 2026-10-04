<?php
/**
 * Change log per post.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Seo;

/**
 * Every change RankSphere makes to a post's SEO fields: which fields, before and after, who, when.
 * Kept in protected post meta (deleted with the post), the newest MAX entries.
 */
final class History {

	public const META = '_ranksphere_seo_history';

	public const MAX = 20;

	/**
	 * Records a change and returns its ID.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $before  Changed fields before.
	 * @param array<string, mixed> $after   The same fields after.
	 * @param string               $source  "ranksphere" or "undo".
	 */
	public function record( int $post_id, array $before, array $after, string $source = 'ranksphere' ): string {
		$id      = wp_generate_uuid4();
		$entries = $this->all( $post_id );

		$entries[] = array(
			'id'      => $id,
			'at'      => time(),
			'user_id' => get_current_user_id(),
			'source'  => $source,
			'before'  => $before,
			'after'   => $after,
			'undone'  => null,
		);

		update_post_meta( $post_id, self::META, wp_slash( array_slice( $entries, -self::MAX ) ) );

		return $id;
	}

	/**
	 * One entry.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $id      Entry ID.
	 *
	 * @return array{id: string, at: int, user_id: int, source: string, before: array<array-key, mixed>, after: array<array-key, mixed>, undone: ?int}|null
	 */
	public function find( int $post_id, string $id ): ?array {
		foreach ( $this->all( $post_id ) as $entry ) {
			if ( $entry['id'] === $id ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Marks an entry as undone.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $id      Entry ID.
	 */
	public function mark_undone( int $post_id, string $id ): void {
		$entries = array_map(
			static fn ( array $entry ): array => $entry['id'] === $id ? array( 'undone' => time() ) + $entry : $entry,
			$this->all( $post_id )
		);

		update_post_meta( $post_id, self::META, wp_slash( $entries ) );
	}

	/**
	 * All entries, oldest first.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return list<array{id: string, at: int, user_id: int, source: string, before: array<array-key, mixed>, after: array<array-key, mixed>, undone: ?int}>
	 */
	public function all( int $post_id ): array {
		$stored  = get_post_meta( $post_id, self::META, true );
		$entries = array();

		foreach ( is_array( $stored ) ? $stored : array() as $entry ) {
			if ( ! is_array( $entry ) || ! is_string( $entry['id'] ?? null ) || ! is_array( $entry['before'] ?? null ) || ! is_array( $entry['after'] ?? null ) ) {
				continue;
			}

			$entries[] = array(
				'id'      => $entry['id'],
				'at'      => is_int( $entry['at'] ?? null ) ? $entry['at'] : 0,
				'user_id' => is_int( $entry['user_id'] ?? null ) ? $entry['user_id'] : 0,
				'source'  => is_string( $entry['source'] ?? null ) ? $entry['source'] : 'ranksphere',
				'before'  => $entry['before'],
				'after'   => $entry['after'],
				'undone'  => is_int( $entry['undone'] ?? null ) ? $entry['undone'] : null,
			);
		}

		return $entries;
	}
}
