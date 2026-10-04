<?php
/**
 * Title and description suggestions from RankSphere for one post.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Insights;

use RankSphere\Connection\ConnectionStore;
use RankSphere\Connection\RankSphereClient;
use RankSphere\Seo\SeoFields;
use RankSphere\Seo\SeoService;
use WP_Error;
use WP_Post;

/**
 * "Create suggestion" in the box on the edit screen: the site sends RankSphere what the SEO plugin
 * holds now and the post's text, RankSphere writes the suggestion in the background (it checks
 * lengths and the company's taboo words), the box polls. Taking one over writes it through the
 * active SEO plugin like any change from RankSphere – with history – and reports it to RankSphere,
 * so it can be undone there too.
 */
final class Suggestions {

	/** Fields a suggestion covers. */
	public const FIELDS = array( 'title', 'description' );

	/** Characters of the post's text sent along. */
	private const TEXT = 20000;

	/**
	 * Takes the connection store.
	 *
	 * @param ConnectionStore $store Where the connection lives.
	 */
	public function __construct( private readonly ConnectionStore $store = new ConnectionStore() ) {}

	/**
	 * Asks RankSphere for a suggestion.
	 *
	 * @param WP_Post $post The published post.
	 *
	 * @return array<mixed>|WP_Error RankSphere's state: pending, or failed with error.
	 */
	public function start( WP_Post $post ): array|WP_Error {
		$client = $this->client();
		$url    = get_permalink( $post );

		if ( null === $client || ! is_string( $url ) ) {
			return new WP_Error( 'ranksphere_not_connected', 'Not connected.' );
		}

		$current = SeoService::current()->read( $post->ID );
		$text    = trim( wp_strip_all_tags( strip_shortcodes( $post->post_excerpt . "\n\n" . $post->post_content ) ) );

		return $client->send(
			'/suggestions',
			array(
				'url'     => $url,
				'lang'    => Insights::language(),
				'current' => array(
					'title'       => $current['title'],
					'description' => $current['description'],
				),
				'content' => mb_substr( (string) preg_replace( '/\s+/u', ' ', $text ), 0, self::TEXT ),
			)
		);
	}

	/**
	 * Where the suggestion stands: none, pending, done (title, description, why) or failed (error).
	 *
	 * @param WP_Post $post The post.
	 *
	 * @return array<mixed>|WP_Error
	 */
	public function status( WP_Post $post ): array|WP_Error {
		$client = $this->client();
		$url    = get_permalink( $post );

		if ( null === $client || ! is_string( $url ) ) {
			return new WP_Error( 'ranksphere_not_connected', 'Not connected.' );
		}

		return $client->get( '/suggestions', array( 'url' => $url ) );
	}

	/**
	 * Writes one suggested field into the SEO plugin and tells RankSphere.
	 *
	 * @param WP_Post $post  The post.
	 * @param string  $field title or description.
	 * @param string  $value The suggested text.
	 *
	 * @return array{before: array<string, mixed>, after: array<string, mixed>, history_id: ?string, unsupported: list<string>}|WP_Error
	 */
	public function apply( WP_Post $post, string $field, string $value ): array|WP_Error {
		$value = SeoFields::clean( $field, $value );

		if ( ! in_array( $field, self::FIELDS, true ) || ! is_string( $value ) || '' === $value ) {
			return new WP_Error( 'ranksphere_invalid_field', 'Unknown field or empty value.', array( 'status' => 400 ) );
		}

		$result = SeoService::current()->update( $post->ID, array( $field => $value ), 'suggestion' );
		$client = $this->client();
		$url    = get_permalink( $post );

		if ( null !== $result['history_id'] && null !== $client && is_string( $url ) ) {
			$user = wp_get_current_user();

			// RankSphere logs it like its own changes, so it can be undone from there as well.
			$client->send(
				'/changes',
				array(
					'url'        => $url,
					'post_id'    => $post->ID,
					'before'     => $result['before'],
					'after'      => $result['after'],
					'history_id' => $result['history_id'],
					'by'         => $user->exists() ? $user->display_name : null,
				),
				false
			);
		}

		return $result;
	}

	/**
	 * The client while the site is connected.
	 */
	private function client(): ?RankSphereClient {
		$connection = $this->store->get();

		return null === $connection ? null : new RankSphereClient( $connection );
	}
}
