<?php
/**
 * Ends the connection.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Connection;

/**
 * One way out for every trigger (admin, RankSphere, revoked password, deleted user): forget the
 * connection first, then tell RankSphere and remove the application password where that is
 * still needed. Forgetting first keeps the revocation hook from running a second time.
 */
final class Disconnector {

	/**
	 * Takes the connection store.
	 *
	 * @param ConnectionStore $store Where the connection lives.
	 */
	public function __construct( private readonly ConnectionStore $store = new ConnectionStore() ) {}

	/**
	 * Disconnects the site.
	 *
	 * @param string $reason          One of the ConnectionStore::REASON_* constants.
	 * @param bool   $notify          Tell RankSphere (not when RankSphere itself asked).
	 * @param bool   $revoke_password Remove the application password RankSphere used.
	 */
	public function disconnect( string $reason, bool $notify, bool $revoke_password ): void {
		$connection = $this->store->get();

		if ( null === $connection ) {
			return;
		}

		$this->store->forget( $reason );

		if ( $notify ) {
			( new RankSphereClient( $connection ) )->notify_disconnect( $reason, ConnectionStore::REASON_ADMIN === $reason );
		}

		if ( $revoke_password && class_exists( \WP_Application_Passwords::class ) ) {
			\WP_Application_Passwords::delete_application_password( $connection->user_id, $connection->app_password_uuid );
		}
	}
}
