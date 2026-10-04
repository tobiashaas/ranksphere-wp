<?php
/**
 * The SEO fields RankSphere reads and writes.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Seo;

/**
 * Title, description, focus keywords, canonical and noindex – the same five fields for every SEO
 * plugin. `null` always means "the SEO plugin's own default" (its template, no canonical, the
 * site-wide robots setting); writing null clears the field. Pure PHP, unit-tested.
 */
final class SeoFields {

	public const TITLE = 'title';

	public const DESCRIPTION = 'description';

	public const FOCUS_KEYWORDS = 'focus_keywords';

	public const CANONICAL = 'canonical';

	public const NOINDEX = 'noindex';

	public const ALL = array( self::TITLE, self::DESCRIPTION, self::FOCUS_KEYWORDS, self::CANONICAL, self::NOINDEX );

	/** At most this many focus keywords are kept (the free plugins know one, some Pro versions more). */
	public const MAX_KEYWORDS = 10;

	/**
	 * Every field set to the default.
	 *
	 * @return array{title: null, description: null, focus_keywords: null, canonical: null, noindex: null}
	 */
	public static function empty(): array {
		return array(
			self::TITLE          => null,
			self::DESCRIPTION    => null,
			self::FOCUS_KEYWORDS => null,
			self::CANONICAL      => null,
			self::NOINDEX        => null,
		);
	}

	/**
	 * The fields of a partial update, cleaned: unknown keys dropped, empty values turned into null.
	 * Throws on a value of the wrong type instead of guessing.
	 *
	 * @param array<array-key, mixed> $input Decoded JSON.
	 *
	 * @return array<string, string|bool|list<string>|null>
	 *
	 * @throws \InvalidArgumentException On a value of the wrong type.
	 */
	public static function changes( array $input ): array {
		$changes = array();

		foreach ( self::ALL as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$changes[ $field ] = self::clean( $field, $input[ $field ] );
			}
		}

		return $changes;
	}

	/**
	 * One field, cleaned.
	 *
	 * @param string $field One of ALL.
	 * @param mixed  $value Raw value.
	 *
	 * @return string|bool|list<string>|null
	 *
	 * @throws \InvalidArgumentException On a value of the wrong type.
	 */
	public static function clean( string $field, mixed $value ): string|bool|array|null {
		if ( null === $value ) {
			return null;
		}

		switch ( $field ) {
			case self::NOINDEX:
				if ( ! is_bool( $value ) ) {
					throw new \InvalidArgumentException( 'noindex must be true, false or null.' );
				}

				return $value;

			case self::FOCUS_KEYWORDS:
				if ( ! is_array( $value ) ) {
					throw new \InvalidArgumentException( 'focus_keywords must be a list of strings.' );
				}

				$keywords = array();

				foreach ( $value as $keyword ) {
					if ( ! is_string( $keyword ) ) {
						throw new \InvalidArgumentException( 'focus_keywords must be a list of strings.' );
					}

					$keyword = self::text( $keyword, 100 );

					if ( '' !== $keyword && ! in_array( $keyword, $keywords, true ) ) {
						$keywords[] = $keyword;
					}
				}

				return array() === $keywords ? null : array_slice( $keywords, 0, self::MAX_KEYWORDS );

			case self::CANONICAL:
				if ( ! is_string( $value ) ) {
					throw new \InvalidArgumentException( 'canonical must be a URL or null.' );
				}

				$value = trim( $value );

				if ( '' === $value ) {
					return null;
				}

				if ( 1 !== preg_match( '#^https?://[^\s<>"]+$#i', $value ) ) {
					throw new \InvalidArgumentException( 'canonical must be an http(s) URL.' );
				}

				return $value;

			default:
				if ( ! is_string( $value ) ) {
					throw new \InvalidArgumentException( 'title and description must be strings or null.' );
				}

				$value = self::text( $value, self::TITLE === $field ? 300 : 1000 );

				return '' === $value ? null : $value;
		}
	}

	/**
	 * One line of plain text: tags removed, whitespace collapsed, cut to a maximum length.
	 *
	 * @param string $value Raw text.
	 * @param int    $max   Maximum characters.
	 */
	private static function text( string $value, int $max ): string {
		// Pure PHP on purpose (unit-tested without WordPress); wp_strip_all_tags() does the same plus script contents.
		$value = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $value );
		$value = trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( $value ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags

		return mb_substr( $value, 0, $max );
	}
}
