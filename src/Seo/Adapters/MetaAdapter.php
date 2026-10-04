<?php
/**
 * Shared helpers for adapters that store in post meta.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Seo\Adapters;

use RankSphere\Seo\Adapter;

/**
 * Post meta in, post meta out; an empty value deletes the key so the SEO plugin falls back to its
 * own default.
 */
abstract class MetaAdapter implements Adapter {

	/**
	 * A meta value as text; null when missing or empty.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 */
	protected function text( int $post_id, string $key ): ?string {
		$value = get_post_meta( $post_id, $key, true );

		return is_scalar( $value ) && '' !== trim( (string) $value ) ? (string) $value : null;
	}

	/**
	 * Stores a value, or deletes the key for null.
	 *
	 * @param int                          $post_id Post ID.
	 * @param string                       $key     Meta key.
	 * @param string|int|array<mixed>|null $value   Value.
	 */
	protected function store( int $post_id, string $key, string|int|array|null $value ): void {
		if ( null === $value || '' === $value || array() === $value ) {
			delete_post_meta( $post_id, $key );

			return;
		}

		// update_post_meta() unslashes; keep backslashes in titles intact.
		update_post_meta( $post_id, $key, wp_slash( $value ) );
	}

	/**
	 * "a, b" → ["a", "b"]; null when empty.
	 *
	 * @param string|null $keywords Comma-separated keywords.
	 *
	 * @return list<string>|null
	 */
	protected function split( ?string $keywords ): ?array {
		$keywords = array_values( array_filter( array_map( 'trim', explode( ',', (string) $keywords ) ), static fn ( string $keyword ): bool => '' !== $keyword ) );

		return array() === $keywords ? null : $keywords;
	}

	/**
	 * ["a", "b"] → "a,b"; null for none.
	 *
	 * @param mixed $keywords List from SeoFields::changes().
	 */
	protected function join( mixed $keywords ): ?string {
		$keywords = is_array( $keywords ) ? array_filter( $keywords, 'is_string' ) : array();

		return array() !== $keywords ? implode( ',', $keywords ) : null;
	}

	/**
	 * A text value from the changes.
	 *
	 * @param mixed $value Value.
	 */
	protected function string_or_null( mixed $value ): ?string {
		return is_string( $value ) ? $value : null;
	}
}
