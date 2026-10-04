<?php
/**
 * SEO fields in the active SEO plugin.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Tests\Integration;

use RankSphere\Seo\Adapters\Native;
use RankSphere\Seo\SeoPlugins;
use WP_REST_Request;

/**
 * Runs once per SEO plugin in CI (RANKSPHERE_TEST_SEO_PLUGIN) and without one: RankSphere's
 * changes land where that plugin reads them, partial updates keep the other fields, every change
 * can be undone – and nothing happens without signature and the user's right to edit the post.
 */
final class SeoTest extends RankSphereTestCase {

	/**
	 * A published page.
	 *
	 * @var int
	 */
	private int $page;

	public function set_up(): void {
		parent::set_up();

		$this->ensure_seo_plugin_tables();

		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Leistungen',
				'post_name'   => 'leistungen',
				'post_status' => 'publish',
			)
		);
		self::assertIsInt( $page );
		$this->page = $page;
		$this->connect();
	}

	public function test_title_and_description_land_where_the_seo_plugin_reads_them(): void {
		$response = $this->put(
			array(
				'title'          => 'Leistungen – Muster GmbH',
				'description'    => 'Was wir für Sie tun, kurz erklärt.',
				'focus_keywords' => array( 'leistungen muster' ),
			)
		);

		self::assertSame( 200, $response->get_status() );
		self::text( $response, 'history_id' );
		self::assertSame( 'Leistungen – Muster GmbH', self::value( $response, 'after', 'title' ) );

		self::assertSame( array( 'Leistungen – Muster GmbH', 'Was wir für Sie tun, kurz erklärt.' ), $this->stored() );
		$this->assert_rendered_title_contains( 'Leistungen – Muster GmbH' );

		$read = $this->dispatch( $this->sign( new WP_REST_Request( 'GET', '/ranksphere/v1/posts/' . $this->page . '/seo' ) ) );
		self::assertSame( 200, $read->get_status() );
		self::assertSame( SeoPlugins::adapter()->slug(), self::value( $read, 'seo_plugin', 'slug' ) ?? 'ranksphere' );
		self::assertSame( 'Was wir für Sie tun, kurz erklärt.', self::value( $read, 'seo', 'description' ) );

		if ( in_array( 'focus_keywords', SeoPlugins::adapter()->supports(), true ) ) {
			self::assertSame( array( 'leistungen muster' ), self::value( $read, 'seo', 'focus_keywords' ) );
		}
	}

	public function test_a_partial_update_keeps_the_other_fields_and_null_clears(): void {
		$this->put(
			array(
				'title'       => 'Titel',
				'description' => 'Beschreibung',
			)
		);
		$this->put( array( 'title' => 'Neuer Titel' ) );

		self::assertSame( array( 'Neuer Titel', 'Beschreibung' ), $this->stored() );

		$this->put( array( 'description' => null ) );
		self::assertSame( array( 'Neuer Titel', null ), $this->stored() );
	}

	public function test_noindex_and_canonical(): void {
		$supports = SeoPlugins::adapter()->supports();

		$this->put(
			array(
				'noindex'   => true,
				'canonical' => 'https://example.org/leistungen/',
			)
		);
		$seo = SeoPlugins::adapter()->read( $this->page );
		self::assertTrue( $seo['noindex'] );
		self::assertSame( 'https://example.org/leistungen/', $seo['canonical'] );

		$this->put( array( 'noindex' => null ) );
		self::assertNull( SeoPlugins::adapter()->read( $this->page )['noindex'] );
		self::assertContains( 'noindex', $supports );
	}

	public function test_a_change_can_be_undone_unless_changed_again(): void {
		$this->put( array( 'title' => 'Vorher' ) );
		$change = self::text( $this->put( array( 'title' => 'Nachher' ) ), 'history_id' );

		self::assertSame( 200, $this->undo( $change )->get_status() );
		self::assertSame( 'Vorher', $this->stored()[0] );

		self::assertSame( 409, $this->undo( $change )->get_status(), 'only once' );

		$later = self::text( $this->put( array( 'title' => 'Wieder anders' ) ), 'history_id' );
		$this->put( array( 'title' => 'Von Hand geändert' ) );
		self::assertSame( 409, $this->undo( $later )->get_status(), 'would overwrite a newer change' );
	}

	public function test_an_unchanged_value_is_not_a_change(): void {
		$this->put( array( 'title' => 'Gleich' ) );
		$response = $this->put( array( 'title' => 'Gleich' ) );

		self::assertSame( 200, $response->get_status() );
		self::assertNull( self::value( $response, 'history_id' ) );
	}

	public function test_a_url_leads_to_its_post(): void {
		$request = new WP_REST_Request( 'GET', '/ranksphere/v1/lookup' );
		$request->set_query_params( array( 'url' => (string) get_permalink( $this->page ) ) );
		$response = $this->dispatch( $this->sign( $request ) );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( $this->page, self::value( $response, 'post_id' ) );
		self::assertStringContainsString( 'post=' . $this->page, self::text( $response, 'edit_url' ) );

		$missing = new WP_REST_Request( 'GET', '/ranksphere/v1/lookup' );
		$missing->set_query_params( array( 'url' => home_url( '/gibt-es-nicht/' ) ) );
		self::assertSame( 404, $this->dispatch( $this->sign( $missing ) )->get_status() );
	}

	public function test_without_signature_or_rights_nothing_changes(): void {
		$unsigned = new WP_REST_Request( 'PUT', '/ranksphere/v1/posts/' . $this->page . '/seo' );
		$unsigned->set_header( 'Content-Type', 'application/json' );
		$unsigned->set_body( (string) wp_json_encode( array( 'title' => 'Fremd' ) ) );
		self::assertSame( 401, $this->dispatch( $unsigned )->get_status() );

		// The approving user loses the right to edit others' pages.
		$user = get_userdata( $this->admin );
		self::assertInstanceOf( \WP_User::class, $user );
		$user->set_role( 'author' );
		wp_set_current_user( 0 ); // Otherwise WordPress keeps the cached user with the old role.
		$this->authenticate();
		self::assertSame( 403, $this->put( array( 'title' => 'Fremd' ) )->get_status() );

		self::assertNotSame( 'Fremd', $this->stored()[0] );
	}

	public function test_wrong_types_are_a_bad_request(): void {
		self::assertSame( 400, $this->put( array( 'noindex' => 'yes' ) )->get_status() );
	}

	/**
	 * PUT /posts/{id}/seo, signed.
	 *
	 * @param array<string, mixed> $fields Body.
	 */
	private function put( array $fields ): \WP_REST_Response {
		$request = new WP_REST_Request( 'PUT', '/ranksphere/v1/posts/' . $this->page . '/seo' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $fields ) );

		return $this->dispatch( $this->sign( $request ) );
	}

	/**
	 * POST /posts/{id}/seo/undo, signed.
	 *
	 * @param string $history_id Entry.
	 */
	private function undo( string $history_id ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/ranksphere/v1/posts/' . $this->page . '/seo/undo' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'history_id' => $history_id ) ) );

		return $this->dispatch( $this->sign( $request ) );
	}

	/**
	 * Title and description as the SEO plugin stores them – read past the adapter, from the
	 * storage docs/SEO-PLUGINS.md names.
	 *
	 * @return array{0: ?string, 1: ?string}
	 */
	private function stored(): array {
		$meta = function ( string $key ): ?string {
			$value = get_post_meta( $this->page, $key, true );

			return is_string( $value ) && '' !== $value ? $value : null;
		};

		switch ( SeoPlugins::adapter()->slug() ) {
			case 'wordpress-seo':
				return array( $meta( '_yoast_wpseo_title' ), $meta( '_yoast_wpseo_metadesc' ) );
			case 'seo-by-rank-math':
				return array( $meta( 'rank_math_title' ), $meta( 'rank_math_description' ) );
			case 'wp-seopress':
				return array( $meta( '_seopress_titles_title' ), $meta( '_seopress_titles_desc' ) );
			case 'autodescription':
				return array( $meta( '_genesis_title' ), $meta( '_genesis_description' ) );
			case 'slim-seo':
				$data = get_post_meta( $this->page, 'slim_seo', true );
				$data = is_array( $data ) ? $data : array();

				return array( is_string( $data['title'] ?? null ) ? $data['title'] : null, is_string( $data['description'] ?? null ) ? $data['description'] : null );
			case 'all-in-one-seo-pack':
				$db = $GLOBALS['wpdb'];
				self::assertInstanceOf( \wpdb::class, $db );
				$row = $db->get_row( $db->prepare( 'SELECT title, description FROM %i WHERE post_id = %d', $db->prefix . 'aioseo_posts', $this->page ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- AIOSEO's own table is the point of this check.
				$row = is_array( $row ) ? $row : array();

				return array( is_string( $row['title'] ?? null ) && '' !== $row['title'] ? $row['title'] : null, is_string( $row['description'] ?? null ) && '' !== $row['description'] ? $row['description'] : null );
			default:
				return array( $meta( Native::TITLE ), $meta( Native::DESCRIPTION ) );
		}
	}

	/**
	 * The title the visitor's browser gets. Yoast renders from its indexables, AIOSEO from its own
	 * table – so this proves those were updated, not just the meta. Rank Math, SEOPress and TSF only
	 * hook into the front end once their setup has run (not in the test bootstrap); for them the
	 * storage check above is the proof.
	 *
	 * @param string $title Expected part of the title.
	 */
	private function assert_rendered_title_contains( string $title ): void {
		if ( ! in_array( SeoPlugins::adapter()->slug(), array( 'ranksphere', 'wordpress-seo', 'all-in-one-seo-pack', 'slim-seo' ), true ) ) {
			return;
		}

		// go_to() runs WordPress' main query and with it the `wp` action the SEO plugins hook into.
		$this->go_to( (string) get_permalink( $this->page ) );

		self::assertStringContainsString( $title, html_entity_decode( wp_get_document_title(), ENT_QUOTES ) );
	}

	/**
	 * AIOSEO creates its tables on activation, which the test bootstrap does not run.
	 */
	private function ensure_seo_plugin_tables(): void {
		if ( ! function_exists( 'aioseo' ) ) {
			return;
		}

		$aioseo  = aioseo();
		$updates = is_object( $aioseo ) && isset( $aioseo->updates ) && is_object( $aioseo->updates ) ? $aioseo->updates : null;

		if ( null !== $updates && method_exists( $updates, 'addInitialCustomTablesForV4' ) ) {
			$updates->addInitialCustomTablesForV4();
		}
	}
}
