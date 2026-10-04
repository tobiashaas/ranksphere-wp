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
 * screen. Only posts that came from RankSphere are checked.
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

		if ( in_array( $status, self::LOCKED, true ) && $post_id > 0 && self::from_ranksphere( $post_id ) && self::has_placeholders( $content ) ) {
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
