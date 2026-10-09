<?php
/**
 * Typed reads from RankSphere's answers.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Insights;

/**
 * RankSphere's JSON is data from another server: every value is read with its type checked, and
 * a missing or odd one becomes "nothing" instead of a warning in the admin.
 */
final class Value {

	/**
	 * A string, or '' when the key is missing or no string.
	 *
	 * @param array<mixed> $data Decoded JSON.
	 * @param string       $key  Key.
	 */
	public static function text( array $data, string $key ): string {
		return is_string( $data[ $key ] ?? null ) ? $data[ $key ] : '';
	}

	/**
	 * A number, or null.
	 *
	 * @param array<mixed> $data Decoded JSON.
	 * @param string       $key  Key.
	 */
	public static function number( array $data, string $key ): ?float {
		return is_int( $data[ $key ] ?? null ) || is_float( $data[ $key ] ?? null ) ? (float) $data[ $key ] : null;
	}

	/**
	 * An object (associative array), or null.
	 *
	 * @param array<mixed> $data Decoded JSON.
	 * @param string       $key  Key.
	 *
	 * @return array<mixed>|null
	 */
	public static function map( array $data, string $key ): ?array {
		return is_array( $data[ $key ] ?? null ) ? $data[ $key ] : null;
	}

	/**
	 * A list of objects; entries that are no object are left out.
	 *
	 * @param array<mixed> $data Decoded JSON.
	 * @param string       $key  Key.
	 *
	 * @return list<array<mixed>>
	 */
	public static function maps( array $data, string $key ): array {
		$items = is_array( $data[ $key ] ?? null ) ? $data[ $key ] : array();

		return array_values( array_filter( $items, 'is_array' ) );
	}

	/**
	 * A list of strings; anything else is left out.
	 *
	 * @param array<mixed> $data Decoded JSON.
	 * @param string       $key  Key.
	 *
	 * @return list<string>
	 */
	public static function texts( array $data, string $key ): array {
		$items = is_array( $data[ $key ] ?? null ) ? $data[ $key ] : array();

		return array_values( array_filter( $items, 'is_string' ) );
	}

	/**
	 * An https link (RankSphere's), or '' – nothing else ends up in an href.
	 *
	 * @param array<mixed> $data Decoded JSON.
	 * @param string       $key  Key.
	 */
	public static function link( array $data, string $key ): string {
		$url = self::text( $data, $key );

		$host = wp_parse_url( $url, PHP_URL_HOST );

		// https, or plain http for a RankSphere running on this machine (development).
		return str_starts_with( $url, 'https://' ) || ( str_starts_with( $url, 'http://' ) && in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) ? $url : '';
	}

	/**
	 * A whole number for display, in the site's format.
	 *
	 * @param float $value The number.
	 */
	public static function count( float $value ): string {
		return number_format_i18n( $value );
	}

	/**
	 * A share 0…1 as percent.
	 *
	 * @param float $share The share.
	 */
	public static function percent( float $share ): string {
		/* translators: %s: a number, e.g. 42. */
		return sprintf( __( '%s %%', 'ranksphere' ), number_format_i18n( $share * 100 ) );
	}

	/**
	 * The change against the period before as "+12 %" / "−8 %"; '' without a fair comparison.
	 *
	 * @param float|null $now    This period.
	 * @param float|null $before The period before.
	 */
	public static function change( ?float $now, ?float $before ): string {
		if ( null === $now || null === $before || $before <= 0.0 ) {
			return '';
		}

		$change = ( $now - $before ) / $before;

		return ( $change >= 0 ? '+' : '−' ) . self::percent( abs( $change ) );
	}
}
