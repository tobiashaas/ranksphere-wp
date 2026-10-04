<?php
/**
 * Yoast SEO.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Seo\Adapters;

use RankSphere\Seo\SeoFields;

/**
 * Post meta through WPSEO_Meta, then the indexable is rebuilt – Yoast renders from its indexables
 * table, which would otherwise only catch up at the next save in the editor.
 */
final class Yoast extends MetaAdapter {

	private const PREFIX = '_yoast_wpseo_';

	/** The class Yoast uses to rebuild a post's indexable when it is saved. */
	private const WATCHER = 'Yoast\\WP\\SEO\\Integrations\\Watchers\\Indexable_Post_Watcher';

	/** Yoast's own keys (without prefix) per field. */
	private const KEYS = array(
		SeoFields::TITLE       => 'title',
		SeoFields::DESCRIPTION => 'metadesc',
		SeoFields::CANONICAL   => 'canonical',
	);

	/**
	 * Slug.
	 */
	public function slug(): string {
		return 'wordpress-seo';
	}

	/**
	 * All five; the free version keeps one focus keyword.
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
		$focus   = $this->text( $post_id, self::PREFIX . 'focuskw' );
		$noindex = $this->text( $post_id, self::PREFIX . 'meta-robots-noindex' );

		return array(
			SeoFields::TITLE          => $this->text( $post_id, self::PREFIX . 'title' ),
			SeoFields::DESCRIPTION    => $this->text( $post_id, self::PREFIX . 'metadesc' ),
			SeoFields::FOCUS_KEYWORDS => null === $focus ? null : array( $focus ),
			SeoFields::CANONICAL      => $this->text( $post_id, self::PREFIX . 'canonical' ),
			// 1 = noindex, 2 = index, 0 or missing = the post type's default.
			SeoFields::NOINDEX        => '1' === $noindex ? true : ( '2' === $noindex ? false : null ),
		);
	}

	/**
	 * Partial update, then the indexable.
	 *
	 * @param int                                          $post_id Post ID.
	 * @param array<string, string|bool|list<string>|null> $changes Changes.
	 */
	public function write( int $post_id, array $changes ): void {
		foreach ( self::KEYS as $field => $key ) {
			if ( array_key_exists( $field, $changes ) ) {
				$this->set( $post_id, $key, $this->string_or_null( $changes[ $field ] ) );
			}
		}

		if ( array_key_exists( SeoFields::FOCUS_KEYWORDS, $changes ) ) {
			$keywords = $changes[ SeoFields::FOCUS_KEYWORDS ];
			$this->set( $post_id, 'focuskw', is_array( $keywords ) && isset( $keywords[0] ) ? $keywords[0] : null );
		}

		if ( array_key_exists( SeoFields::NOINDEX, $changes ) ) {
			$noindex = $changes[ SeoFields::NOINDEX ];
			$this->set( $post_id, 'meta-robots-noindex', is_bool( $noindex ) ? ( $noindex ? '1' : '2' ) : null );
		}

		$this->rebuild_indexable( $post_id );
	}

	/**
	 * Through WPSEO_Meta when Yoast is loaded, so its sanitising and hooks apply.
	 *
	 * @param int         $post_id Post ID.
	 * @param string      $key     Yoast key without prefix.
	 * @param string|null $value   Value; null deletes.
	 */
	private function set( int $post_id, string $key, ?string $value ): void {
		if ( ! class_exists( \WPSEO_Meta::class ) ) {
			$this->store( $post_id, self::PREFIX . $key, $value );

			return;
		}

		if ( null === $value ) {
			\WPSEO_Meta::delete( $key, $post_id );
		} else {
			\WPSEO_Meta::set_value( $key, $value, $post_id );
		}
	}

	/**
	 * Yoast's own watcher method, the same one that runs when a post is saved.
	 *
	 * @param int $post_id Post ID.
	 */
	private function rebuild_indexable( int $post_id ): void {
		if ( ! function_exists( 'YoastSEO' ) ) {
			return;
		}

		try {
			$main    = YoastSEO();
			$classes = is_object( $main ) && isset( $main->classes ) && is_object( $main->classes ) ? $main->classes : null;

			if ( null === $classes || ! method_exists( $classes, 'get' ) ) {
				return;
			}

			$watcher = $classes->get( self::WATCHER );

			if ( is_object( $watcher ) && method_exists( $watcher, 'build_indexable' ) ) {
				$watcher->build_indexable( $post_id );
			}
		} catch ( \Throwable ) {
			// The meta is saved; Yoast rebuilds the indexable at the next save.
			return;
		}
	}
}
