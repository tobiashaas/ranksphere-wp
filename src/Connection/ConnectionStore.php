<?php
/**
 * Reads and writes the connection option.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Connection;

use RankSphere\Support\Options;

/**
 * The connection lives in one option without autoload (it holds secrets and is only needed on
 * RankSphere's requests and on the plugin's own page). Ending it keeps a short note why, so the
 * admin page can say what happened.
 */
final class ConnectionStore {

	/** The administrator disconnected in WordPress. */
	public const REASON_ADMIN = 'admin';

	/** RankSphere disconnected (project deleted, disconnected there). */
	public const REASON_RANKSPHERE = 'ranksphere';

	/** The application password RankSphere used was revoked. */
	public const REASON_REVOKED = 'revoked';

	/** The user who approved the connection was deleted. */
	public const REASON_USER_DELETED = 'user_deleted';

	/**
	 * The current connection, null when the site is not connected.
	 */
	public function get(): ?Connection {
		return Connection::from_array( get_option( Options::CONNECTION ) );
	}

	/**
	 * Stores a (new) connection and forgets an earlier disconnect note.
	 *
	 * @param Connection $connection The connection.
	 */
	public function save( Connection $connection ): void {
		update_option( Options::CONNECTION, $connection->to_array(), false );
		delete_option( Options::DISCONNECTED );
	}

	/**
	 * Removes the connection and notes why.
	 *
	 * @param string $reason One of the REASON_* constants.
	 */
	public function forget( string $reason ): void {
		delete_option( Options::CONNECTION );
		update_option(
			Options::DISCONNECTED,
			array(
				'reason' => $reason,
				'at'     => time(),
			),
			false
		);
	}

	/**
	 * Why and when the last connection ended; null when it did not (or a new one replaced it).
	 *
	 * @return array{reason: string, at: int}|null
	 */
	public function last_disconnect(): ?array {
		$note = get_option( Options::DISCONNECTED );

		if ( ! is_array( $note ) || ! isset( $note['reason'], $note['at'] ) || ! is_string( $note['reason'] ) || ! is_int( $note['at'] ) ) {
			return null;
		}

		return array(
			'reason' => $note['reason'],
			'at'     => $note['at'],
		);
	}
}
