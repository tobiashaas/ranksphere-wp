<?php
/**
 * All in One SEO.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Seo\Adapters;

use RankSphere\Seo\SeoFields;

/**
 * AIOSEO's source of truth is its own table (aioseo_posts); its `_aioseo_*` post meta is only a
 * copy. Read and written through its model – savePost() is a partial update since 5.0 and keeps
 * the table, the meta copy and AIOSEO's caches in step. Keywords: focus + additional keyphrases.
 */
final class Aioseo implements \RankSphere\Seo\Adapter {

	private const MODEL = '\AIOSEO\Plugin\Common\Models\Post';

	/**
	 * Slug.
	 */
	public function slug(): string {
		return 'all-in-one-seo-pack';
	}

	/**
	 * All five.
	 *
	 * @return list<string>
	 */
	public function supports(): array {
		return SeoFields::ALL;
	}

	/**
	 * Raw values from the model.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array{title: ?string, description: ?string, focus_keywords: ?list<string>, canonical: ?string, noindex: ?bool}
	 */
	public function read( int $post_id ): array {
		$fields = SeoFields::empty();
		$model  = $this->model( $post_id );

		if ( null === $model ) {
			return $fields;
		}

		$values = get_object_vars( $model );
		$text   = static fn ( string $key ): ?string => isset( $values[ $key ] ) && is_scalar( $values[ $key ] ) && '' !== trim( (string) $values[ $key ] ) ? (string) $values[ $key ] : null;
		$flag   = static fn ( string $key, bool $fallback ): bool => isset( $values[ $key ] ) && is_scalar( $values[ $key ] ) ? in_array( (string) $values[ $key ], array( '1', 'true' ), true ) : $fallback;

		$fields[ SeoFields::TITLE ]       = $text( 'title' );
		$fields[ SeoFields::DESCRIPTION ] = $text( 'description' );
		$fields[ SeoFields::CANONICAL ]   = $text( 'canonical_url' );

		// robots_default on: the post follows the site-wide setting – "default" for RankSphere.
		if ( ! $flag( 'robots_default', true ) ) {
			$fields[ SeoFields::NOINDEX ] = $flag( 'robots_noindex', false );
		}

		$fields[ SeoFields::FOCUS_KEYWORDS ] = $this->keywords( $values['keyphrases'] ?? null );

		return $fields;
	}

	/**
	 * Partial update through savePost().
	 *
	 * @param int                                          $post_id Post ID.
	 * @param array<string, string|bool|list<string>|null> $changes Changes.
	 *
	 * @throws \RuntimeException When AIOSEO is not loaded or reports a database error.
	 */
	public function write( int $post_id, array $changes ): void {
		$model = self::MODEL;

		if ( ! class_exists( $model ) || ! is_callable( array( $model, 'savePost' ) ) ) {
			throw new \RuntimeException( 'All in One SEO is not loaded.' );
		}

		$data = array();

		if ( array_key_exists( SeoFields::TITLE, $changes ) ) {
			$data['title'] = $changes[ SeoFields::TITLE ];
		}

		if ( array_key_exists( SeoFields::DESCRIPTION, $changes ) ) {
			$data['description'] = $changes[ SeoFields::DESCRIPTION ];
		}

		if ( array_key_exists( SeoFields::CANONICAL, $changes ) ) {
			$data['canonical_url'] = $changes[ SeoFields::CANONICAL ];
		}

		if ( array_key_exists( SeoFields::NOINDEX, $changes ) ) {
			// savePost() maps "default"/"noindex" to the columns robots_default/robots_noindex.
			$noindex         = $changes[ SeoFields::NOINDEX ];
			$data['default'] = ! is_bool( $noindex );
			$data['noindex'] = true === $noindex;
		}

		if ( array_key_exists( SeoFields::FOCUS_KEYWORDS, $changes ) ) {
			$keywords           = is_array( $changes[ SeoFields::FOCUS_KEYWORDS ] ) ? $changes[ SeoFields::FOCUS_KEYWORDS ] : array();
			$data['keyphrases'] = array() === $keywords ? null : array(
				'focus'      => array( 'keyphrase' => $keywords[0] ),
				'additional' => array_map( static fn ( string $keyword ): array => array( 'keyphrase' => $keyword ), array_slice( $keywords, 1 ) ),
			);
		}

		if ( array() === $data ) {
			return;
		}

		$error = call_user_func( array( $model, 'savePost' ), $post_id, $data );

		if ( is_string( $error ) && '' !== $error ) {
			throw new \RuntimeException( 'All in One SEO could not save: ' . $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never printed, becomes a REST error.
		}
	}

	/**
	 * AIOSEO's model for the post, fresh from the database.
	 *
	 * @param int $post_id Post ID.
	 */
	private function model( int $post_id ): ?object {
		$model = self::MODEL;

		if ( ! class_exists( $model ) || ! is_callable( array( $model, 'getPost' ) ) ) {
			return null;
		}

		$post = call_user_func( array( $model, 'getPost' ), $post_id );

		return is_object( $post ) ? $post : null;
	}

	/**
	 * Focus + additional keyphrases from the stored JSON (string or decoded object).
	 *
	 * @param mixed $keyphrases Stored value.
	 *
	 * @return list<string>|null
	 */
	private function keywords( mixed $keyphrases ): ?array {
		$data = json_decode( is_string( $keyphrases ) ? $keyphrases : (string) wp_json_encode( $keyphrases ), true );

		if ( ! is_array( $data ) ) {
			return null;
		}

		$keywords = array();
		$focus    = is_array( $data['focus'] ?? null ) ? ( $data['focus']['keyphrase'] ?? null ) : null;

		if ( is_string( $focus ) && '' !== trim( $focus ) ) {
			$keywords[] = $focus;
		}

		foreach ( is_array( $data['additional'] ?? null ) ? $data['additional'] : array() as $additional ) {
			$keyword = is_array( $additional ) ? ( $additional['keyphrase'] ?? null ) : null;

			if ( is_string( $keyword ) && '' !== trim( $keyword ) ) {
				$keywords[] = $keyword;
			}
		}

		return array() === $keywords ? null : $keywords;
	}
}
