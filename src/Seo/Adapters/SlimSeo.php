<?php
/**
 * Slim SEO.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Seo\Adapters;

use RankSphere\Seo\SeoFields;

/**
 * One array in the post meta `slim_seo`; other keys in it (images, …) are kept. The free version
 * has no focus keyword.
 */
final class SlimSeo extends MetaAdapter {

	private const META = 'slim_seo';

	private const KEYS = array(
		SeoFields::TITLE       => 'title',
		SeoFields::DESCRIPTION => 'description',
		SeoFields::CANONICAL   => 'canonical',
	);

	/**
	 * Slug.
	 */
	public function slug(): string {
		return 'slim-seo';
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
		$data = $this->data( $post_id );
		$text = static fn ( string $key ): ?string => isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) && '' !== trim( (string) $data[ $key ] ) ? (string) $data[ $key ] : null;

		return array(
			SeoFields::TITLE          => $text( 'title' ),
			SeoFields::DESCRIPTION    => $text( 'description' ),
			SeoFields::FOCUS_KEYWORDS => null,
			SeoFields::CANONICAL      => $text( 'canonical' ),
			SeoFields::NOINDEX        => in_array( $data['noindex'] ?? 0, array( 1, '1', true ), true ) ? true : null,
		);
	}

	/**
	 * Partial update of the array.
	 *
	 * @param int                                          $post_id Post ID.
	 * @param array<string, string|bool|list<string>|null> $changes Changes.
	 */
	public function write( int $post_id, array $changes ): void {
		$data = $this->data( $post_id );

		foreach ( self::KEYS as $field => $key ) {
			if ( array_key_exists( $field, $changes ) ) {
				$value = $this->string_or_null( $changes[ $field ] );

				if ( null === $value ) {
					unset( $data[ $key ] );
				} else {
					$data[ $key ] = $value;
				}
			}
		}

		if ( array_key_exists( SeoFields::NOINDEX, $changes ) ) {
			if ( true === $changes[ SeoFields::NOINDEX ] ) {
				$data['noindex'] = 1;
			} else {
				unset( $data['noindex'] );
			}
		}

		$this->store( $post_id, self::META, $data );
	}

	/**
	 * The stored array.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array<array-key, mixed>
	 */
	private function data( int $post_id ): array {
		$data = get_post_meta( $post_id, self::META, true );

		return is_array( $data ) ? $data : array();
	}
}
