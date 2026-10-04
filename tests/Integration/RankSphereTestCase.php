<?php
/**
 * Shared setup: a connected RankSphere, signed requests, captured outgoing calls.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Tests\Integration;

use RankSphere\Security\RequestVerifier;
use RankSphere\Security\Signature;
use WP_Application_Passwords;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * An administrator with an application password for RankSphere; helpers to connect and to send
 * requests the way RankSphere signs them.
 */
abstract class RankSphereTestCase extends WP_UnitTestCase {

	protected const SECRET = 'c2VjcmV0LXRoYXQtaXMtbG9uZy1lbm91Z2gtZm9yLWhtYWMtc2hhMjU2LTEyMzQ1Njc4';

	protected const TOKEN = 'site-token-0123456789abcdefghijklmnopqrstuvwxyz';

	/**
	 * The administrator who approves RankSphere.
	 *
	 * @var int
	 */
	protected int $admin;

	/**
	 * Their application password for RankSphere.
	 *
	 * @var string
	 */
	protected string $uuid;

	/**
	 * Requests RankSphere sent to its API, captured instead of sent.
	 *
	 * @var list<array{url: string, args: array<mixed>}>
	 */
	protected array $outgoing = array();

	/**
	 * Counts signed requests.
	 *
	 * @var int
	 */
	private static int $sequence = 0;

	/**
	 * Time of the first signed request.
	 *
	 * @var int|null
	 */
	private static ?int $start = null;

	public function set_up(): void {
		parent::set_up();

		// A fresh REST server per test, as WordPress' own REST tests do.
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress' global.
		do_action( 'rest_api_init', $wp_rest_server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress' hook.

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		self::assertIsInt( $admin );
		$this->admin = $admin;
		$this->uuid  = $this->approve( $admin );

		add_filter(
			'pre_http_request',
			/**
			 * Captures the request.
			 *
			 * @param false|array<string, mixed> $preempt Ignored.
			 * @param array<string, mixed>       $args    Request arguments.
			 * @param string                     $url     Address.
			 */
			function ( $preempt, array $args, string $url ): array {
				$this->outgoing[] = array(
					'url'  => $url,
					'args' => $args,
				);

				return array(
					'headers'  => array(),
					'body'     => '{}',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	public function tear_down(): void {
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Creates an application password for the user and authenticates the request with it.
	 *
	 * @param int $user_id The user.
	 */
	protected function approve( int $user_id ): string {
		$created = WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => 'RankSphere' ) );
		self::assertIsArray( $created );
		$uuid = $created[1]['uuid'];
		self::assertIsString( $uuid );

		wp_set_current_user( $user_id );
		$GLOBALS['wp_rest_application_password_uuid'] = $uuid; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- set by WordPress when an application password authenticates.

		return $uuid;
	}

	/**
	 * Back to the approved administrator's application password.
	 */
	protected function authenticate(): void {
		wp_set_current_user( $this->admin );
		$GLOBALS['wp_rest_application_password_uuid'] = $this->uuid; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- set by WordPress when an application password authenticates.
	}

	/**
	 * Connects the site as RankSphere does.
	 */
	protected function connect(): void {
		$this->authenticate();
		self::assertSame( 201, $this->dispatch( $this->connect_request() )->get_status() );
	}

	/**
	 * POST /connection with RankSphere's body.
	 *
	 * @param array<string, string> $overrides Changed fields.
	 *
	 * @return WP_REST_Request
	 * @phpstan-return WP_REST_Request<array<string, mixed>>
	 */
	protected function connect_request( array $overrides = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/ranksphere/v1/connection' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				$overrides + array(
					'project_id'   => 'muster',
					'project_name' => 'Musterprojekt',
					'project_url'  => 'https://ranksphere.test/projects/muster',
					'api_url'      => 'https://ranksphere.test/api/wordpress/v1/',
					'secret'       => self::SECRET,
					'site_token'   => self::TOKEN,
				)
			)
		);

		return $request;
	}

	/**
	 * Adds RankSphere's signature headers.
	 *
	 * @param WP_REST_Request $request   The request.
	 * @param int|null        $timestamp Signing time, now by default.
	 * @param string          $secret    Signing secret.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return WP_REST_Request
	 * @phpstan-return WP_REST_Request<array<string, mixed>>
	 */
	protected function sign( WP_REST_Request $request, ?int $timestamp = null, string $secret = self::SECRET ): WP_REST_Request {
		// A distinct timestamp per request: identical requests would otherwise carry the same signature,
		// which the replay protection rightly refuses.
		self::$start ??= time();
		$offset        = self::$sequence % 250;
		++self::$sequence;
		$timestamp ??= self::$start - $offset;
		$query       = $request->get_query_params();
		unset( $query['rest_route'] );

		$request->set_header( RequestVerifier::HEADER_TIMESTAMP, (string) $timestamp );
		$request->set_header(
			RequestVerifier::HEADER_SIGNATURE,
			( new Signature( $secret ) )->sign( $timestamp, $request->get_method(), Signature::canonical_path( $request->get_route(), $query ), (string) $request->get_body() )
		);

		return $request;
	}

	/**
	 * Runs the request through the REST server.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	protected function dispatch( WP_REST_Request $request ): WP_REST_Response {
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A value from a REST answer, following nested keys; null when missing.
	 *
	 * @param WP_REST_Response $response The answer.
	 * @param string           ...$path  Keys.
	 */
	protected static function value( WP_REST_Response $response, string ...$path ): mixed {
		$value = $response->get_data();

		foreach ( $path as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return null;
			}

			$value = $value[ $key ];
		}

		return $value;
	}

	/**
	 * A string from a REST answer; fails the test when it is none.
	 *
	 * @param WP_REST_Response $response The answer.
	 * @param string           ...$path  Keys.
	 */
	protected static function text( WP_REST_Response $response, string ...$path ): string {
		$value = self::value( $response, ...$path );
		self::assertIsString( $value );

		return $value;
	}
}
