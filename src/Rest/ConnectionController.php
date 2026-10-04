<?php
/**
 * REST routes for the connection: status, connect, disconnect.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Rest;

use RankSphere\Connection\Connection;
use RankSphere\Connection\ConnectionStore;
use RankSphere\Connection\Disconnector;
use RankSphere\Security\RequestVerifier;
use RankSphere\Seo\SeoPlugins;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use const RankSphere\VERSION;

/**
 * `ranksphere/v1/status` and `ranksphere/v1/connection` as described in docs/CONTRACT.md.
 */
final class ConnectionController {

	public const NAMESPACE = 'ranksphere/v1';

	/**
	 * Takes its collaborators.
	 *
	 * @param ConnectionStore $store    Where the connection lives.
	 * @param RequestVerifier $verifier Checks RankSphere's requests.
	 */
	public function __construct(
		private readonly ConnectionStore $store = new ConnectionStore(),
		private readonly RequestVerifier $verifier = new RequestVerifier(),
	) {}

	/**
	 * Hooks the routes.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the routes (on `rest_api_init`).
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'can_read_status' ),
			)
		);

		$text = static function ( int $min, int $max, string $pattern = '' ): array {
			$arg = array(
				'type'              => 'string',
				'required'          => true,
				'minLength'         => $min,
				'maxLength'         => $max,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'rest_sanitize_request_arg',
			);

			return '' === $pattern ? $arg : $arg + array( 'pattern' => $pattern );
		};

		register_rest_route(
			self::NAMESPACE,
			'/connection',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'connect' ),
					'permission_callback' => array( $this, 'can_connect' ),
					'args'                => array(
						'project_id'   => $text( 1, 64, '^[A-Za-z0-9_-]+$' ),
						'project_name' => $text( 1, 200 ),
						'project_url'  => $text( 8, 500 ),
						'api_url'      => $text( 8, 500 ),
						'secret'       => $text( 32, 256 ),
						'site_token'   => $text( 32, 256 ),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'disconnect' ),
					'permission_callback' => array( $this, 'can_disconnect' ),
				),
			)
		);
	}

	/**
	 * GET /status – only RankSphere, signed, as a user who may at least write drafts.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function can_read_status( WP_REST_Request $request ): bool|WP_Error {
		$connection = $this->verifier->verify( $request );

		if ( $connection instanceof WP_Error ) {
			return $connection;
		}

		return current_user_can( 'edit_posts' ) ? true : $this->forbidden();
	}

	/**
	 * GET /status.
	 */
	public function status(): WP_REST_Response {
		return new WP_REST_Response( $this->payload( $this->store->get() ) );
	}

	/**
	 * POST /connection – only with an application password of an administrator. A connected site
	 * only accepts it signed with the current secret (RankSphere renewing the connection); to
	 * connect a different project, the site is disconnected first.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function can_connect( WP_REST_Request $request ): bool|WP_Error {
		if ( null === rest_get_authenticated_app_password() ) {
			return new WP_Error( 'ranksphere_app_password_required', __( 'Connect through RankSphere: it needs an application password for this site.', 'ranksphere' ), array( 'status' => 401 ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->forbidden();
		}

		$current = $this->store->get();

		if ( null !== $current && $this->verifier->verify( $request ) instanceof WP_Error ) {
			return new WP_Error(
				'ranksphere_already_connected',
				/* translators: %s: RankSphere project name. */
				sprintf( __( 'This site is already connected to the RankSphere project "%s". Disconnect it in WordPress first.', 'ranksphere' ), $current->project_name ),
				array(
					'status'       => 409,
					'project_name' => $current->project_name,
				)
			);
		}

		return true;
	}

	/**
	 * POST /connection.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function connect( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$api_url     = untrailingslashit( $this->string_param( $request, 'api_url' ) );
		$project_url = $this->string_param( $request, 'project_url' );

		if ( ! $this->is_allowed_url( $api_url ) || ! $this->is_allowed_url( $project_url ) ) {
			return new WP_Error( 'ranksphere_invalid_url', __( 'RankSphere addresses must use HTTPS.', 'ranksphere' ), array( 'status' => 400 ) );
		}

		$connection = new Connection(
			$this->string_param( $request, 'project_id' ),
			sanitize_text_field( $this->string_param( $request, 'project_name' ) ),
			$project_url,
			$api_url,
			$this->string_param( $request, 'secret' ),
			$this->string_param( $request, 'site_token' ),
			get_current_user_id(),
			(string) rest_get_authenticated_app_password(),
			time(),
		);

		$this->store->save( $connection );

		return new WP_REST_Response( $this->payload( $connection ), 201 );
	}

	/**
	 * DELETE /connection – RankSphere, signed with the current secret.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function can_disconnect( WP_REST_Request $request ): bool|WP_Error {
		$connection = $this->verifier->verify( $request );

		return $connection instanceof WP_Error ? $connection : true;
	}

	/**
	 * DELETE /connection: forgets the connection and removes the application password RankSphere
	 * used – after this RankSphere has no access left.
	 */
	public function disconnect(): WP_REST_Response {
		( new Disconnector( $this->store ) )->disconnect( ConnectionStore::REASON_RANKSPHERE, false, true );

		return new WP_REST_Response( $this->payload( null ) );
	}

	/**
	 * What RankSphere needs to know before it acts. Never the secret or the token.
	 *
	 * @param Connection|null $connection The connection, null when not connected.
	 *
	 * @return array<string, mixed>
	 */
	private function payload( ?Connection $connection ): array {
		return array(
			'connected'      => null !== $connection,
			'project_id'     => $connection?->project_id,
			'plugin_version' => VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'seo_plugin'     => SeoPlugins::active(),
			'abilities'      => function_exists( 'wp_register_ability' ),
			'site_url'       => home_url( '/' ),
			'post_types'     => self::post_types(),
		);
	}

	/**
	 * Post types a text from RankSphere can become: public ones with an editor, which the current user
	 * (RankSphere's) may create – custom ones like "Services" included, with the site's labels.
	 *
	 * @return list<array{name: string, label: string}>
	 */
	private static function post_types(): array {
		$types = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			$create = $type->cap->create_posts ?? null;
			$label  = $type->labels->singular_name ?? null;

			if ( 'attachment' === $type->name || ! $type->show_ui || ! post_type_supports( $type->name, 'editor' ) || ! is_string( $create ) || ! current_user_can( $create ) ) {
				continue;
			}

			$types[] = array(
				'name'  => $type->name,
				'label' => is_string( $label ) && '' !== $label ? $label : $type->name,
			);
		}

		return $types;
	}

	/**
	 * HTTPS only; plain HTTP only where `ranksphere_allow_insecure_urls` allows it (local and development sites).
	 *
	 * @param string $url The address.
	 */
	private function is_allowed_url( string $url ): bool {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}

		if ( 'https' === $scheme ) {
			return true;
		}

		/**
		 * Whether RankSphere may be reached over plain HTTP (a RankSphere running locally).
		 *
		 * @param bool $allowed True on local and development sites.
		 */
		$insecure = (bool) apply_filters( 'ranksphere_allow_insecure_urls', in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) );

		return 'http' === $scheme && $insecure;
	}

	/**
	 * A validated string parameter.
	 *
	 * @param WP_REST_Request $request The request.
	 * @param string          $key     Parameter name.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	private function string_param( WP_REST_Request $request, string $key ): string {
		$value = $request->get_param( $key );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * The generic refusal.
	 */
	private function forbidden(): WP_Error {
		return new WP_Error( 'ranksphere_forbidden', __( 'You are not allowed to do this.', 'ranksphere' ), array( 'status' => rest_authorization_required_code() ) );
	}
}
