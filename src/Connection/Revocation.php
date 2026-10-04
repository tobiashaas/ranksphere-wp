<?php
/**
 * Notices when the credentials behind the connection disappear.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Connection;

/**
 * A user can revoke RankSphere's access in their profile (Application Passwords) or an
 * administrator can delete that user. Either way RankSphere can no longer act on the site, so
 * the plugin ends the connection right away and tells RankSphere – instead of waiting for the
 * next failing call.
 */
final class Revocation {

	/**
	 * Takes the connection store.
	 *
	 * @param ConnectionStore $store Where the connection lives.
	 */
	public function __construct( private readonly ConnectionStore $store = new ConnectionStore() ) {}

	/**
	 * Hooks the two events.
	 */
	public function register(): void {
		add_action( 'wp_delete_application_password', array( $this, 'password_deleted' ), 10, 2 );
		add_action( 'deleted_user', array( $this, 'user_deleted' ) );
	}

	/**
	 * Fired after an application password was deleted.
	 *
	 * @param int                  $user_id The user the password belonged to.
	 * @param array<string, mixed> $item    The deleted password (uuid, name, …).
	 */
	public function password_deleted( int $user_id, array $item ): void {
		$connection = $this->store->get();

		if ( null !== $connection && $connection->user_id === $user_id && ( $item['uuid'] ?? null ) === $connection->app_password_uuid ) {
			( new Disconnector( $this->store ) )->disconnect( ConnectionStore::REASON_REVOKED, true, false );
		}
	}

	/**
	 * Fired after a user was deleted.
	 *
	 * @param int $user_id The deleted user.
	 */
	public function user_deleted( int $user_id ): void {
		$connection = $this->store->get();

		if ( null !== $connection && $connection->user_id === $user_id ) {
			( new Disconnector( $this->store ) )->disconnect( ConnectionStore::REASON_USER_DELETED, true, false );
		}
	}
}
