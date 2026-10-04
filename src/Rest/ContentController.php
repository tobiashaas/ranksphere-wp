<?php
/**
 * REST routes for SEO fields and drafts.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Rest;

use RankSphere\Content\Drafts;
use RankSphere\Security\RequestVerifier;
use RankSphere\Seo\SeoFields;
use RankSphere\Seo\SeoPlugins;
use RankSphere\Seo\SeoService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `ranksphere/v1/lookup`, `/posts/{id}/seo` (+ undo) and `/drafts` as described in docs/CONTRACT.md.
 * Every route: signed by RankSphere (RequestVerifier), then the approving user's own capabilities –
 * RankSphere can do nothing that user could not do in the editor.
 */
final class ContentController {

	/**
	 * Takes its collaborators.
	 *
	 * @param RequestVerifier $verifier Checks RankSphere's requests.
	 * @param Drafts          $drafts   Creates and updates drafts.
	 */
	public function __construct(
		private readonly RequestVerifier $verifier = new RequestVerifier(),
		private readonly Drafts $drafts = new Drafts(),
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
		$id = array(
			'id' => array(
				'type'              => 'integer',
				'minimum'           => 1,
				'required'          => true,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			ConnectionController::NAMESPACE,
			'/lookup',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'lookup' ),
				'permission_callback' => fn ( WP_REST_Request $request ) => $this->allowed( $request, 'edit_posts' ),
				'args'                => array(
					'url' => array(
						'type'              => 'string',
						'required'          => true,
						'maxLength'         => 2000,
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		register_rest_route(
			ConnectionController::NAMESPACE,
			'/posts/(?P<id>\d+)/seo',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read_seo' ),
					'permission_callback' => fn ( WP_REST_Request $request ) => $this->allowed( $request, 'edit_post', $this->post_id( $request ) ),
					'args'                => $id,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_seo' ),
					'permission_callback' => fn ( WP_REST_Request $request ) => $this->allowed( $request, 'edit_post', $this->post_id( $request ) ),
					'args'                => $id,
				),
			)
		);

		register_rest_route(
			ConnectionController::NAMESPACE,
			'/posts/(?P<id>\d+)/seo/undo',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'undo_seo' ),
				'permission_callback' => fn ( WP_REST_Request $request ) => $this->allowed( $request, 'edit_post', $this->post_id( $request ) ),
				'args'                => $id + array(
					'history_id' => array(
						'type'              => 'string',
						'required'          => true,
						'pattern'           => '^[0-9a-f-]{36}$',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		register_rest_route(
			ConnectionController::NAMESPACE,
			'/drafts',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_draft' ),
				'permission_callback' => fn ( WP_REST_Request $request ) => $this->allowed( $request, 'edit_posts' ),
				'args'                => array(
					'ranksphere_id' => $this->text_arg( 1, 64, '^[A-Za-z0-9_-]+$' ),
					'post_type'     => $this->text_arg( 1, 20, '^[a-z0-9_-]+$' ),
					'title'         => $this->text_arg( 1, 300 ),
					'content'       => $this->text_arg( 1, 500000 ),
					'slug'          => array( 'required' => false ) + $this->text_arg( 0, 200 ),
					'excerpt'       => array( 'required' => false ) + $this->text_arg( 0, 2000 ),
				),
			)
		);
	}

	/**
	 * GET /lookup?url= – which post a URL of the site belongs to.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function lookup( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$url     = $this->string_param( $request, 'url' );
		$post_id = url_to_postid( $url );
		$address = (string) preg_replace( '/[?#].*$/', '', $url );

		// The front page: url_to_postid() knows a static front page only by its own slug.
		if ( 0 === $post_id && untrailingslashit( $address ) === untrailingslashit( home_url() ) && 'page' === get_option( 'show_on_front' ) ) {
			$front   = get_option( 'page_on_front' );
			$post_id = is_numeric( $front ) ? (int) $front : 0;
		}

		$post = $post_id > 0 ? get_post( $post_id ) : null;

		if ( null === $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'ranksphere_not_found', __( 'No editable post or page has this address.', 'ranksphere' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $this->post_summary( $post ) );
	}

	/**
	 * GET /posts/{id}/seo.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function read_seo( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post = get_post( $this->post_id( $request ) );

		if ( null === $post ) {
			return $this->not_found();
		}

		$service = SeoService::current();

		return new WP_REST_Response(
			$this->post_summary( $post ) + array(
				'seo_plugin' => SeoPlugins::active(),
				'supports'   => $service->adapter()->supports(),
				'seo'        => $service->read( $post->ID ),
			)
		);
	}

	/**
	 * PUT /posts/{id}/seo – partial update; only sent fields change, null = the plugin's default.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function update_seo( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post = get_post( $this->post_id( $request ) );

		if ( null === $post ) {
			return $this->not_found();
		}

		$changes = $this->seo_changes( $request->get_json_params() );

		if ( $changes instanceof WP_Error ) {
			return $changes;
		}

		try {
			$result = SeoService::current()->update( $post->ID, $changes );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'ranksphere_seo_plugin_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( $result + array( 'post_id' => $post->ID ) );
	}

	/**
	 * POST /posts/{id}/seo/undo.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function undo_seo( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post = get_post( $this->post_id( $request ) );

		if ( null === $post ) {
			return $this->not_found();
		}

		try {
			$result = SeoService::current()->undo( $post->ID, $this->string_param( $request, 'history_id' ) );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'ranksphere_seo_plugin_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		return $result instanceof WP_Error ? $result : new WP_REST_Response( $result + array( 'post_id' => $post->ID ) );
	}

	/**
	 * POST /drafts – creates or updates the draft for a RankSphere text. Never publishes.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function save_draft( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_type = $this->string_param( $request, 'post_type' );
		$type      = get_post_type_object( $post_type );
		$create    = null !== $type && is_string( $type->cap->create_posts ?? null ) ? $type->cap->create_posts : '';

		if ( null === $type || ! $type->public || 'attachment' === $post_type || '' === $create || ! current_user_can( $create ) ) {
			return new WP_Error( 'ranksphere_invalid_content', __( 'Drafts can only be posts or pages the user may create.', 'ranksphere' ), array( 'status' => 400 ) );
		}

		$body = $request->get_json_params();
		$seo  = $this->seo_changes( is_array( $body['seo'] ?? null ) ? $body['seo'] : array() );

		if ( $seo instanceof WP_Error ) {
			return $seo;
		}

		$draft = array(
			'ranksphere_id' => $this->string_param( $request, 'ranksphere_id' ),
			'post_type'     => $post_type,
			'title'         => sanitize_text_field( $this->string_param( $request, 'title' ) ),
			'content'       => $this->string_param( $request, 'content' ),
			'slug'          => $this->string_param( $request, 'slug' ),
			'excerpt'       => sanitize_textarea_field( $this->string_param( $request, 'excerpt' ) ),
		);

		try {
			$saved = $this->drafts->save( $draft, $seo );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'ranksphere_seo_plugin_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		if ( $saved instanceof WP_Error ) {
			return $saved;
		}

		$post = get_post( $saved['post_id'] );

		return new WP_REST_Response(
			array( 'created' => $saved['created'] ) + ( null !== $post ? $this->post_summary( $post ) : array( 'post_id' => $saved['post_id'] ) ),
			$saved['created'] ? 201 : 200
		);
	}

	/**
	 * Signed by RankSphere, then the user's capability.
	 *
	 * @param WP_REST_Request $request    The request.
	 * @param string          $capability Capability to check.
	 * @param int             ...$args    Its arguments (post ID).
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	private function allowed( WP_REST_Request $request, string $capability, int ...$args ): bool|WP_Error {
		$connection = $this->verifier->verify( $request );

		if ( $connection instanceof WP_Error ) {
			return $connection;
		}

		if ( current_user_can( $capability, ...$args ) ) {
			return true;
		}

		return new WP_Error( 'ranksphere_forbidden', __( 'The WordPress user RankSphere acts as may not do this.', 'ranksphere' ), array( 'status' => 403 ) );
	}

	/**
	 * The cleaned SEO fields of a body, or a 400.
	 *
	 * @param array<array-key, mixed>|null $input Decoded JSON.
	 *
	 * @return array<string, string|bool|list<string>|null>|WP_Error
	 */
	private function seo_changes( ?array $input ): array|WP_Error {
		try {
			return SeoFields::changes( $input ?? array() );
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'ranksphere_invalid_seo', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * What RankSphere needs to know about a post.
	 *
	 * @param \WP_Post $post The post.
	 *
	 * @return array<string, mixed>
	 */
	private function post_summary( \WP_Post $post ): array {
		return array(
			'post_id'     => $post->ID,
			'post_type'   => $post->post_type,
			'status'      => $post->post_status,
			'title'       => get_the_title( $post ),
			'url'         => (string) get_permalink( $post ),
			'edit_url'    => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
			'preview_url' => (string) get_preview_post_link( $post ),
		);
	}

	/**
	 * The {id} of the route.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	private function post_id( WP_REST_Request $request ): int {
		$id = $request->get_param( 'id' );

		return is_numeric( $id ) ? (int) $id : 0;
	}

	/**
	 * A string parameter, '' when missing.
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
	 * A required string argument.
	 *
	 * @param int    $min     Minimum length.
	 * @param int    $max     Maximum length.
	 * @param string $pattern Regular expression, '' for none.
	 *
	 * @return array<string, mixed>
	 */
	private function text_arg( int $min, int $max, string $pattern = '' ): array {
		$arg = array(
			'type'              => 'string',
			'required'          => true,
			'minLength'         => $min,
			'maxLength'         => $max,
			'validate_callback' => 'rest_validate_request_arg',
		);

		return '' === $pattern ? $arg : $arg + array( 'pattern' => $pattern );
	}

	/**
	 * The 404.
	 */
	private function not_found(): WP_Error {
		return new WP_Error( 'ranksphere_not_found', __( 'This post does not exist.', 'ranksphere' ), array( 'status' => 404 ) );
	}
}
