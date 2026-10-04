<?php
/**
 * Drafts from RankSphere.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Tests\Integration;

use RankSphere\Content\Drafts;
use RankSphere\Content\PlaceholderLock;
use RankSphere\Seo\SeoPlugins;
use WP_REST_Request;

/**
 * Always a draft, updated in place while it is one, never touched once published; open
 * placeholders keep it from being published at all.
 */
final class DraftsTest extends RankSphereTestCase {

	private const CONTENT = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Unsere Leistungen</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Wir beraten Sie vor Ort.</p>\n<!-- /wp:paragraph -->";

	public function set_up(): void {
		parent::set_up();
		$this->connect();
	}

	public function test_a_text_arrives_as_draft_with_its_seo_fields(): void {
		$response = $this->send( array( 'status' => 'publish' ) );

		self::assertSame( 201, $response->get_status() );
		$post_id = self::value( $response, 'post_id' );
		self::assertIsInt( $post_id );

		$post = get_post( $post_id );
		self::assertInstanceOf( \WP_Post::class, $post );
		self::assertSame( 'draft', $post->post_status, 'never published, whatever is sent' );
		self::assertSame( 'page', $post->post_type );
		self::assertSame( 'Leistungen', $post->post_title );
		self::assertStringContainsString( '<!-- wp:heading -->', $post->post_content );
		self::assertSame( $this->admin, (int) $post->post_author );
		self::assertSame( 'Leistungen | Muster', SeoPlugins::adapter()->read( $post_id )['title'] );
		self::assertStringContainsString( 'post=' . $post_id, self::text( $response, 'edit_url' ) );
	}

	public function test_the_same_text_updates_its_draft(): void {
		$first  = self::value( $this->send(), 'post_id' );
		$second = $this->send( array( 'title' => 'Leistungen 2' ) );

		self::assertSame( 200, $second->get_status() );
		self::assertSame( $first, self::value( $second, 'post_id' ) );
		self::assertFalse( self::value( $second, 'created' ) );
		self::assertIsInt( $first );
		self::assertSame( 'Leistungen 2', get_the_title( $first ) );
	}

	public function test_a_published_text_is_left_alone(): void {
		$post_id = self::value( $this->send( array( 'content' => '<p>Fertig.</p>' ) ), 'post_id' );
		self::assertIsInt( $post_id );
		wp_publish_post( $post_id );

		$response = $this->send( array( 'title' => 'Überschrieben?' ) );

		self::assertSame( 409, $response->get_status() );
		self::assertSame( 'Leistungen', get_the_title( $post_id ) );
	}

	public function test_scripts_are_filtered_out(): void {
		$post_id = self::value( $this->send( array( 'content' => '<p>Hallo</p><script>alert(1)</script>' ) ), 'post_id' );
		self::assertIsInt( $post_id );

		self::assertStringNotContainsString( '<script', (string) get_post_field( 'post_content', $post_id ) );
	}

	public function test_open_placeholders_keep_it_a_draft(): void {
		$post_id = self::value( $this->send( array( 'content' => '<p>Geöffnet [[Angabe fehlt: Öffnungszeiten]].</p>' ) ), 'post_id' );
		self::assertIsInt( $post_id );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);
		self::assertSame( 'draft', get_post_status( $post_id ) );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '<p>Geöffnet Mo–Fr 8–17 Uhr.</p>',
				'post_status'  => 'publish',
			)
		);
		self::assertSame( 'publish', get_post_status( $post_id ), 'filled in: publishing works' );
	}

	public function test_placeholders_in_other_posts_are_none_of_our_business(): void {
		$post_id = self::factory()->post->create( array( 'post_content' => '[[wiki-link]]' ) );
		self::assertIsInt( $post_id );

		self::assertSame( 'publish', get_post_status( $post_id ) );
		self::assertTrue( PlaceholderLock::has_placeholders( '[[Angabe fehlt: Preis]]' ) );
	}

	public function test_only_post_types_the_user_may_create(): void {
		self::assertSame( 400, $this->send( array( 'post_type' => 'attachment' ) )->get_status() );
		self::assertSame( 400, $this->send( array( 'post_type' => 'gibt-es-nicht' ) )->get_status() );
		self::assertNull( ( new Drafts() )->find( 'text-42' ) );
	}

	/**
	 * POST /drafts, signed.
	 *
	 * @param array<string, mixed> $overrides Changed fields.
	 */
	private function send( array $overrides = array() ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/ranksphere/v1/drafts' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				$overrides + array(
					'ranksphere_id' => 'text-42',
					'post_type'     => 'page',
					'title'         => 'Leistungen',
					'slug'          => 'leistungen-neu',
					'content'       => self::CONTENT,
					'excerpt'       => 'Kurz gesagt.',
					'seo'           => array(
						'title'       => 'Leistungen | Muster',
						'description' => 'Was wir tun.',
					),
				)
			)
		);

		return $this->dispatch( $this->sign( $request ) );
	}
}
