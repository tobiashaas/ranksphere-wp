<?php
/**
 * Drafts from RankSphere.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Content;

use RankSphere\Seo\SeoFields;
use RankSphere\Seo\SeoService;
use WP_Error;

/**
 * Creates or updates the WordPress draft for a text written in RankSphere. Always a draft: the
 * status is set here, whatever was sent. A draft someone already published is never touched again.
 *
 * A text written for an existing post (`revises`) never touches a published original: it becomes a
 * separate draft linked to it (REVISES_META) that the author takes over in the editor
 * (Admin\ApplyRevision). An original that is not published yet gets the text itself – its content
 * before stays as WordPress revision (TextHistory).
 */
final class Drafts {

	/** RankSphere's ID of the text, stored on the post. */
	public const META = '_ranksphere_draft_id';

	/** On a revision draft: the post it revises. */
	public const REVISES_META = '_ranksphere_revises';

	/** On a revision draft: when it was taken over into the original. */
	public const APPLIED_META = '_ranksphere_applied';

	/** Statuses RankSphere may still overwrite. */
	public const EDITABLE = array( 'draft', 'pending', 'auto-draft' );

	/**
	 * Takes the history.
	 *
	 * @param TextHistory $history What RankSphere did with a post's text.
	 */
	public function __construct( private readonly TextHistory $history = new TextHistory() ) {}

	/**
	 * Creates or updates the draft.
	 *
	 * @param array{ranksphere_id: string, post_type: string, title: string, content: string, slug?: string, excerpt?: string, revises?: int} $draft The text.
	 * @param array<string, string|bool|list<string>|null>                                                                                    $seo   Cleaned SEO fields.
	 *
	 * @return array{post_id: int, created: bool, mode?: string, revises?: int}|WP_Error
	 */
	public function save( array $draft, array $seo ): array|WP_Error {
		$revises = (int) ( $draft['revises'] ?? 0 );

		if ( $revises > 0 ) {
			return $this->save_for( get_post( $revises ), $draft, $seo );
		}

		$existing = $this->find( $draft['ranksphere_id'] );

		if ( null !== $existing && ! current_user_can( 'edit_post', $existing->ID ) ) {
			return self::not_yours();
		}

		if ( null !== $existing && ! in_array( $existing->post_status, self::EDITABLE, true ) ) {
			return new WP_Error(
				'ranksphere_already_published',
				__( 'This text is already published in WordPress; RankSphere no longer changes it.', 'ranksphere' ),
				array(
					'status'  => 409,
					'post_id' => $existing->ID,
				)
			);
		}

		$postarr = array(
			'post_type'    => $draft['post_type'],
			'post_status'  => 'draft',
			'post_title'   => $draft['title'],
			'post_content' => wp_kses_post( $draft['content'] ),
			'post_excerpt' => $draft['excerpt'] ?? '',
		);

		if ( isset( $draft['slug'] ) && '' !== $draft['slug'] ) {
			$postarr['post_name'] = sanitize_title( $draft['slug'] );
		}

		if ( null !== $existing ) {
			$postarr['ID'] = $existing->ID;
		} else {
			$postarr['post_author'] = get_current_user_id();
		}

		// wp_insert_post() expects slashed data.
		$post_id = wp_insert_post( wp_slash( $postarr ), true );

		if ( $post_id instanceof WP_Error ) {
			return new WP_Error( 'ranksphere_invalid_content', $post_id->get_error_message(), array( 'status' => 400 ) );
		}

		update_post_meta( $post_id, self::META, $draft['ranksphere_id'] );
		$this->seo( $post_id, $seo );

		if ( null === $existing ) {
			$this->history->record( $post_id, TextHistory::CREATED, self::text_id( $draft['ranksphere_id'] ) );
		}

		return array(
			'post_id' => $post_id,
			'created' => null === $existing,
		);
	}

	/**
	 * A text written for an existing post: into the original while it is unpublished, else into a
	 * revision draft linked to it.
	 *
	 * @param \WP_Post|null                                                                                                                   $original The post the text is for.
	 * @param array{ranksphere_id: string, post_type: string, title: string, content: string, slug?: string, excerpt?: string, revises?: int} $draft    The text.
	 * @param array<string, string|bool|list<string>|null>                                                                                    $seo      Cleaned SEO fields.
	 *
	 * @return array{post_id: int, created: bool, mode: string, revises: int}|WP_Error
	 */
	private function save_for( ?\WP_Post $original, array $draft, array $seo ): array|WP_Error {
		if ( null === $original || 'trash' === $original->post_status || ! current_user_can( 'edit_post', $original->ID ) || ! array_key_exists( $original->post_type, Texts::post_types() ) ) {
			return new WP_Error( 'ranksphere_forbidden', __( 'The post this text is for no longer exists or you may not edit it.', 'ranksphere' ), array( 'status' => 403 ) );
		}

		$text = self::text_id( $draft['ranksphere_id'] );

		if ( 'original' === self::mode_for( $original ) ) {
			$before  = TextHistory::keep_current( $original->ID );
			$postarr = array(
				'ID'           => $original->ID,
				'post_title'   => $draft['title'],
				'post_content' => wp_kses_post( $draft['content'] ),
			);

			// A post just opened in the editor is an auto-draft, which WordPress deletes after a week.
			if ( 'auto-draft' === $original->post_status ) {
				$postarr['post_status'] = 'draft';
			}

			$post_id = wp_update_post( wp_slash( $postarr ), true );

			if ( $post_id instanceof WP_Error ) {
				return new WP_Error( 'ranksphere_invalid_content', $post_id->get_error_message(), array( 'status' => 400 ) );
			}

			update_post_meta( $original->ID, self::META, $draft['ranksphere_id'] );
			$this->seo( $original->ID, $seo );
			$this->history->record( $original->ID, TextHistory::REPLACED, $text, 0, $before );

			return array(
				'post_id' => $original->ID,
				'created' => false,
				'mode'    => 'original',
				'revises' => $original->ID,
			);
		}

		// Published (or scheduled, private), or a draft whose content WordPress could not keep: the original stays as it is.
		$existing = $this->find( $draft['ranksphere_id'] );

		if ( null !== $existing && ( ! in_array( $existing->post_status, self::EDITABLE, true ) || self::meta_int( $existing->ID, self::REVISES_META ) !== $original->ID ) ) {
			$existing = null;
		}

		if ( null !== $existing && ! current_user_can( 'edit_post', $existing->ID ) ) {
			return self::not_yours();
		}

		$postarr = array(
			'post_type'    => $original->post_type,
			'post_status'  => 'draft',
			'post_title'   => $draft['title'],
			'post_content' => wp_kses_post( $draft['content'] ),
		);

		if ( null !== $existing ) {
			$postarr['ID'] = $existing->ID;
		} else {
			$postarr['post_author'] = get_current_user_id();
		}

		$post_id = wp_insert_post( wp_slash( $postarr ), true );

		if ( $post_id instanceof WP_Error ) {
			return new WP_Error( 'ranksphere_invalid_content', $post_id->get_error_message(), array( 'status' => 400 ) );
		}

		update_post_meta( $post_id, self::META, $draft['ranksphere_id'] );
		update_post_meta( $post_id, self::REVISES_META, $original->ID );
		delete_post_meta( $post_id, self::APPLIED_META );
		$this->seo( $post_id, $seo );

		if ( null === $existing ) {
			$this->history->record( $original->ID, TextHistory::REVISION, $text, $post_id );
		}

		return array(
			'post_id' => $post_id,
			'created' => null === $existing,
			'mode'    => 'revision',
			'revises' => $original->ID,
		);
	}

	/**
	 * Where a text for an existing post goes: into the post itself ("original") while it is not
	 * published and its content before can be kept as WordPress revision (or it is empty), else
	 * into a revision draft next to it ("revision") – nothing is ever overwritten without a way back.
	 *
	 * @param \WP_Post $post The post the text is for.
	 */
	public static function mode_for( \WP_Post $post ): string {
		if ( ! in_array( $post->post_status, self::EDITABLE, true ) ) {
			return 'revision';
		}

		return '' === trim( $post->post_content ) || wp_revisions_enabled( $post ) ? 'original' : 'revision';
	}

	/**
	 * Someone else's draft of this text, which the current user may not edit.
	 */
	private static function not_yours(): WP_Error {
		return new WP_Error( 'ranksphere_forbidden', __( 'Someone else already saved this text as draft, and you may not edit it. Ask them or an editor to update it.', 'ranksphere' ), array( 'status' => 403 ) );
	}

	/**
	 * The SEO fields that came with the text.
	 *
	 * @param int                                          $post_id The post.
	 * @param array<string, string|bool|list<string>|null> $seo     Cleaned SEO fields.
	 */
	private function seo( int $post_id, array $seo ): void {
		if ( array() !== $seo ) {
			SeoService::current()->update( $post_id, array_intersect_key( $seo, array_flip( SeoFields::ALL ) ) );
		}
	}

	/**
	 * The post a post meta field points to (REVISES_META) or the time in it (APPLIED_META), 0 without.
	 *
	 * @param int    $post_id The post.
	 * @param string $key     The meta key.
	 */
	public static function meta_int( int $post_id, string $key ): int {
		$value = get_post_meta( $post_id, $key, true );

		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * RankSphere's text id from "text-<id>" (0 for other ids).
	 *
	 * @param string $ranksphere_id RankSphere's ID.
	 */
	public static function text_id( string $ranksphere_id ): int {
		return 1 === preg_match( '/^text-(\d+)$/', $ranksphere_id, $match ) ? (int) $match[1] : 0;
	}

	/**
	 * The post RankSphere created for this text, in any status.
	 *
	 * @param string $ranksphere_id RankSphere's ID.
	 */
	public function find( string $ranksphere_id ): ?\WP_Post {
		$posts = get_posts(
			array(
				// All types: "any" leaves out types excluded from search (custom "Leistungen" and the like).
				'post_type'   => array_values( get_post_types() ),
				'post_status' => array_values( array_diff( get_post_stati(), array( 'trash', 'inherit' ) ) ),
				'meta_key'    => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one lookup per request.
				'meta_value'  => $ranksphere_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one lookup per request.
				'numberposts' => 1,
			)
		);

		return $posts[0] ?? null;
	}
}
