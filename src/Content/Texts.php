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

	/** Characters of an existing post's text sent to RankSphere. */
	public const SOURCE_CHARS = 12000;

	/** Own pages sent as the only internal link targets. */
	public const SITE_PAGES = 200;

	/** How long the ideas are kept (they come from RankSphere's tasks). */
	private const IDEAS_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Starts a text – for a new post, or for an existing one (its text goes along, so RankSphere
	 * builds on it, also for drafts nobody can see on the website). The site's own pages go along
	 * as the only internal link targets.
	 *
	 * @param array{type: string, topic: string, post_type?: string, notes?: string, required?: array<string, string>} $data What the person entered.
	 * @param \WP_Post|null                                                                                            $post The existing post the text is for.
	 *
	 * @return array<mixed>|WP_Error With the new text's id.
	 */
	public function start( array $data, ?\WP_Post $post = null ): array|WP_Error {
		$user = wp_get_current_user();

		if ( null !== $post ) {
			$data['post_type'] = $post->post_type;
		}

		return $this->client()?->send(
			'/texts',
			array_filter( $data, static fn ( mixed $value ): bool => '' !== $value && array() !== $value ) + array_filter(
				array(
					'source'     => null === $post ? null : self::source( $post ),
					'site_pages' => self::site_pages( null === $post ? 0 : $post->ID ),
					'by'         => $user->exists() ? $user->display_name : null,
					'lang'       => Insights::language(),
				),
				static fn ( mixed $value ): bool => null !== $value && array() !== $value
			)
		) ?? self::not_connected();
	}

	/**
	 * Ideas: open tasks of the project that call for a text (kind, topic, page). Kept ten minutes.
	 *
	 * @return list<array<mixed>>
	 */
	public function ideas(): array {
		$connection = $this->store->get();

		if ( null === $connection ) {
			return array();
		}

		$key    = 'ranksphere_text_ideas_' . md5( $connection->project_url . '|' . Insights::language() );
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return Value::maps( $cached, 'ideas' );
		}

		$answer = ( new RankSphereClient( $connection ) )->get( '/texts/ideas', array( 'lang' => Insights::language() ) );
		$ideas  = $answer instanceof WP_Error ? array( 'ideas' => array() ) : $answer;
		set_transient( $key, $ideas, $answer instanceof WP_Error ? 2 * MINUTE_IN_SECONDS : self::IDEAS_TTL );

		return Value::maps( $ideas, 'ideas' );
	}

	/**
	 * A note on the finished text – RankSphere revises the current version with it.
	 *
	 * @param int    $id   RankSphere's id.
	 * @param string $note The note.
	 *
	 * @return array<mixed>|WP_Error
	 */
	public function note( int $id, string $note ): array|WP_Error {
		return $this->client()?->send( '/texts/' . $id . '/notes', array( 'note' => $note ) + self::by() ) ?? self::not_connected();
	}

	/**
	 * One older version (its text, SEO fields, label).
	 *
	 * @param int $id     RankSphere's id.
	 * @param int $number The version.
	 *
	 * @return array<mixed>|WP_Error
	 */
	public function version( int $id, int $number ): array|WP_Error {
		return $this->client()?->get( '/texts/' . $id . '/versions/' . $number, array( 'lang' => Insights::language() ) ) ?? self::not_connected();
	}

	/**
	 * An older version becomes the current one (no AI call).
	 *
	 * @param int $id     RankSphere's id.
	 * @param int $number The version.
	 *
	 * @return array<mixed>|WP_Error
	 */
	public function restore( int $id, int $number ): array|WP_Error {
		return $this->client()?->send( '/texts/' . $id . '/restore', array( 'version' => $number ) + self::by() ) ?? self::not_connected();
	}

	/**
	 * What RankSphere gets of an existing post: its text as plain paragraphs (headings marked),
	 * title, address and status.
	 *
	 * @param \WP_Post $post The post.
	 *
	 * @return array{post_id: int, post_type: string, status: string, title: string, path: string|null, content: string}
	 */
	public static function source( \WP_Post $post ): array {
		$html = strip_shortcodes( (string) preg_replace( '/<!--.*?-->/s', '', $post->post_content ) );
		$html = (string) preg_replace( '/<h([1-6])[^>]*>/i', "\n\n## ", $html );
		$html = (string) preg_replace( '#</(p|h[1-6]|li|div|blockquote|tr)>|<br\s*/?>#i', "\n", $html );
		$text = trim( (string) preg_replace( "/\n{3,}/", "\n\n", (string) preg_replace( '/[ \t]+/', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) ) );
		$path = 'publish' === $post->post_status ? self::relative( $post ) : null;

		return array(
			'post_id'   => $post->ID,
			'post_type' => $post->post_type,
			'status'    => $post->post_status,
			'title'     => get_the_title( $post ),
			'path'      => $path,
			'content'   => mb_substr( $text, 0, self::SOURCE_CHARS ),
		);
	}

	/**
	 * The site's published pages and posts (title + path) – the only internal link targets.
	 *
	 * @param int $exclude A post to leave out (the one being rewritten).
	 *
	 * @return list<array{title: string, path: string}>
	 */
	public static function site_pages( int $exclude = 0 ): array {
		$types = array_values(
			array_filter(
				get_post_types( array( 'public' => true ) ),
				static fn ( string $type ): bool => 'attachment' !== $type
			)
		);
		// Pages first (services, contact – the usual link targets), then the newest other posts.
		$posts = array();

		foreach ( array( array_intersect( $types, array( 'page' ) ), array_diff( $types, array( 'page' ) ) ) as $group ) {
			$left = self::SITE_PAGES - count( $posts );

			if ( array() === $group || $left <= 0 ) {
				continue;
			}

			$posts = array_merge(
				$posts,
				get_posts(
					array(
						'post_type'              => array_values( $group ),
						'post_status'            => 'publish',
						'numberposts'            => $left,
						'orderby'                => array(
							'menu_order' => 'ASC',
							'date'       => 'DESC',
						),
						'exclude'                => $exclude > 0 ? array( $exclude ) : array(),
						'suppress_filters'       => false,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					)
				)
			);
		}

		$pages = array();

		foreach ( $posts as $post ) {
			$path = self::relative( $post );

			if ( null !== $path ) {
				$pages[] = array(
					'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
					'path'  => $path,
				);
			}
		}

		return $pages;
	}

	/**
	 * A post's address on the site: path, with the query for plain permalinks ("/?page_id=12").
	 *
	 * @param \WP_Post $post The post.
	 */
	private static function relative( \WP_Post $post ): ?string {
		$link = get_permalink( $post );
		$path = is_string( $link ) ? wp_make_link_relative( $link ) : '';

		return str_starts_with( $path, '/' ) ? $path : null;
	}

	/**
	 * Who asks – the current user's display name – and in which language.
	 *
	 * @return array{by?: string, lang: string}
	 */
	private static function by(): array {
		$user = wp_get_current_user();

		// The language goes along so RankSphere's messages (busy, limits) come in the user's language.
		return ( $user->exists() ? array( 'by' => $user->display_name ) : array() ) + array( 'lang' => Insights::language() );
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
	 * @param int                $id      RankSphere's id.
	 * @param array<int, string> $answers Position of the open question => answer.
	 *
	 * @return array<mixed>|WP_Error
	 */
	public function answer( int $id, array $answers ): array|WP_Error {
		return $this->client()?->send( '/texts/' . $id . '/answers', array( 'answers' => $answers ) + self::by() ) ?? self::not_connected();
	}

	/**
	 * Saves the text as WordPress draft (the current user as author) and tells RankSphere. A text
	 * for an existing post goes into that post while it is unpublished, else into a revision
	 * draft linked to it (Drafts).
	 *
	 * @param int    $id        RankSphere's id.
	 * @param string $post_type Post type for a first draft (later drafts keep theirs).
	 *
	 * @return array{post_id: int, created: bool, mode?: string, revises?: int}|WP_Error
	 */
	public function save_draft( int $id, string $post_type ): array|WP_Error {
		$client = $this->client();

		if ( null === $client ) {
			return self::not_connected();
		}

		$payload = $client->get(
			'/texts/' . $id . '/draft',
			array(
				'post_type' => $post_type,
				'lang'      => Insights::language(),
			)
		);

		if ( $payload instanceof WP_Error ) {
			return $payload;
		}

		$type   = get_post_type_object( Value::text( $payload, 'post_type' ) );
		$create = null !== $type && is_string( $type->cap->create_posts ?? null ) ? $type->cap->create_posts : '';

		if ( null === $type || ! $type->public || 'attachment' === $type->name || '' === $create || ! current_user_can( $create ) ) {
			return new WP_Error( 'ranksphere_invalid_content', __( 'Drafts can only be posts or pages the user may create.', 'ranksphere' ) );
		}

		try {
			$seo     = SeoFields::changes( Value::map( $payload, 'seo' ) ?? array() );
			$draft   = array(
				'ranksphere_id' => Value::text( $payload, 'ranksphere_id' ),
				'post_type'     => $type->name,
				'title'         => sanitize_text_field( Value::text( $payload, 'title' ) ),
				'content'       => Value::text( $payload, 'content' ),
				'slug'          => Value::text( $payload, 'slug' ),
			);
			$revises = (int) ( Value::number( $payload, 'revises' ) ?? 0 );

			if ( $revises > 0 ) {
				$draft['revises'] = $revises;
			}

			$saved = $this->drafts->save( $draft, $seo );
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
