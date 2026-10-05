<?php
/**
 * "Create suggestion" and "Apply" in the box on the edit screen; post types in the status.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Tests\Integration;

use RankSphere\Seo\History;
use RankSphere\Seo\SeoService;
use WP_REST_Request;

/**
 * The site asks RankSphere (signed, with the SEO plugin's current values and the post's text), polls,
 * and writes a taken-over field through the SEO plugin with history – then reports it to RankSphere.
 */
final class SuggestionsTest extends RankSphereTestCase {

	/**
	 * Requests to RankSphere's /suggestions and /changes: method, path and decoded body.
	 *
	 * @var list<array{method: string, path: string, body: array<mixed>}>
	 */
	private array $asked = array();

	/**
	 * RankSphere's answer to GET /suggestions.
	 *
	 * @var array<string, mixed>
	 */
	private array $state = array( 'status' => 'pending' );

	public function set_up(): void {
		parent::set_up();

		$this->asked = array();
		$this->state = array( 'status' => 'pending' );

		add_filter(
			'pre_http_request',
			/**
			 * Answers like RankSphere (after the base class' catch-all).
			 *
			 * @param false|array<string, mixed> $preempt Earlier answer.
			 * @param array<string, mixed>       $args    Request arguments.
			 * @param string                     $url     Address.
			 *
			 * @return false|array<string, mixed>
			 */
			function ( $preempt, array $args, string $url ) {
				$path = (string) wp_parse_url( $url, PHP_URL_PATH );

				if ( ! in_array( $path, array( '/api/wordpress/v1/suggestions', '/api/wordpress/v1/changes' ), true ) ) {
					return $preempt;
				}

				$method        = is_string( $args['method'] ?? null ) ? $args['method'] : 'GET';
				$body          = json_decode( is_string( $args['body'] ?? null ) ? $args['body'] : '', true );
				$this->asked[] = array(
					'method' => $method,
					'path'   => $path,
					'body'   => is_array( $body ) ? $body : array(),
				);

				return array(
					'headers'  => array(),
					'body'     => (string) wp_json_encode( 'POST' === $method && str_ends_with( $path, '/suggestions' ) ? array( 'status' => 'pending' ) : $this->state ),
					'response' => array(
						'code'    => str_ends_with( $path, '/changes' ) ? 201 : 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			20,
			3
		);
	}

	public function test_a_suggestion_is_asked_for_polled_and_shown_escaped(): void {
		$this->connect();
		$post = $this->published( '<p>Wir warten Heizungen.</p>[gallery]' );
		SeoService::current()->update( $post, array( 'title' => 'Wartung – Alt' ) );
		$this->as_editor();

		$started = $this->dispatch( self::request( 'POST', '/ranksphere/v1/page-suggestion', $post ) );

		self::assertSame( 'pending', self::text( $started, 'status' ) );
		self::assertStringContainsString( 'RankSphere is writing a suggestion', self::text( $started, 'html' ) );
		$sent = $this->asked[0];
		self::assertSame( 'POST', $sent['method'] );
		self::assertSame( get_permalink( $post ), $sent['body']['url'] ?? null );
		$current = $sent['body']['current'] ?? null;
		self::assertIsArray( $current );
		self::assertSame( 'Wartung – Alt', $current['title'] ?? null, 'what the SEO plugin holds now' );
		self::assertSame( 'Wir warten Heizungen.', $sent['body']['content'] ?? null, 'plain text, no shortcodes' );

		$this->state = array(
			'status'      => 'done',
			'title'       => 'Heizungswartung <b>Kosten</b> | Muster',
			'description' => null,
			'why'         => 'Suchanfrage nach vorne.',
		);
		$html        = self::text( $this->dispatch( self::request( 'GET', '/ranksphere/v1/page-suggestion', $post ) ), 'html' );

		self::assertStringContainsString( 'Heizungswartung &lt;b&gt;Kosten&lt;/b&gt; | Muster', $html, 'escaped' );
		self::assertStringContainsString( 'data-ranksphere-apply="title"', $html );
		self::assertStringNotContainsString( 'data-ranksphere-apply="description"', $html, 'a field RankSphere left out is not offered' );
		self::assertStringContainsString( 'Wartung – Alt', $html, 'next to the current value' );

		$this->state = array(
			'status' => 'failed',
			'error'  => 'Für Vorschläge mit KI zuerst den OpenRouter-Key eintragen.',
		);
		self::assertStringContainsString( 'OpenRouter-Key', self::text( $this->dispatch( self::request( 'GET', '/ranksphere/v1/page-suggestion', $post ) ), 'html' ) );
	}

	public function test_applying_writes_the_seo_plugin_with_history_and_tells_ranksphere(): void {
		$this->connect();
		$post = $this->published( 'Text' );
		$this->as_editor();

		$request = self::request( 'POST', '/ranksphere/v1/page-suggestion/apply', $post );
		$request->set_param( 'field', 'title' );
		$request->set_param( 'value', 'Heizungswartung: Kosten & Ablauf' );
		$response = $this->dispatch( $request );

		self::assertSame( 'applied', self::text( $response, 'status' ) );
		self::assertStringContainsString( 'data-ranksphere-reload', self::text( $response, 'html' ) );
		self::assertSame( 'Heizungswartung: Kosten & Ablauf', SeoService::current()->read( $post )['title'] );

		$history = ( new History() )->all( $post );
		self::assertSame( 'suggestion', end( $history )['source'] ?? null );

		$change = $this->asked[0];
		self::assertSame( '/api/wordpress/v1/changes', $change['path'] );
		$after = $change['body']['after'] ?? null;
		self::assertIsArray( $after );
		self::assertSame( 'Heizungswartung: Kosten & Ablauf', $after['title'] ?? null );
		self::assertSame( end( $history )['id'] ?? null, $change['body']['history_id'] ?? null );
		self::assertSame( 'Redakteurin', $change['body']['by'] ?? null );
	}

	public function test_only_people_who_may_edit_the_post(): void {
		$this->connect();
		$post        = $this->published( 'Text' );
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		self::assertIsInt( $contributor );
		wp_set_current_user( $contributor );
		unset( $GLOBALS['wp_rest_application_password_uuid'] );

		self::assertSame( 403, $this->dispatch( self::request( 'POST', '/ranksphere/v1/page-suggestion', $post ) )->get_status() );
		self::assertSame( 403, $this->dispatch( self::request( 'GET', '/ranksphere/v1/page-suggestion', $post ) )->get_status() );

		$apply = self::request( 'POST', '/ranksphere/v1/page-suggestion/apply', $post );
		$apply->set_param( 'field', 'title' );
		$apply->set_param( 'value', 'X' );
		self::assertSame( 403, $this->dispatch( $apply )->get_status() );
		self::assertSame( array(), $this->asked );
	}

	public function test_the_status_lists_the_post_types_a_text_can_become(): void {
		register_post_type(
			'leistungen',
			array(
				'public'   => true,
				'label'    => 'Leistungen',
				'labels'   => array( 'singular_name' => 'Leistung' ),
				'supports' => array( 'title', 'editor' ),
			)
		);
		register_post_type(
			'no_editor',
			array(
				'public'   => true,
				'supports' => array( 'title' ),
			)
		);
		$this->connect();

		$types = $this->dispatch( $this->sign( new WP_REST_Request( 'GET', '/ranksphere/v1/status' ) ) )->get_data();
		self::assertIsArray( $types );
		self::assertIsArray( $types['post_types'] ?? null );
		$names = array_column( $types['post_types'], 'label', 'name' );

		self::assertArrayHasKey( 'post', $names );
		self::assertArrayHasKey( 'page', $names );
		self::assertSame( 'Leistung', $names['leistungen'] ?? null );
		self::assertArrayNotHasKey( 'attachment', $names );
		self::assertArrayNotHasKey( 'no_editor', $names, 'a text needs an editor' );

		unregister_post_type( 'leistungen' );
		unregister_post_type( 'no_editor' );
	}

	/**
	 * A published post.
	 *
	 * @param string $content Its content.
	 */
	private function published( string $content ): int {
		$post = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => $content,
				'post_excerpt' => '',
			)
		);
		self::assertIsInt( $post );

		return $post;
	}

	/**
	 * Logged in as an editor (cookie, not RankSphere's application password).
	 */
	private function as_editor(): void {
		$editor = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Redakteurin',
			)
		);
		self::assertIsInt( $editor );
		wp_set_current_user( $editor );
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
	}

	/**
	 * A request of the box' script.
	 *
	 * @param string $method GET or POST.
	 * @param string $route  The route.
	 * @param int    $post   The post.
	 *
	 * @phpstan-return WP_REST_Request<array<string, mixed>>
	 */
	private static function request( string $method, string $route, int $post ): WP_REST_Request {
		$request = new WP_REST_Request( $method, $route );
		$request->set_param( 'post_id', $post );

		return $request;
	}
}
