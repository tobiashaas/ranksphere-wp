<?php
/**
 * The plugin's own fields, for sites without an SEO plugin.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Seo\Adapters;

use RankSphere\Seo\SeoFields;

/**
 * Stored in protected post meta (leading underscore); NativeOutput prints them in the page head.
 */
final class Native extends MetaAdapter {

	public const TITLE = '_ranksphere_title';

	public const DESCRIPTION = '_ranksphere_description';

	public const FOCUS_KEYWORDS = '_ranksphere_focus_keywords';

	public const CANONICAL = '_ranksphere_canonical';

	public const NOINDEX = '_ranksphere_noindex';

	/**
	 * Slug.
	 */
	public function slug(): string {
		return 'ranksphere';
	}

	/**
	 * All five fields.
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
		$noindex = $this->text( $post_id, self::NOINDEX );

		return array(
			SeoFields::TITLE          => $this->text( $post_id, self::TITLE ),
			SeoFields::DESCRIPTION    => $this->text( $post_id, self::DESCRIPTION ),
			SeoFields::FOCUS_KEYWORDS => $this->split( $this->text( $post_id, self::FOCUS_KEYWORDS ) ),
			SeoFields::CANONICAL      => $this->text( $post_id, self::CANONICAL ),
			SeoFields::NOINDEX        => null === $noindex ? null : '1' === $noindex,
		);
	}

	/**
	 * Partial update.
	 *
	 * @param int                                          $post_id Post ID.
	 * @param array<string, string|bool|list<string>|null> $changes Changes.
	 */
	public function write( int $post_id, array $changes ): void {
		$keys = array(
			SeoFields::TITLE       => self::TITLE,
			SeoFields::DESCRIPTION => self::DESCRIPTION,
			SeoFields::CANONICAL   => self::CANONICAL,
		);

		foreach ( $keys as $field => $key ) {
			if ( array_key_exists( $field, $changes ) ) {
				$this->store( $post_id, $key, $this->string_or_null( $changes[ $field ] ) );
			}
		}

		if ( array_key_exists( SeoFields::FOCUS_KEYWORDS, $changes ) ) {
			$this->store( $post_id, self::FOCUS_KEYWORDS, $this->join( $changes[ SeoFields::FOCUS_KEYWORDS ] ) );
		}

		if ( array_key_exists( SeoFields::NOINDEX, $changes ) ) {
			$noindex = $changes[ SeoFields::NOINDEX ];
			$this->store( $post_id, self::NOINDEX, is_bool( $noindex ) ? ( $noindex ? '1' : '0' ) : null );
		}
	}
}
