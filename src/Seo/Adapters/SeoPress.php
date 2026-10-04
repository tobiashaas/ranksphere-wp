<?php
/**
 * SEOPress.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Seo\Adapters;

use RankSphere\Seo\SeoFields;

/**
 * Post meta – the same keys SEOPress' own abilities write. noindex is "yes" or absent; SEOPress
 * has no explicit "index" per post, so false and null both remove it.
 */
final class SeoPress extends MetaAdapter {

	private const KEYS = array(
		SeoFields::TITLE       => '_seopress_titles_title',
		SeoFields::DESCRIPTION => '_seopress_titles_desc',
		SeoFields::CANONICAL   => '_seopress_robots_canonical',
	);

	/**
	 * Slug.
	 */
	public function slug(): string {
		return 'wp-seopress';
	}

	/**
	 * All five (target keywords comma-separated).
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
		return array(
			SeoFields::TITLE          => $this->text( $post_id, '_seopress_titles_title' ),
			SeoFields::DESCRIPTION    => $this->text( $post_id, '_seopress_titles_desc' ),
			SeoFields::FOCUS_KEYWORDS => $this->split( $this->text( $post_id, '_seopress_analysis_target_kw' ) ),
			SeoFields::CANONICAL      => $this->text( $post_id, '_seopress_robots_canonical' ),
			SeoFields::NOINDEX        => 'yes' === $this->text( $post_id, '_seopress_robots_index' ) ? true : null,
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
			$this->store( $post_id, '_seopress_analysis_target_kw', $this->join( $changes[ SeoFields::FOCUS_KEYWORDS ] ) );
		}

		if ( array_key_exists( SeoFields::NOINDEX, $changes ) ) {
			$this->store( $post_id, '_seopress_robots_index', true === $changes[ SeoFields::NOINDEX ] ? 'yes' : null );
		}
	}
}
