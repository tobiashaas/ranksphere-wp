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
			'/api/wordpress/v1/texts'              => self::overview(),
			'/api/wordpress/v1/texts/7'            => self::written(),
			'/api/wordpress/v1/texts/ideas'        => array(
				'ideas' => array(
					array(
						'title'      => 'Eigene Seite zu „heizung warten“',
						'why'        => '300 × gesehen, aber keine Seite passt.',
						'area'       => 'Bei Google',
						'impact'     => 'medium',
						'type'       => 'landing',
						'type_label' => 'Leistungsseite',
						'topic'      => 'heizung warten',
						'page'       => null,
					),
				),
			),
			'/api/wordpress/v1/texts/7/versions/1' => array(
				'number'           => 1,
				'label'            => 'Erster Entwurf',
				'by'               => 'Anna',
				'score'            => 71,
				'created_at'       => '2026-10-05T08:00:00+00:00',
				'html'             => '<p>Die erste Fassung.</p>',
				'seo_title'        => 'Alt | Muster',
				'meta_description' => null,
				'questions'        => array(),
			),
			'/api/wordpress/v1/texts/7/draft'      => array(
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
		self::assertMatchesRegularExpression( '/page=ranksphere-texts&(amp|#038);text=7/', $html );
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

	public function test_the_form_offers_the_post_type_and_an_existing_post_without_typing_an_address(): void {
		$this->connect();
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Wartung & Service',
			)
		);
		self::assertIsInt( $page );
		$this->as_role( 'editor' );

		$_GET['post'] = (string) $page;
		$html         = $this->render( 0 );
		unset( $_GET['post'] );

		self::assertStringContainsString( 'name="post_type"', $html );
		self::assertMatchesRegularExpression( '/<option value="page"[^>]*selected/', $html, 'the post type of the chosen post' );
		self::assertMatchesRegularExpression( '/value="existing" data-ranksphere-mode checked/', $html );
		self::assertMatchesRegularExpression( '/<option value="' . $page . '" selected[^>]*>Wartung &amp; Service</', $html );
		self::assertMatchesRegularExpression( '/<option value="landing"[^>]*selected/', $html, 'a page presents a service' );
		self::assertStringNotContainsString( 'target_page', $html, 'no address to type' );
		self::assertStringContainsString( 'What to write about', $html );
		self::assertMatchesRegularExpression( '/type=landing&(amp|#038);topic=heizung%20warten/', $html, 'an idea fills the form' );
	}

	public function test_only_posts_the_user_may_edit_are_offered(): void {
		$this->connect();
		$other = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Fremder Beitrag',
			)
		);
		self::assertIsInt( $other );
		$author = $this->as_role( 'contributor' );
		$own    = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_title'  => 'Eigener Entwurf',
				'post_author' => $author,
			)
		);
		self::assertIsInt( $own );

		$titles = array_column( TextsPage::editable_posts( 'post' ), 'title', 'id' );

		self::assertArrayHasKey( $own, $titles );
		self::assertStringContainsString( 'Eigener Entwurf', $titles[ $own ] );
		self::assertArrayNotHasKey( $other, $titles );
		self::assertSame( array(), TextsPage::editable_posts( 'page' ), 'contributors write no pages' );
	}

	public function test_a_text_for_an_existing_post_sends_its_text_and_the_sites_pages(): void {
		$this->connect();
		$this->as_role( 'editor' );
		$contact = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Kontakt',
			)
		);
		self::assertIsInt( $contact );
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Wartung',
				'post_content' => "<!-- wp:heading -->\n<h2>Ablauf</h2>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p>Wir kommen [termin] einmal im Jahr.</p>\n<!-- /wp:paragraph -->",
			)
		);
		self::assertIsInt( $page_id );
		$page = get_post( $page_id );
		self::assertInstanceOf( \WP_Post::class, $page );

		add_shortcode( 'termin', static fn (): string => 'Termin' );
		( new Texts() )->start(
			array(
				'type'  => 'landing',
				'topic' => 'Wartung',
			),
			$page
		);
		remove_shortcode( 'termin' );

		$body = $this->asked[0]['body'];
		self::assertSame( 'page', $body['post_type'] ?? null );
		self::assertIsArray( $body['source'] ?? null );
		self::assertSame( $page->ID, $body['source']['post_id'] );
		self::assertSame( '/?page_id=' . $page->ID, $body['source']['path'] ?? null );
		self::assertIsString( $body['source']['content'] ?? null );
		self::assertStringContainsString( '## Ablauf', $body['source']['content'] );
		self::assertStringContainsString( 'Wir kommen einmal im Jahr.', (string) preg_replace( '/\s+/', ' ', $body['source']['content'] ) );
		self::assertIsArray( $body['site_pages'] ?? null );
		$paths = array_column( $body['site_pages'], 'path' );
		self::assertContains( '/?page_id=' . $contact, $paths );
		self::assertNotContains( '/?page_id=' . $page->ID, $paths, 'not a link to itself' );
	}

	public function test_a_text_for_a_published_page_is_saved_as_revision_next_to_it(): void {
		$this->connect();
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Wartung',
				'post_content' => '<p>Live.</p>',
			)
		);
		self::assertIsInt( $page );
		$this->as_role( 'editor' );
		$this->answers['/api/wordpress/v1/texts/7']                  = array(
			'source' => array(
				'post_id' => $page,
				'title'   => 'Wartung',
				'path'    => '/wartung/',
			),
		) + self::written();
		$this->answers['/api/wordpress/v1/texts/7/draft']['revises'] = $page;

		$html = $this->render( 7 );
		self::assertStringContainsString( 'Save as revision', $html );
		self::assertStringContainsString( 'stays as it is', $html );
		self::assertStringNotContainsString( '<select id="rs-post-type"', $html, 'the type is the page\'s' );

		$saved = ( new Texts() )->save_draft( 7, 'page' );

		self::assertIsArray( $saved );
		self::assertSame( 'revision', $saved['mode'] ?? null );
		self::assertSame( '<p>Live.</p>', get_post( $page )->post_content ?? null );
		self::assertSame( $page, \RankSphere\Content\Drafts::meta_int( $saved['post_id'], \RankSphere\Content\Drafts::REVISES_META ) );
	}

	public function test_versions_can_be_seen_and_a_note_revises_the_text(): void {
		$this->connect();
		$this->as_role( 'author' );

		$html = $this->render( 7 );
		self::assertStringContainsString( 'name="note"', $html );
		self::assertStringContainsString( 'Nachgebessert: „kürzer“', $html );
		self::assertStringContainsString( 'version=1', $html );

		$_GET['version'] = '1';
		$old             = $this->render( 7 );
		unset( $_GET['version'] );
		self::assertStringContainsString( 'Die erste Fassung.', $old );
		self::assertStringContainsString( 'Restore this version', $old );
		self::assertStringContainsString( 'Alt | Muster', $old );

		( new Texts() )->note( 7, 'Bitte kürzer.' );
		$note = array_values( array_filter( $this->asked, static fn ( array $request ): bool => str_ends_with( $request['path'], '/notes' ) ) );
		self::assertSame( 'Bitte kürzer.', $note[0]['body']['note'] ?? null );
		self::assertSame( 'Autorin', $note[0]['body']['by'] ?? null );
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
		delete_transient( 'ranksphere_text_ideas_' . md5( 'https://ranksphere.test/projects/muster|' . \RankSphere\Insights\Insights::language() ) );

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
				array(
					'key'      => 'landing',
					'label'    => 'Leistungsseite',
					'hint'     => 'Stellt eine Leistung vor.',
					'required' => array(),
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
			'source'            => null,
			'versions'          => array(
				array(
					'number'     => 2,
					'reason'     => 'note',
					'label'      => 'Nachgebessert: „kürzer“',
					'by'         => 'Ben',
					'score'      => 84,
					'created_at' => '2026-10-05T09:00:00+00:00',
				),
				array(
					'number'     => 1,
					'reason'     => 'start',
					'label'      => 'Erster Entwurf',
					'by'         => 'Anna',
					'score'      => 71,
					'created_at' => '2026-10-05T08:00:00+00:00',
				),
			),
		);
	}
}
