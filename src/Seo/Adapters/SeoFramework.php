<?php
/**
 * The SEO Framework.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Seo\Adapters;

use RankSphere\Seo\SeoFields;

/**
 * Through TSF's own post data class, one item at a time – never save_meta() with a partial array,
 * which would drop the items not passed. No focus keyword without its paid extension.
 */
final class SeoFramework extends MetaAdapter {

	private const DATA = '\The_SEO_Framework\Data\Plugin\Post';

	private const KEYS = array(
		SeoFields::TITLE       => '_genesis_title',
		SeoFields::DESCRIPTION => '_genesis_description',
		SeoFields::CANONICAL   => '_genesis_canonical_uri',
	);

	/**
	 * Slug.
	 */
	public function slug(): string {
		return 'autodescription';
	}

	/**
	 * No focus keyword.
	 *
	 * @return list<string>
	 */
	public function supports(): array {
		return array( SeoFields::TITLE, SeoFields::DESCRIPTION, SeoFields::CANONICAL, SeoFields::NOINDEX );
	}

	/**
	 * Raw values.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array{title: ?string, description: ?string, focus_keywords: ?list<string>, canonical: ?string, noindex: ?bool}
	 */
	public function read( int $post_id ): array {
		$noindex = $this->text( $post_id, '_genesis_noindex' );

		return array(
			SeoFields::TITLE          => $this->text( $post_id, '_genesis_title' ),
			SeoFields::DESCRIPTION    => $this->text( $post_id, '_genesis_description' ),
			SeoFields::FOCUS_KEYWORDS => null,
			SeoFields::CANONICAL      => $this->text( $post_id, '_genesis_canonical_uri' ),
			// 1 = noindex, -1 = index, 0 = default.
			SeoFields::NOINDEX        => '1' === $noindex ? true : ( '-1' === $noindex ? false : null ),
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
				$this->item( $post_id, $key, $this->string_or_null( $changes[ $field ] ) ?? '' );
			}
		}

		if ( array_key_exists( SeoFields::NOINDEX, $changes ) ) {
			$noindex = $changes[ SeoFields::NOINDEX ];
			$this->item( $post_id, '_genesis_noindex', is_bool( $noindex ) ? ( $noindex ? 1 : -1 ) : 0 );
		}
	}

	/**
	 * One item through TSF when loaded, otherwise its post meta key.
	 *
	 * @param int        $post_id Post ID.
	 * @param string     $key     TSF item.
	 * @param string|int $value   Value ('' / 0 = default).
	 */
	private function item( int $post_id, string $key, string|int $value ): void {
		$data = self::DATA;

		if ( class_exists( $data ) && is_callable( array( $data, 'update_single_meta_item' ) ) ) {
			call_user_func( array( $data, 'update_single_meta_item' ), $key, $value, $post_id );

			return;
		}

		$this->store( $post_id, $key, '' === $value || 0 === $value ? null : $value );
	}
}
