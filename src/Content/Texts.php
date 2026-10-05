<?php
/**
 * Texts written by RankSphere, started from WordPress.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Content;

use RankSphere\Connection\ConnectionStore;
use RankSphere\Connection\RankSphereClient;
use RankSphere\Insights\Insights;
use RankSphere\Insights\Value;
use RankSphere\Seo\SeoFields;
use WP_Error;

/**
 * The page "Texte": people who write in WordPress start a text, follow it, answer RankSphere's open
 * questions and save it as a draft here – without opening RankSphere. RankSphere writes and reviews
 * (the same pipeline as its own page "Texte"); the draft is saved by this site with the current
 * user as author, never published, and reported back so RankSphere knows the post.
 */
final class Texts {

	/**
	 * Takes the connection store and the drafts.
	 *
	 * @param ConnectionStore $store  Where the connection lives.
	 * @param Drafts          $drafts Saves drafts.
	 */
	public function __construct(
		private readonly ConnectionStore $store = new ConnectionStore(),
		private readonly Drafts $drafts = new Drafts(),
	) {}

	/**
	 * Text types, the latest texts and why no text can start right now (or null).
	 *
	 * @return array<mixed>|WP_Error
	 */
	public function overview(): array|WP_Error {
		return $this->client()?->get( '/texts', array( 'lang' => Insights::language() ) ) ?? self::not_connected();
	}

	/**
	 * Starts a text.
	 *
	 * @param array{type: string, topic: string, target_page?: string, notes?: string, required?: array<string, string>} $data What the person entered.
	 *
	 * @return array<mixed>|WP_Error With the new text's id.
	 */
	public function start( array $data ): array|WP_Error {
		$user = wp_get_current_user();

		return $this->client()?->send(
			'/texts',
			array_filter( $data, static fn ( mixed $value ): bool => '' !== $value && array() !== $value ) + array(
				'by'   => $user->exists() ? $user->display_name : null,
				'lang' => Insights::language(),
			)
		) ?? self::not_connected();
	}

	/**
	 * One text with its HTML, SEO fields, questions and review.
	 *
	 * @param int $id RankSphere's id.
	 *
	 * @return array<mixed>|WP_Error
	 */
	public function get( int $id ): array|WP_Error {
		return $this->client()?->get( '/texts/' . $id, array( 'lang' => Insights::language() ) ) ?? self::not_connected();
	}

	/**
	 * Answers to the open questions – RankSphere writes the text again.
	 *
	 * @param int                   $id      RankSphere's id.
	 * @param array<string, string> $answers Question => answer.
	 *
	 * @return array<mixed>|WP_Error
	 */
	public function answer( int $id, array $answers ): array|WP_Error {
		return $this->client()?->send( '/texts/' . $id . '/answers', array( 'answers' => $answers ) ) ?? self::not_connected();
	}

	/**
	 * Saves the text as WordPress draft (the current user as author) and tells RankSphere.
	 *
	 * @param int    $id        RankSphere's id.
	 * @param string $post_type Post type for a first draft (later drafts keep theirs).
	 *
	 * @return array{post_id: int, created: bool}|WP_Error
	 */
	public function save_draft( int $id, string $post_type ): array|WP_Error {
		$client = $this->client();

		if ( null === $client ) {
			return self::not_connected();
		}

		$payload = $client->get( '/texts/' . $id . '/draft', array( 'post_type' => $post_type ) );

		if ( $payload instanceof WP_Error ) {
			return $payload;
		}

		$type   = get_post_type_object( Value::text( $payload, 'post_type' ) );
		$create = null !== $type && is_string( $type->cap->create_posts ?? null ) ? $type->cap->create_posts : '';

		if ( null === $type || ! $type->public || 'attachment' === $type->name || '' === $create || ! current_user_can( $create ) ) {
			return new WP_Error( 'ranksphere_invalid_content', __( 'Drafts can only be posts or pages the user may create.', 'ranksphere' ) );
		}

		try {
			$seo   = SeoFields::changes( Value::map( $payload, 'seo' ) ?? array() );
			$saved = $this->drafts->save(
				array(
					'ranksphere_id' => Value::text( $payload, 'ranksphere_id' ),
					'post_type'     => $type->name,
					'title'         => sanitize_text_field( Value::text( $payload, 'title' ) ),
					'content'       => Value::text( $payload, 'content' ),
					'slug'          => Value::text( $payload, 'slug' ),
				),
				$seo
			);
		} catch ( \InvalidArgumentException | \RuntimeException $e ) {
			return new WP_Error( 'ranksphere_invalid_content', $e->getMessage() );
		}

		if ( $saved instanceof WP_Error ) {
			return $saved;
		}

		$client->send(
			'/texts/' . $id . '/pushed',
			array(
				'post_id'   => $saved['post_id'],
				'edit_url'  => admin_url( 'post.php?post=' . $saved['post_id'] . '&action=edit' ),
				'post_type' => $type->name,
			),
			false
		);

		return $saved;
	}

	/**
	 * Post types the current user may turn a text into: public, with an editor.
	 *
	 * @return array<string, string> Name => label.
	 */
	public static function post_types(): array {
		$types = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			$create = $type->cap->create_posts ?? null;
			$label  = $type->labels->singular_name ?? null;

			if ( 'attachment' !== $type->name && $type->show_ui && post_type_supports( $type->name, 'editor' ) && is_string( $create ) && current_user_can( $create ) ) {
				$types[ $type->name ] = is_string( $label ) && '' !== $label ? $label : $type->name;
			}
		}

		return $types;
	}

	/**
	 * The client while the site is connected.
	 */
	private function client(): ?RankSphereClient {
		$connection = $this->store->get();

		return null === $connection ? null : new RankSphereClient( $connection );
	}

	/**
	 * The error without a connection.
	 */
	private static function not_connected(): WP_Error {
		return new WP_Error( 'ranksphere_not_connected', __( 'This site is not connected to RankSphere.', 'ranksphere' ) );
	}
}
