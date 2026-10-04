<?php
/**
 * Connecting, the status route, signatures and the ways a connection ends.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Tests\Integration;

use RankSphere\Admin\SettingsPage;
use RankSphere\Connection\ConnectionStore;
use RankSphere\Security\RequestVerifier;
use RankSphere\Security\Signature;
use RankSphere\Support\Options;
use WP_Application_Passwords;
use WP_REST_Request;

use const RankSphere\VERSION;

/**
 * Only RankSphere, with the approved application password and a fresh signature, gets in.
 */
final class ConnectionTest extends RankSphereTestCase {

	public function test_connecting_needs_an_application_password_of_an_administrator(): void {
		wp_set_current_user( $this->admin );
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		self::assertSame( 401, $this->dispatch( $this->connect_request() )->get_status(), 'cookie login is not enough' );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		self::assertIsInt( $editor );
		$this->approve( $editor );
		self::assertSame( 403, $this->dispatch( $this->connect_request() )->get_status() );

		self::assertNull( ( new ConnectionStore() )->get() );
	}

	public function test_an_administrator_connects_the_site(): void {
		$this->authenticate();
		$response = $this->dispatch( $this->connect_request() );

		self::assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		self::assertIsArray( $data );
		self::assertTrue( $data['connected'] );
		self::assertSame( VERSION, $data['plugin_version'] );
		self::assertStringNotContainsString( self::SECRET, (string) wp_json_encode( $data ), 'the secret never leaves the site' );
		self::assertStringNotContainsString( self::TOKEN, (string) wp_json_encode( $data ) );

		$connection = ( new ConnectionStore() )->get();
		self::assertNotNull( $connection );
		self::assertSame( 'Musterprojekt', $connection->project_name );
		self::assertSame( 'https://ranksphere.test/api/wordpress/v1', $connection->api_url );
		self::assertSame( $this->admin, $connection->user_id );
		self::assertSame( $this->uuid, $connection->app_password_uuid );

		$autoload = wp_load_alloptions();
		self::assertArrayNotHasKey( Options::CONNECTION, $autoload, 'secrets are not autoloaded' );
	}

	public function test_plain_http_addresses_are_refused_unless_allowed(): void {
		$this->authenticate();
		$request = fn (): WP_REST_Request => $this->connect_request( array( 'api_url' => 'http://ranksphere.test/api/wordpress/v1' ) );

		add_filter( 'ranksphere_allow_insecure_urls', '__return_false' );
		self::assertSame( 400, $this->dispatch( $request() )->get_status(), 'production' );
		self::assertNull( ( new ConnectionStore() )->get() );

		add_filter( 'ranksphere_allow_insecure_urls', '__return_true', 20 );
		self::assertSame( 201, $this->dispatch( $request() )->get_status(), 'a local RankSphere' );
	}

	public function test_a_connected_site_is_not_taken_over_by_another_project(): void {
		$this->connect();

		$response = $this->dispatch( $this->connect_request( array( 'project_name' => 'Fremd' ) ) );

		self::assertSame( 409, $response->get_status() );
		self::assertSame( 'Musterprojekt', $this->project_name() );

		// RankSphere itself renews the connection with the current secret.
		$renew = $this->sign( $this->connect_request( array( 'project_name' => 'Umbenannt' ) ) );
		self::assertSame( 201, $this->dispatch( $renew )->get_status() );
		self::assertSame( 'Umbenannt', $this->project_name() );
	}

	public function test_the_status_needs_a_valid_fresh_signature(): void {
		$this->connect();

		$request = new WP_REST_Request( 'GET', '/ranksphere/v1/status' );
		$request->set_query_params(
			array(
				'b'          => 'x y',
				'a'          => '1',
				'rest_route' => '/ranksphere/v1/status',
			)
		);
		$signed   = $this->sign( $request );
		$response = $this->dispatch( $signed );

		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertIsArray( $data );
		self::assertTrue( $data['connected'] );
		self::assertSame( home_url( '/' ), $data['site_url'] );

		self::assertSame( 401, $this->dispatch( $signed )->get_status(), 'a signature counts once' );
		self::assertSame( 401, $this->dispatch( new WP_REST_Request( 'GET', '/ranksphere/v1/status' ) )->get_status(), 'unsigned' );
		self::assertSame( 401, $this->dispatch( $this->sign( new WP_REST_Request( 'GET', '/ranksphere/v1/status' ), time() - Signature::TOLERANCE - 1 ) )->get_status(), 'expired' );
		self::assertSame( 401, $this->dispatch( $this->sign( new WP_REST_Request( 'GET', '/ranksphere/v1/status' ), null, str_repeat( 'x', 40 ) ) )->get_status(), 'wrong secret' );
	}

	public function test_only_the_approved_application_password_is_accepted(): void {
		$this->connect();

		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		self::assertIsInt( $other );
		$this->approve( $other );

		self::assertSame( 403, $this->dispatch( $this->sign( new WP_REST_Request( 'GET', '/ranksphere/v1/status' ) ) )->get_status() );
	}

	public function test_ranksphere_disconnects_and_its_password_is_removed(): void {
		$this->connect();

		$response = $this->dispatch( $this->sign( new WP_REST_Request( 'DELETE', '/ranksphere/v1/connection' ) ) );

		self::assertSame( 200, $response->get_status() );
		self::assertNull( ( new ConnectionStore() )->get() );
		self::assertNull( WP_Application_Passwords::get_user_application_password( $this->admin, $this->uuid ) );
		self::assertSame( ConnectionStore::REASON_RANKSPHERE, ( new ConnectionStore() )->last_disconnect()['reason'] ?? null );
		self::assertSame( array(), $this->outgoing, 'RankSphere asked – no need to tell it' );
	}

	public function test_revoking_the_password_ends_the_connection_and_tells_ranksphere(): void {
		$this->connect();

		WP_Application_Passwords::delete_application_password( $this->admin, $this->uuid );

		self::assertNull( ( new ConnectionStore() )->get() );
		self::assertSame( ConnectionStore::REASON_REVOKED, ( new ConnectionStore() )->last_disconnect()['reason'] ?? null );
		self::assertCount( 1, $this->outgoing );
		self::assertSame( 'https://ranksphere.test/api/wordpress/v1/disconnect', $this->outgoing[0]['url'] );

		$headers = $this->outgoing[0]['args']['headers'];
		self::assertIsArray( $headers );
		self::assertSame( 'Bearer ' . self::TOKEN, $headers['Authorization'] );
		$body = $this->outgoing[0]['args']['body'];
		self::assertIsString( $body );
		self::assertStringContainsString( '"reason":"revoked"', $body );
		$signature = $headers[ RequestVerifier::HEADER_SIGNATURE ];
		$timestamp = $headers[ RequestVerifier::HEADER_TIMESTAMP ];
		self::assertIsString( $signature );
		self::assertIsString( $timestamp );
		self::assertTrue(
			( new Signature( self::SECRET ) )->verify(
				$signature,
				(int) $timestamp,
				'POST',
				'/api/wordpress/v1/disconnect',
				$body,
				time()
			)
		);
	}

	public function test_another_users_password_does_not_matter(): void {
		$this->connect();
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		self::assertIsInt( $other );
		$uuid = $this->approve( $other );

		WP_Application_Passwords::delete_application_password( $other, $uuid );

		self::assertNotNull( ( new ConnectionStore() )->get() );
	}

	public function test_deleting_the_user_ends_the_connection(): void {
		$this->connect();
		require_once ABSPATH . 'wp-admin/includes/user.php';

		wp_delete_user( $this->admin );

		self::assertNull( ( new ConnectionStore() )->get() );
		self::assertSame( ConnectionStore::REASON_USER_DELETED, ( new ConnectionStore() )->last_disconnect()['reason'] ?? null );
	}

	public function test_the_admin_page_shows_the_connection_and_disconnects(): void {
		$this->connect();
		unset( $GLOBALS['wp_rest_application_password_uuid'] );

		ob_start();
		( new SettingsPage() )->render();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'Musterprojekt', $html );
		self::assertStringContainsString( 'https://ranksphere.test/projects/muster', $html );
		self::assertStringNotContainsString( self::SECRET, $html );

		$_REQUEST['_wpnonce'] = wp_create_nonce( SettingsPage::DISCONNECT_ACTION );
		add_filter(
			'wp_redirect',
			static function ( string $location ): string {
				throw new \RuntimeException( 'redirect:' . $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test only.
			}
		);

		try {
			( new SettingsPage() )->handle_disconnect();
			self::fail( 'expected a redirect' );
		} catch ( \RuntimeException $e ) {
			self::assertStringContainsString( 'page=' . SettingsPage::SLUG, $e->getMessage() );
		}

		self::assertNull( ( new ConnectionStore() )->get() );
		self::assertNull( WP_Application_Passwords::get_user_application_password( $this->admin, $this->uuid ) );
		self::assertCount( 1, $this->outgoing, 'RankSphere is told' );

		ob_start();
		( new SettingsPage() )->render();
		self::assertStringContainsString( 'disconnected from RankSphere', (string) ob_get_clean() );
	}

	/**
	 * The stored project name, null when not connected.
	 */
	private function project_name(): ?string {
		$connection = ( new ConnectionStore() )->get();

		return null !== $connection ? $connection->project_name : null;
	}
}
