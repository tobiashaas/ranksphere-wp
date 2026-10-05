<?php
/**
 * RankSphere → Texts: start, follow, answer, save as draft – in WordPress.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Tests\Integration;

use RankSphere\Admin\TextsPage;
use RankSphere\Content\Drafts;
use RankSphere\Content\Texts;
use RankSphere\Seo\SeoService;

/**
 * RankSphere writes; the page shows its answers escaped, the draft is saved here with the current
 * user as author and reported back.
 */
final class TextsTest extends RankSphereTestCase {

	/**
	 * Requests to RankSphere's /texts: method, path, query and body.
	 *
	 * @var list<array{method: string, path: string, url: string, body: array<mixed>}>
	 */
	private array $asked = array();

	/**
	 * RankSphere's answers by path.
	 *
	 * @var array<string, array<mixed>>
	 */
	private array $answers = array();

	public function set_up(): void {
		parent::set_up();

		$this->asked   = array();
		$this->answers = array(
			'/api/wordpress/v1/texts'         => self::overview(),
			'/api/wordpress/v1/texts/7'       => self::written(),
			'/api/wordpress/v1/texts/7/draft' => array(
				'ranksphere_id' => 'text-7',
				'post_type'     => 'page',
				'title'         => 'Heizungswartung für Ihr Haus',
				'slug'          => 'heizungswartung',
				'content'       => "<!-- wp:paragraph -->\n<p>Wir warten Heizungen.</p>\n<!-- /wp:paragraph -->",
				'seo'           => array(
					'title'       => 'Heizungswartung | Muster',
					'description' => 'Fester Termin, Protokoll, kleine Mängel gleich behoben.',
				),
			),
		);

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

				if ( ! str_starts_with( $path, '/api/wordpress/v1/texts' ) ) {
					return $preempt;
				}

				$body          = json_decode( is_string( $args['body'] ?? null ) ? $args['body'] : '', true );
				$this->asked[] = array(
					'method' => is_string( $args['method'] ?? null ) ? $args['method'] : 'GET',
					'path'   => $path,
					'url'    => $url,
					'body'   => is_array( $body ) ? $body : array(),
				);

				return array(
					'headers'  => array(),
					'body'     => (string) wp_json_encode( $this->answers[ $path ] ?? array( 'id' => 7 ) ),
					'response' => array(
						'code'    => 200,
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

	public function test_the_list_offers_a_new_text_with_the_facts_of_each_kind(): void {
		$this->connect();
		$this->as_role( 'author' );

		$html = $this->render( 0 );

		self::assertStringContainsString( 'value="guide"', $html );
		self::assertStringContainsString( 'data-ranksphere-type-fields="success_story" hidden', $html, 'facts of other kinds stay hidden' );
		self::assertStringContainsString( 'name="required_success_story_quote"', $html );
		self::assertStringContainsString( 'Heizung &lt;b&gt;tauschen&lt;/b&gt;', $html, 'escaped' );
		self::assertStringContainsString( 'page=ranksphere-texts&amp;text=7', $html );
		self::assertStringContainsString( 'rs-pill-watch', $html );

		$this->answers['/api/wordpress/v1/texts'] = array( 'blocked' => 'Für Texte braucht RankSphere einen KI-Zugang.' ) + self::overview();
		$blocked                                  = $this->render( 0 );
		self::assertStringContainsString( 'KI-Zugang', $blocked );
		self::assertStringNotContainsString( 'name="topic"', $blocked, 'no form while RankSphere cannot write' );
	}

	public function test_a_finished_text_shows_its_questions_review_and_the_draft_form(): void {
		$this->connect();
		$this->as_role( 'editor' );

		$html = $this->render( 7 );

		self::assertStringContainsString( 'rs-tile-focus', $html, 'open questions are the next step' );
		self::assertStringContainsString( 'name="answers[An welchen Tagen gibt es Termine?]"', $html );
		self::assertStringContainsString( '<mark>[[Angabe fehlt: Wochentag]]</mark>', $html );
		self::assertStringNotContainsString( '<script', $html );
		self::assertStringContainsString( 'name="post_type"', $html );
		self::assertMatchesRegularExpression( '/<option value="page" selected/', $html, 'the default for the kind of text' );
		self::assertStringContainsString( '84', $html );

		$this->answers['/api/wordpress/v1/texts/7'] = array( 'status' => 'pending' ) + self::written();
		self::assertStringContainsString( 'data-ranksphere-refresh', $this->render( 7 ) );
	}

	public function test_saving_creates_the_draft_with_the_current_author_and_tells_ranksphere(): void {
		$this->connect();
		$editor = $this->as_role( 'editor' );

		$saved = ( new Texts() )->save_draft( 7, 'page' );

		self::assertIsArray( $saved );
		$post = get_post( $saved['post_id'] );
		self::assertNotNull( $post );
		self::assertSame( 'draft', $post->post_status );
		self::assertSame( 'page', $post->post_type );
		self::assertSame( $editor, (int) $post->post_author, 'the person who saved it writes it' );
		self::assertSame( 'text-7', get_post_meta( $post->ID, Drafts::META, true ) );
		self::assertSame( 'Heizungswartung | Muster', SeoService::current()->read( $post->ID )['title'] );

		$pushed = array_values( array_filter( $this->asked, static fn ( array $request ): bool => str_ends_with( $request['path'], '/pushed' ) ) );
		self::assertSame( $post->ID, $pushed[0]['body']['post_id'] ?? null );
		self::assertSame( 'page', $pushed[0]['body']['post_type'] ?? null );

		$again = ( new Texts() )->save_draft( 7, 'page' );
		self::assertIsArray( $again );
		self::assertSame( $post->ID, $again['post_id'], 'the same draft' );
	}

	public function test_a_contributor_cannot_create_pages_from_a_text(): void {
		$this->connect();
		$this->as_role( 'contributor' );

		$saved = ( new Texts() )->save_draft( 7, 'page' );

		self::assertInstanceOf( \WP_Error::class, $saved );
		self::assertSame( array( 'post' => 'Post' ), Texts::post_types(), 'contributors may create posts only' );
	}

	public function test_a_new_text_carries_the_author_and_the_language(): void {
		$this->connect();
		$this->as_role( 'author' );

		( new Texts() )->start(
			array(
				'type'  => 'guide',
				'topic' => 'Wie lange hält eine Heizung?',
				'notes' => '',
			)
		);

		$sent = $this->asked[0];
		self::assertSame( 'POST', $sent['method'] );
		self::assertSame( 'Autorin', $sent['body']['by'] ?? null );
		self::assertSame( 'en_US', $sent['body']['lang'] ?? null );
		self::assertArrayNotHasKey( 'notes', $sent['body'], 'empty fields stay out' );
	}

	/**
	 * The page's HTML.
	 *
	 * @param int $text RankSphere's id, 0 for the list.
	 */
	private function render( int $text ): string {
		$_GET['text'] = (string) $text;
		ob_start();
		( new TextsPage() )->render();
		unset( $_GET['text'] );

		return (string) ob_get_clean();
	}

	/**
	 * Logged in with a role (cookie, not RankSphere's application password).
	 *
	 * @param string $role The role.
	 */
	private function as_role( string $role ): int {
		$user = self::factory()->user->create(
			array(
				'role'         => $role,
				'display_name' => 'Autorin',
			)
		);
		self::assertIsInt( $user );
		wp_set_current_user( $user );
		unset( $GLOBALS['wp_rest_application_password_uuid'] );

		return $user;
	}

	/**
	 * RankSphere's GET /texts.
	 *
	 * @return array<string, mixed>
	 */
	private static function overview(): array {
		return array(
			'types'   => array(
				array(
					'key'      => 'guide',
					'label'    => 'Ratgeber-Artikel',
					'hint'     => 'Beantwortet eine Frage.',
					'required' => array(),
				),
				array(
					'key'      => 'success_story',
					'label'    => 'Erfolgsgeschichte',
					'hint'     => 'Ein echter Kunde.',
					'required' => array(
						array(
							'key'   => 'quote',
							'label' => 'Freigegebenes Zitat',
						),
					),
				),
			),
			'texts'   => array(
				array(
					'id'                => 7,
					'title'             => 'Heizung <b>tauschen</b>',
					'type'              => 'Leistungsseite',
					'status'            => 'done',
					'state'             => '1 Angabe fehlt',
					'tone'              => 'watch',
					'open_questions'    => 1,
					'wordpress_post_id' => null,
					'by'                => 'Anna',
					'created_at'        => '2026-10-05T08:00:00+00:00',
					'url'               => 'https://ranksphere.test/projects/muster/content?draft=7',
				),
			),
			'blocked' => null,
			'url'     => 'https://ranksphere.test/projects/muster/content',
		);
	}

	/**
	 * RankSphere's GET /texts/7.
	 *
	 * @return array<string, mixed>
	 */
	private static function written(): array {
		return array(
			'id'                => 7,
			'title'             => 'Heizungswartung für Ihr Haus',
			'type'              => 'Leistungsseite',
			'status'            => 'done',
			'state'             => '1 Angabe fehlt',
			'tone'              => 'watch',
			'open_questions'    => 1,
			'wordpress_post_id' => null,
			'by'                => 'Anna',
			'url'               => 'https://ranksphere.test/projects/muster/content?draft=7',
			'topic'             => 'Heizungswartung',
			'error'             => null,
			'html'              => '<h1>Heizungswartung</h1><p>Termine ab <mark>[[Angabe fehlt: Wochentag]]</mark>.</p><script>alert(1)</script>',
			'seo_title'         => 'Heizungswartung | Muster',
			'meta_description'  => 'Fester Termin, Protokoll, kleine Mängel gleich behoben.',
			'questions'         => array( 'An welchen Tagen gibt es Termine?' ),
			'answers'           => array(),
			'review'            => array(
				'score'  => 84,
				'passed' => false,
				'issues' => array(
					array(
						'priority' => 'P1',
						'problem'  => 'Der Termin fehlt.',
						'fix'      => null,
					),
				),
			),
			'post_type'         => null,
			'default_post_type' => 'page',
		);
	}
}
