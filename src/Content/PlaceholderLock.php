<?php
/**
 * Texts with open placeholders cannot be published.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Content;

/**
 * RankSphere marks missing facts as [[…]] instead of inventing them. Such a draft stays a draft
 * until every placeholder is filled in – enforced when the post is saved, explained on its edit
 * screen. Only posts that came from RankSphere are checked, and a post that is already live is
 * never taken offline by it.
 *
 * A revision draft (Drafts::REVISES_META) always stays a draft: published, it would be a second
 * page next to the original. It is taken over into the original instead (Admin\ApplyRevision).
 */
final class PlaceholderLock {

	/** [[Angabe fehlt: Öffnungszeiten]] and the like. */
	public const PATTERN = '/\[\[[^\[\]]{1,300}\]\]/u';

	/** Statuses that would make the text visible or schedule it. */
	private const LOCKED = array( 'publish', 'future' );

	/**
	 * Hooks the lock and the notice.
	 */
	public function register(): void {
		add_filter( 'wp_insert_post_data', array( $this, 'keep_draft' ), 99, 2 );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Filter `wp_insert_post_data`: back to draft while placeholders are open.
	 *
	 * @param array<string, mixed> $data    Post data about to be saved (slashed).
	 * @param array<string, mixed> $postarr The submitted data.
	 *
	 * @return array<string, mixed>
	 */
	public function keep_draft( $data, $postarr ): array {
		$status  = is_string( $data['post_status'] ?? null ) ? $data['post_status'] : '';
		$content = is_string( $data['post_content'] ?? null ) ? wp_unslash( $data['post_content'] ) : '';
		$post_id = isset( $postarr['ID'] ) && is_numeric( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;

		if ( ! in_array( $status, self::LOCKED, true ) || $post_id <= 0 ) {
			return $data;
		}

		if ( Drafts::meta_int( $post_id, Drafts::REVISES_META ) > 0 ) {
			$data['post_status'] = 'draft';

			return $data;
		}

		if ( 'publish' !== get_post_status( $post_id ) && self::from_ranksphere( $post_id ) && self::has_placeholders( $content ) ) {
			$data['post_status'] = 'draft';
		}

		return $data;
	}

	/**
	 * The explanation on the edit screen (the block editor shows it as a notice too).
	 */
	public function notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post   = get_post();

		if ( null === $screen || 'post' !== $screen->base || ! $post instanceof \WP_Post ) {
			return;
		}

		$original = Drafts::meta_int( $post->ID, Drafts::REVISES_META );

		if ( $original > 0 && null !== get_post( $original ) ) {
			printf(
				'<div class="notice notice-info"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: title of the original post. */
						__( 'This draft is RankSphere\'s rewrite of "%s". It stays a draft – published it would be a second page. Take it over into the original with the button in the RankSphere box.', 'ranksphere' ),
						get_the_title( $original )
					)
				)
			);
		}

		if ( ! self::from_ranksphere( $post->ID ) || ! self::has_placeholders( $post->post_content ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'This text from RankSphere still contains placeholders like [[…]] for missing facts. Fill them in – until then it can only be saved as a draft.', 'ranksphere' )
		);
	}

	/**
	 * Whether the content has open placeholders.
	 *
	 * @param string $content Post content.
	 */
	public static function has_placeholders( string $content ): bool {
		return 1 === preg_match( self::PATTERN, $content );
	}

	/**
	 * Whether the post was created by RankSphere.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function from_ranksphere( int $post_id ): bool {
		$id = get_post_meta( $post_id, Drafts::META, true );

		return is_string( $id ) && '' !== $id;
	}
}
