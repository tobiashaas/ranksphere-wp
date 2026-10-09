<?php
/**
 * Prints the plugin's own SEO fields when no SEO plugin is active.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Seo;

use RankSphere\Seo\Adapters\Native;

/**
 * Title through WordPress' document title, description as meta tag, canonical through WordPress'
 * own rel=canonical, noindex through wp_robots. Only on single posts and pages, only with values.
 */
final class NativeOutput {

	/**
	 * Hooks the output – only when no supported SEO plugin runs (it would print its own).
	 */
	public function register(): void {
		add_action(
			'wp',
			function (): void {
				if ( null !== SeoPlugins::active() || ! is_singular() ) {
					return;
				}

				add_filter( 'pre_get_document_title', array( $this, 'title' ), 20 );
				add_action( 'wp_head', array( $this, 'description' ), 1 );
				add_filter( 'get_canonical_url', array( $this, 'canonical' ), 20 );
				add_filter( 'wp_robots', array( $this, 'robots' ) );
			}
		);
	}

	/**
	 * Filter `pre_get_document_title`.
	 *
	 * @param string $title Title so far.
	 */
	public function title( $title ): string {
		$own = $this->field( Native::TITLE );

		// WordPress prints a title from this filter as it is – escaped here, like its own titles.
		return null !== $own ? esc_html( $own ) : (string) $title;
	}

	/**
	 * Prints the meta description.
	 */
	public function description(): void {
		$description = $this->field( Native::DESCRIPTION );

		if ( null !== $description ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $description ) );
		}
	}

	/**
	 * Filter `get_canonical_url`.
	 *
	 * @param string $url Canonical so far.
	 */
	public function canonical( $url ): string {
		$own = $this->field( Native::CANONICAL );

		return null !== $own ? $own : (string) $url;
	}

	/**
	 * Filter `wp_robots`.
	 *
	 * @param array<string, bool|string> $robots Directives so far.
	 *
	 * @return array<string, bool|string>
	 */
	public function robots( $robots ): array {
		$robots = is_array( $robots ) ? $robots : array();

		if ( '1' === $this->field( Native::NOINDEX ) ) {
			$robots['noindex'] = true;
			unset( $robots['index'], $robots['max-image-preview'] );
		}

		return $robots;
	}

	/**
	 * A stored value of the queried post.
	 *
	 * @param string $key Meta key.
	 */
	private function field( string $key ): ?string {
		$value = get_post_meta( (int) get_queried_object_id(), $key, true );

		return is_string( $value ) && '' !== trim( $value ) ? $value : null;
	}
}
