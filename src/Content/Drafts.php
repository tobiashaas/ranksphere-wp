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
 */
final class Drafts {

	/** RankSphere's ID of the text, stored on the post. */
	public const META = '_ranksphere_draft_id';

	/** Statuses RankSphere may still overwrite. */
	private const EDITABLE = array( 'draft', 'pending', 'auto-draft' );

	/**
	 * Creates or updates the draft.
	 *
	 * @param array{ranksphere_id: string, post_type: string, title: string, content: string, slug?: string, excerpt?: string} $draft The text.
	 * @param array<string, string|bool|list<string>|null>                                                                     $seo   Cleaned SEO fields.
	 *
	 * @return array{post_id: int, created: bool}|WP_Error
	 */
	public function save( array $draft, array $seo ): array|WP_Error {
		$existing = $this->find( $draft['ranksphere_id'] );

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

		if ( array() !== $seo ) {
			SeoService::current()->update( $post_id, array_intersect_key( $seo, array_flip( SeoFields::ALL ) ) );
		}

		return array(
			'post_id' => $post_id,
			'created' => null === $existing,
		);
	}

	/**
	 * The post RankSphere created for this text, in any status.
	 *
	 * @param string $ranksphere_id RankSphere's ID.
	 */
	public function find( string $ranksphere_id ): ?\WP_Post {
		$posts = get_posts(
			array(
				'post_type'        => 'any',
				'post_status'      => 'any',
				'meta_key'         => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one lookup per request.
				'meta_value'       => $ranksphere_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one lookup per request.
				'numberposts'      => 1,
				'suppress_filters' => true,
			)
		);

		return $posts[0] ?? null;
	}
}
