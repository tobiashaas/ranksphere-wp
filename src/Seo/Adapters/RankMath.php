<?php
/**
 * Rank Math.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Seo\Adapters;

use RankSphere\Seo\SeoFields;

/**
 * Post meta; Rank Math has no write ability for these fields (not even in Pro). Robots are an
 * array of directives; only index/noindex are touched. The sitemap cache is invalidated after.
 */
final class RankMath extends MetaAdapter {

	private const KEYS = array(
		SeoFields::TITLE       => 'rank_math_title',
		SeoFields::DESCRIPTION => 'rank_math_description',
		SeoFields::CANONICAL   => 'rank_math_canonical_url',
	);

	private const ROBOTS = 'rank_math_robots';

	/**
	 * Slug.
	 */
	public function slug(): string {
		return 'seo-by-rank-math';
	}

	/**
	 * All five (keywords comma-separated, the first is the main one).
	 *
	 * @return list<string>
	 */
	public function supports(): array {
		return SeoFields::ALL;
	}

	/**
	 * Raw values.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array{title: ?string, description: ?string, focus_keywords: ?list<string>, canonical: ?string, noindex: ?bool}
	 */
	public function read( int $post_id ): array {
		$robots = $this->robots( $post_id );

		return array(
			SeoFields::TITLE          => $this->text( $post_id, 'rank_math_title' ),
			SeoFields::DESCRIPTION    => $this->text( $post_id, 'rank_math_description' ),
			SeoFields::FOCUS_KEYWORDS => $this->split( $this->text( $post_id, 'rank_math_focus_keyword' ) ),
			SeoFields::CANONICAL      => $this->text( $post_id, 'rank_math_canonical_url' ),
			SeoFields::NOINDEX        => in_array( 'noindex', $robots, true ) ? true : ( in_array( 'index', $robots, true ) ? false : null ),
		);
	}

	/**
	 * Partial update.
	 *
	 * @param int                                          $post_id Post ID.
	 * @param array<string, string|bool|list<string>|null> $changes Changes.
	 */
	public function write( int $post_id, array $changes ): void {
		foreach ( self::KEYS as $field => $key ) {
			if ( array_key_exists( $field, $changes ) ) {
				$this->store( $post_id, $key, $this->string_or_null( $changes[ $field ] ) );
			}
		}

		if ( array_key_exists( SeoFields::FOCUS_KEYWORDS, $changes ) ) {
			$this->store( $post_id, 'rank_math_focus_keyword', $this->join( $changes[ SeoFields::FOCUS_KEYWORDS ] ) );
		}

		if ( array_key_exists( SeoFields::NOINDEX, $changes ) ) {
			$noindex = $changes[ SeoFields::NOINDEX ];
			$robots  = array_values( array_diff( $this->robots( $post_id ), array( 'index', 'noindex' ) ) );

			if ( is_bool( $noindex ) ) {
				array_unshift( $robots, $noindex ? 'noindex' : 'index' );
			}

			$this->store( $post_id, self::ROBOTS, $robots );
		}

		if ( class_exists( \RankMath\Sitemap\Cache_Watcher::class ) ) {
			\RankMath\Sitemap\Cache_Watcher::invalidate_post( $post_id );
		}
	}

	/**
	 * The stored directives.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return list<string>
	 */
	private function robots( int $post_id ): array {
		$robots = get_post_meta( $post_id, self::ROBOTS, true );

		return is_array( $robots ) ? array_values( array_filter( $robots, 'is_string' ) ) : array();
	}
}
