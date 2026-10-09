<?php
/**
 * Texts for existing posts: into an unpublished original, as revision of a published one, taken
 * over in the editor – with history.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Tests\Integration;

use RankSphere\Admin\ApplyRevision;
use RankSphere\Content\Drafts;
use RankSphere\Content\TextHistory;
use RankSphere\Seo\SeoService;
use WP_REST_Request;

/**
 * A published page is never changed by RankSphere: the rewrite becomes a draft next to it that the
 * author takes over in the editor and publishes with "Update" themselves. Content that was replaced
 * stays a WordPress revision the history links to.
 */
final class RevisionsTest extends RankSphereTestCase {

	public function set_up(): void {
		parent::set_up();

		// Each test starts without enqueued scripts.
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress' own tests reset it the same way.
	}

	public function test_an_unpublished_original_gets_the_text_and_keeps_its_content_as_revision(): void {
		$this->connect();
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		self::assertIsInt( $author );
		$original = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_author'  => $author,
				'post_title'   => 'Alt',
				'post_content' => '<p>Der alte Text.</p>',
			)
		);
		self::assertIsInt( $original );
		wp_set_current_user( $this->admin );

		$saved = ( new Drafts() )->save( self::rewrite( $original ), array( 'title' => 'Wartung | Muster' ) );

		self::assertIsArray( $saved );
		self::assertSame( $original, $saved['post_id'] );
		self::assertSame( 'original', $saved['mode'] ?? null );
		$post = get_post( $original );
		self::assertNotNull( $post );
		self::assertSame( 'draft', $post->post_status );
		self::assertSame( 'Wartung für Ihr Haus', $post->post_title );
		self::assertSame( $author, (int) $post->post_author, 'the author stays' );
		self::assertSame( 'Wartung | Muster', SeoService::current()->read( $original )['title'] );

		$history = ( new TextHistory() )->all( $original );
		self::assertSame( TextHistory::REPLACED, $history[0]['type'] );
		self::assertSame( 7, $history[0]['text'] );
		$before = get_post( $history[0]['wp_revision'] );
		self::assertNotNull( $before );
		self::assertStringContainsString( 'Der alte Text.', $before->post_content, 'the content before is a WordPress revision' );
	}

	public function test_a_published_original_stays_and_gets_a_revision_draft(): void {
		$this->connect();
		$original = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Wartung',
				'post_content' => '<p>Live.</p>',
			)
		);
		self::assertIsInt( $original );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		self::assertIsInt( $editor );
		wp_set_current_user( $editor );

		$saved = ( new Drafts() )->save( self::rewrite( $original, 'page' ), array() );

		self::assertIsArray( $saved );
		self::assertSame( 'revision', $saved['mode'] ?? null );
		self::assertNotSame( $original, $saved['post_id'] );
		self::assertSame( '<p>Live.</p>', get_post( $original )->post_content ?? null, 'the published page is untouched' );
		$revision = get_post( $saved['post_id'] );
		self::assertNotNull( $revision );
		self::assertSame( 'draft', $revision->post_status );
		self::assertSame( 'page', $revision->post_type );
		self::assertSame( $editor, (int) $revision->post_author );
		self::assertSame( $original, Drafts::meta_int( $revision->ID, Drafts::REVISES_META ) );
		self::assertSame( TextHistory::REVISION, ( new TextHistory() )->all( $original )[0]['type'] );

		$again = ( new Drafts() )->save( self::rewrite( $original, 'page' ), array() );
		self::assertIsArray( $again );
		self::assertSame( $revision->ID, $again['post_id'], 'the same revision draft' );
		self::assertCount( 1, ( new TextHistory() )->all( $original ) );
	}

	public function test_without_rights_on_the_original_nothing_is_saved(): void {
		$this->connect();
		$original = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		self::assertIsInt( $original );
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		self::assertIsInt( $contributor );
		wp_set_current_user( $contributor );

		$saved = ( new Drafts() )->save( self::rewrite( $original ), array() );

		self::assertInstanceOf( \WP_Error::class, $saved );
		self::assertSame( 'ranksphere_forbidden', $saved->get_error_code() );
	}

	public function test_taking_a_revision_over_opens_the_original_with_it_and_records_the_save(): void {
		$this->connect();
		$original = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Wartung',
				'post_content' => '<p>Live.</p>',
			)
		);
		self::assertIsInt( $original );
		wp_set_current_user( $this->admin );
		$saved = ( new Drafts() )->save( self::rewrite( $original ), array( 'title' => 'Neu | Muster' ) );
		self::assertIsArray( $saved );
		$revision = $saved['post_id'];

		// The box on the revision draft offers it.
		$box = self::text_of( $this->dispatch( self::insights( $revision ) ) );
		self::assertStringContainsString( 'Take over into the original', $box );
		self::assertStringContainsString( ApplyRevision::PARAM . '=' . $revision, $box );

		// The link puts the revision into the original's editor – nothing is saved yet.
		$_GET = array(
			'post'               => (string) $original,
			ApplyRevision::PARAM => (string) $revision,
			'_wpnonce'           => wp_create_nonce( ApplyRevision::PARAM . '_' . $revision ),
		);
		( new ApplyRevision() )->enqueue( 'post.php' );
		$_GET = array();
		self::assertTrue( wp_script_is( 'ranksphere-apply-revision', 'enqueued' ) );
		$script = wp_scripts()->get_data( 'ranksphere-apply-revision', 'before' );
		self::assertIsArray( $script );
		$data = json_decode( (string) preg_replace( '/^var rankSphereApply = |;$/', '', (string) end( $script ) ), true );
		self::assertIsArray( $data );
		self::assertSame( 'Wartung für Ihr Haus', $data['title'] ?? null );
		self::assertSame( $original, $data['original'] ?? null, 'numbers stay numbers' );
		self::assertStringContainsString( '<p>Wir warten Heizungen.</p>', is_string( $data['content'] ?? null ) ? $data['content'] : '' );
		self::assertSame( '<p>Live.</p>', get_post( $original )->post_content ?? null );
		$before = is_numeric( $data['before'] ?? null ) ? (int) $data['before'] : 0;
		self::assertSame( $original, wp_is_post_revision( $before ), 'the live content is kept as revision first' );

		// The author clicks "Update"; the editor tells the server.
		wp_update_post(
			array(
				'ID'           => $original,
				'post_content' => '<p>Neu.</p>',
			)
		);
		$request = new WP_REST_Request( 'POST', '/ranksphere/v1/revisions/applied' );
		$request->set_body_params(
			array(
				'original' => $original,
				'revision' => $revision,
				'before'   => $before,
			)
		);
		self::assertSame( 200, $this->dispatch( $request )->get_status() );

		self::assertSame( 'Neu | Muster', SeoService::current()->read( $original )['title'], 'SEO fields follow' );
		self::assertGreaterThan( 0, Drafts::meta_int( $revision, Drafts::APPLIED_META ) );
		$history = ( new TextHistory() )->all( $original );
		$last    = $history[ count( $history ) - 1 ];
		self::assertSame( TextHistory::APPLIED, $last['type'] );
		self::assertSame( $before, $last['wp_revision'] );

		$original_box = self::text_of( $this->dispatch( self::insights( $original ) ) );
		self::assertStringContainsString( 'Revision taken over', $original_box );
		self::assertStringContainsString( 'revision.php?revision=' . $before, $original_box, 'WordPress compares and restores' );
		self::assertStringContainsString( 'Taken over with the revision: SEO title', $original_box );
		self::assertStringContainsString( 'Taken over into the original', self::text_of( $this->dispatch( self::insights( $revision ) ) ) );
	}

	public function test_a_revision_draft_stays_a_draft_and_one_with_gaps_is_not_taken_over(): void {
		$this->connect();
		$original = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		self::assertIsInt( $original );
		wp_set_current_user( $this->admin );
		$text            = self::rewrite( $original );
		$text['content'] = '<p>Termine ab [[Angabe fehlt: Wochentag]].</p>';
		$saved           = ( new Drafts() )->save( $text, array() );
		self::assertIsArray( $saved );
		$revision = $saved['post_id'];

		wp_update_post(
			array(
				'ID'          => $revision,
				'post_status' => 'publish',
			)
		);
		self::assertSame( 'draft', get_post_status( $revision ), 'published it would be a second page' );

		$box = self::text_of( $this->dispatch( self::insights( $revision ) ) );
		self::assertStringNotContainsString( ApplyRevision::PARAM . '=', $box );
		self::assertStringContainsString( 'Fill in the placeholders', $box );

		$_GET = array(
			'post'               => (string) $original,
			ApplyRevision::PARAM => (string) $revision,
			'_wpnonce'           => wp_create_nonce( ApplyRevision::PARAM . '_' . $revision ),
		);
		( new ApplyRevision() )->enqueue( 'post.php' );
		$_GET = array();
		self::assertFalse( wp_script_is( 'ranksphere-apply-revision', 'enqueued' ) );
	}

	public function test_the_placeholder_lock_never_takes_a_live_page_offline(): void {
		$this->connect();
		wp_set_current_user( $this->admin );
		$live = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Live.</p>',
			)
		);
		self::assertIsInt( $live );
		update_post_meta( $live, Drafts::META, 'text-9' );

		wp_update_post(
			array(
				'ID'           => $live,
				'post_content' => '<p>Ab [[Angabe fehlt: Preis]].</p>',
			)
		);

		self::assertSame( 'publish', get_post_status( $live ) );
	}

	public function test_a_draft_without_revisions_is_not_overwritten(): void {
		register_post_type(
			'rs_norev',
			array(
				'public'   => true,
				'show_ui'  => true,
				'supports' => array( 'title', 'editor' ),
			)
		);
		$this->connect();
		wp_set_current_user( $this->admin );
		$original = self::factory()->post->create(
			array(
				'post_type'    => 'rs_norev',
				'post_status'  => 'draft',
				'post_content' => '<p>Nur hier.</p>',
			)
		);
		self::assertIsInt( $original );

		$saved = ( new Drafts() )->save( self::rewrite( $original, 'rs_norev' ), array() );
		unregister_post_type( 'rs_norev' );

		self::assertIsArray( $saved );
		self::assertSame( 'revision', $saved['mode'] ?? null, 'WordPress could not keep the text before' );
		self::assertSame( '<p>Nur hier.</p>', get_post( $original )->post_content ?? null );
	}

	public function test_a_post_just_opened_in_the_editor_becomes_a_real_draft(): void {
		$this->connect();
		wp_set_current_user( $this->admin );
		$original = self::factory()->post->create( array( 'post_status' => 'auto-draft' ) );
		self::assertIsInt( $original );

		$saved = ( new Drafts() )->save( self::rewrite( $original ), array() );

		self::assertIsArray( $saved );
		self::assertSame( 'original', $saved['mode'] ?? null );
		self::assertSame( 'draft', get_post_status( $original ), 'auto-drafts are deleted after a week' );
	}

	public function test_a_wrong_link_does_nothing(): void {
		$this->connect();
		$original = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$other    = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		self::assertIsInt( $original );
		self::assertIsInt( $other );
		wp_set_current_user( $this->admin );

		$_GET = array(
			'post'               => (string) $original,
			ApplyRevision::PARAM => (string) $other,
			'_wpnonce'           => wp_create_nonce( ApplyRevision::PARAM . '_' . $other ),
		);
		( new ApplyRevision() )->enqueue( 'post.php' );
		$_GET = array();

		self::assertFalse( wp_script_is( 'ranksphere-apply-revision', 'enqueued' ), 'not a revision of this post' );

		$request = new WP_REST_Request( 'POST', '/ranksphere/v1/revisions/applied' );
		$request->set_body_params(
			array(
				'original' => $original,
				'revision' => $other,
			)
		);
		self::assertSame( 403, $this->dispatch( $request )->get_status() );
	}

	/**
	 * A text from RankSphere for an existing post.
	 *
	 * @param int    $revises   The post.
	 * @param string $post_type Its type.
	 *
	 * @return array{ranksphere_id: string, post_type: string, title: string, content: string, revises: int}
	 */
	private static function rewrite( int $revises, string $post_type = 'post' ): array {
		return array(
			'ranksphere_id' => 'text-7',
			'post_type'     => $post_type,
			'title'         => 'Wartung für Ihr Haus',
			'content'       => "<!-- wp:paragraph -->\n<p>Wir warten Heizungen.</p>\n<!-- /wp:paragraph -->",
			'revises'       => $revises,
		);
	}

	/**
	 * The box's request.
	 *
	 * @param int $post The post.
	 *
	 * @return WP_REST_Request
	 * @phpstan-return WP_REST_Request<array<string, mixed>>
	 */
	private static function insights( int $post ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/ranksphere/v1/page-insights' );
		$request->set_query_params( array( 'post_id' => $post ) );

		return $request;
	}

	/**
	 * The box's HTML.
	 *
	 * @param \WP_REST_Response $response The answer.
	 */
	private static function text_of( \WP_REST_Response $response ): string {
		$data = $response->get_data();
		self::assertIsArray( $data );
		self::assertIsString( $data['html'] ?? null );

		return $data['html'];
	}
}
