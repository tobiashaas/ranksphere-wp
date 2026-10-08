<?php
/**
 * The history of a post in the RankSphere box.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Admin;

use RankSphere\Content\TextHistory;
use RankSphere\Seo\History;
use WP_Post;

/**
 * What RankSphere did with a post, newest first: texts and revisions (TextHistory) and SEO fields
 * (Seo\History). Content that was replaced links to WordPress' own revision screen, which
 * compares and restores – one place for "undo", the one authors know.
 */
final class PostHistory {

	/** Entries shown in the box. */
	private const SHOWN = 6;

	/**
	 * The list as HTML ('' without entries).
	 *
	 * @param WP_Post $post The post.
	 */
	public static function html( WP_Post $post ): string {
		$entries = array();

		foreach ( ( new TextHistory() )->all( $post->ID ) as $entry ) {
			$entries[] = array( $entry['at'], self::text_entry( $entry ) );
		}

		foreach ( ( new History() )->all( $post->ID ) as $entry ) {
			$entries[] = array( $entry['at'], self::seo_entry( $entry ) );
		}

		if ( array() === $entries ) {
			return '';
		}

		usort( $entries, static fn ( array $a, array $b ): int => $b[0] <=> $a[0] );
		$items = implode( '', array_column( array_slice( $entries, 0, self::SHOWN ), 1 ) );

		return '<h4>' . esc_html__( 'History', 'ranksphere' ) . '</h4><ul class="rs-history">' . $items . '</ul>';
	}

	/**
	 * A text event.
	 *
	 * @param array{type: string, at: int, user_id: int, text: int, revision: int, wp_revision: int} $entry The entry.
	 */
	private static function text_entry( array $entry ): string {
		$label = match ( $entry['type'] ) {
			TextHistory::CREATED  => __( 'Written with RankSphere', 'ranksphere' ),
			TextHistory::REPLACED => __( 'Text from RankSphere put in', 'ranksphere' ),
			TextHistory::REVISION => __( 'Revision from RankSphere saved as draft', 'ranksphere' ),
			TextHistory::APPLIED  => __( 'Revision taken over', 'ranksphere' ),
			default               => '',
		};

		if ( '' === $label ) {
			return '';
		}

		$link = '';

		if ( $entry['wp_revision'] > 0 && null !== get_post( $entry['wp_revision'] ) && current_user_can( 'edit_post', $entry['wp_revision'] ) ) {
			$link = '<a href="' . esc_url( admin_url( 'revision.php?revision=' . $entry['wp_revision'] ) ) . '">' . esc_html__( 'Compare or restore the version before', 'ranksphere' ) . '</a>';
		} elseif ( TextHistory::REVISION === $entry['type'] && $entry['revision'] > 0 && null !== get_post( $entry['revision'] ) && current_user_can( 'edit_post', $entry['revision'] ) ) {
			$link = '<a href="' . esc_url( (string) get_edit_post_link( $entry['revision'] ) ) . '">' . esc_html__( 'Open the revision', 'ranksphere' ) . '</a>';
		}

		$icon = in_array( $entry['type'], array( TextHistory::APPLIED, TextHistory::REPLACED ), true ) ? 'refresh' : 'file-text';

		return self::item( $icon, $label, $entry['user_id'], $entry['at'], $link );
	}

	/**
	 * An SEO change.
	 *
	 * @param array{id: string, at: int, user_id: int, source: string, before: array<array-key, mixed>, after: array<array-key, mixed>, undone: ?int} $entry The entry.
	 */
	private static function seo_entry( array $entry ): string {
		$names  = array(
			'title'          => __( 'SEO title', 'ranksphere' ),
			'description'    => __( 'Meta description', 'ranksphere' ),
			'focus_keywords' => __( 'Focus keywords', 'ranksphere' ),
			'canonical'      => __( 'Canonical URL', 'ranksphere' ),
			'noindex'        => __( 'Noindex', 'ranksphere' ),
		);
		$fields = implode( ', ', array_intersect_key( $names, $entry['after'] ) );
		$label  = match ( $entry['source'] ) {
			'suggestion' => __( 'Suggestion taken over', 'ranksphere' ),
			'revision'   => __( 'Taken over with the revision', 'ranksphere' ),
			'undo'       => __( 'Change undone', 'ranksphere' ),
			default      => __( 'Changed from RankSphere', 'ranksphere' ),
		};

		return self::item( 'search', $label . ( '' !== $fields ? ': ' . $fields : '' ), $entry['user_id'], $entry['at'], null !== $entry['undone'] ? '<span class="rs-muted">' . esc_html__( 'Undone', 'ranksphere' ) . '</span>' : '' );
	}

	/**
	 * One line: icon, what, who and when, link.
	 *
	 * @param string $icon    Icon key.
	 * @param string $label   What happened.
	 * @param int    $user_id Who did it.
	 * @param int    $at      When.
	 * @param string $link    A link or note (HTML), may be ''.
	 */
	private static function item( string $icon, string $label, int $user_id, int $at, string $link ): string {
		$user   = $user_id > 0 ? get_userdata( $user_id ) : false;
		$format = get_option( 'date_format' );
		$meta   = array_filter(
			array(
				false !== $user ? $user->display_name : '',
				$at > 0 ? (string) wp_date( is_string( $format ) && '' !== $format ? $format : 'Y-m-d', $at ) : '',
			),
			static fn ( string $part ): bool => '' !== $part
		);

		return '<li>' . Ui::icon( $icon ) . '<div><span>' . esc_html( $label ) . '</span><span class="rs-muted rs-small">' . esc_html( implode( ' · ', $meta ) ) . '</span>' . ( '' !== $link ? '<span class="rs-small">' . $link . '</span>' : '' ) . '</div></li>';
	}
}
