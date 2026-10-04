<?php
/**
 * Reading, changing and undoing a post's SEO fields.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Seo;

use WP_Error;

/**
 * One domain layer for REST (and later abilities): every change goes through the active plugin's
 * adapter and lands in the post's history, so it can be undone.
 */
final class SeoService {

	/**
	 * Takes its collaborators.
	 *
	 * @param Adapter $adapter The active SEO plugin's adapter.
	 * @param History $history The change log.
	 */
	public function __construct(
		private readonly Adapter $adapter = new Adapters\Native(),
		private readonly History $history = new History(),
	) {}

	/**
	 * With the adapter of the SEO plugin that is active right now.
	 */
	public static function current(): self {
		return new self( SeoPlugins::adapter() );
	}

	/**
	 * The adapter in use.
	 */
	public function adapter(): Adapter {
		return $this->adapter;
	}

	/**
	 * The raw fields.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array{title: ?string, description: ?string, focus_keywords: ?list<string>, canonical: ?string, noindex: ?bool}
	 */
	public function read( int $post_id ): array {
		return $this->adapter->read( $post_id );
	}

	/**
	 * Applies a partial update and records it.
	 *
	 * @param int                                          $post_id Post ID.
	 * @param array<string, string|bool|list<string>|null> $changes Cleaned changes.
	 * @param string                                       $source  "ranksphere" or "undo".
	 *
	 * @return array{before: array<string, mixed>, after: array<string, mixed>, history_id: ?string, unsupported: list<string>}
	 */
	public function update( int $post_id, array $changes, string $source = 'ranksphere' ): array {
		$unsupported = array_values( array_diff( array_keys( $changes ), $this->adapter->supports() ) );
		$changes     = array_intersect_key( $changes, array_flip( $this->adapter->supports() ) );
		$current     = $this->read( $post_id );
		$before      = array_intersect_key( $current, $changes );
		$changed     = array_filter( $changes, static fn ( $value, string $field ): bool => $current[ $field ] !== $value, ARRAY_FILTER_USE_BOTH );

		if ( array() === $changed ) {
			return array(
				'before'      => $before,
				'after'       => $before,
				'history_id'  => null,
				'unsupported' => $unsupported,
			);
		}

		$this->adapter->write( $post_id, $changed );
		clean_post_cache( $post_id );

		$after = array_intersect_key( $this->read( $post_id ), $changes );

		return array(
			'before'      => $before,
			'after'       => $after,
			'history_id'  => $this->history->record( $post_id, array_intersect_key( $current, $changed ), array_intersect_key( $after, $changed ), $source ),
			'unsupported' => $unsupported,
		);
	}

	/**
	 * Restores the values before a change – only while nobody changed those fields since.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $history_id Entry to undo.
	 *
	 * @return array{before: array<string, mixed>, after: array<string, mixed>, history_id: ?string, unsupported: list<string>}|WP_Error
	 */
	public function undo( int $post_id, string $history_id ): array|WP_Error {
		$entry = $this->history->find( $post_id, $history_id );

		if ( null === $entry ) {
			return new WP_Error( 'ranksphere_not_found', __( 'This change is not in the history of the post.', 'ranksphere' ), array( 'status' => 404 ) );
		}

		if ( null !== $entry['undone'] ) {
			return new WP_Error( 'ranksphere_already_undone', __( 'This change has already been undone.', 'ranksphere' ), array( 'status' => 409 ) );
		}

		$current = $this->read( $post_id );

		foreach ( $entry['after'] as $field => $value ) {
			if ( ( $current[ $field ] ?? null ) !== $value ) {
				return new WP_Error( 'ranksphere_changed_since', __( 'The field was changed again since; undoing would overwrite that change.', 'ranksphere' ), array( 'status' => 409 ) );
			}
		}

		try {
			$changes = SeoFields::changes( $entry['before'] );
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'ranksphere_invalid_history', $e->getMessage(), array( 'status' => 500 ) );
		}

		$result = $this->update( $post_id, $changes, 'undo' );
		$this->history->mark_undone( $post_id, $history_id );

		return $result;
	}
}
