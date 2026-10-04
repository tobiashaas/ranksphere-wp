<?php
/**
 * Which SEO plugin is active.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Seo;

/**
 * Detection as listed in docs/SEO-PLUGINS.md. With several active, the first in this list wins –
 * the same order the adapters (M2) use.
 */
final class SeoPlugins {

	/**
	 * Slug (wordpress.org), name and the constant that holds the version.
	 *
	 * @var list<array{slug: string, name: string, constant: string}>
	 */
	private const KNOWN = array(
		array(
			'slug'     => 'wordpress-seo',
			'name'     => 'Yoast SEO',
			'constant' => 'WPSEO_VERSION',
		),
		array(
			'slug'     => 'seo-by-rank-math',
			'name'     => 'Rank Math',
			'constant' => 'RANK_MATH_VERSION',
		),
		array(
			'slug'     => 'wp-seopress',
			'name'     => 'SEOPress',
			'constant' => 'SEOPRESS_VERSION',
		),
		array(
			'slug'     => 'all-in-one-seo-pack',
			'name'     => 'All in One SEO',
			'constant' => 'AIOSEO_VERSION',
		),
		array(
			'slug'     => 'autodescription',
			'name'     => 'The SEO Framework',
			'constant' => 'THE_SEO_FRAMEWORK_VERSION',
		),
		array(
			'slug'     => 'slim-seo',
			'name'     => 'Slim SEO',
			'constant' => 'SLIM_SEO_VER',
		),
	);

	/**
	 * The active SEO plugin, null when none of the supported ones runs.
	 *
	 * @return array{slug: string, name: string, version: string}|null
	 */
	public static function active(): ?array {
		foreach ( self::KNOWN as $plugin ) {
			if ( defined( $plugin['constant'] ) ) {
				$version = constant( $plugin['constant'] );

				return array(
					'slug'    => $plugin['slug'],
					'name'    => $plugin['name'],
					'version' => is_scalar( $version ) ? (string) $version : '',
				);
			}
		}

		return null;
	}

	/**
	 * The adapter for the active SEO plugin; the plugin's own fields without one.
	 */
	public static function adapter(): Adapter {
		return match ( self::active()['slug'] ?? null ) {
			'wordpress-seo'       => new Adapters\Yoast(),
			'seo-by-rank-math'    => new Adapters\RankMath(),
			'wp-seopress'         => new Adapters\SeoPress(),
			'all-in-one-seo-pack' => new Adapters\Aioseo(),
			'autodescription'     => new Adapters\SeoFramework(),
			'slim-seo'            => new Adapters\SlimSeo(),
			default               => new Adapters\Native(),
		};
	}
}
