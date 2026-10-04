<?php
/**
 * RankSphere's data in the admin: overview, dashboard widget, box on the edit screen.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Tests\Integration;

use RankSphere\Admin\DashboardWidget;
use RankSphere\Admin\OverviewPage;
use RankSphere\Content\Drafts;
use RankSphere\Insights\Insights;
use RankSphere\Security\RequestVerifier;
use RankSphere\Security\Signature;
use WP_REST_Request;

/**
 * The server fetches signed, caches per language and project, and the pages escape everything
 * RankSphere sends.
 */
final class InsightsTest extends RankSphereTestCase {

	/**
	 * Requests to RankSphere's /overview and /pages.
	 *
	 * @var list<string>
	 */
	private array $asked = array();

	/**
	 * The WordPress draft of the text in the overview.
	 *
	 * @var int|null
	 */
	private ?int $draft = null;

	/**
	 * Status RankSphere answers with.
	 *
	 * @var int
	 */
	private int $status = 200;

	public function set_up(): void {
		parent::set_up();

		Insights::forget();
		$this->asked  = array();
		$this->status = 200;

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

				if ( ! in_array( $path, array( '/api/wordpress/v1/overview', '/api/wordpress/v1/pages' ), true ) ) {
					return $preempt;
				}

				$this->asked[] = $url;
				$this->assert_signed( $url, $args );

				return array(
					'headers'  => array(),
					'body'     => (string) wp_json_encode( str_ends_with( $path, '/overview' ) ? $this->overview() : self::page() ),
					'response' => array(
						'code'    => $this->status,
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

	public function test_the_overview_is_fetched_signed_and_kept_for_ten_minutes(): void {
		$this->connect();

		$overview = ( new Insights() )->overview();

		self::assertIsArray( $overview );
		self::assertIsArray( $overview['data'] );
		self::assertCount( 1, $this->asked );
		self::assertStringContainsString( 'lang=en_US', $this->asked[0] );

		( new Insights() )->overview();
		self::assertCount( 1, $this->asked, 'cached' );

		Insights::forget();
		( new Insights() )->overview();
		self::assertCount( 2, $this->asked, '"Refresh" asks again' );
	}

	public function test_a_failure_shows_a_message_and_waits_before_asking_again(): void {
		$this->connect();
		$this->status = 503;

		$overview = ( new Insights() )->overview();

		self::assertIsArray( $overview );
		self::assertNull( $overview['data'] );
		self::assertSame( 'ranksphere_http_503', $overview['error'] );
		self::assertStringContainsString( 'not reachable right now', OverviewPage::error_message( $overview['error'] ) );

		( new Insights() )->overview();
		self::assertCount( 1, $this->asked, 'not on every page load' );
	}

	public function test_the_overview_page_shows_everything_escaped_for_people_who_write(): void {
		$this->connect();
		$post = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		self::assertIsInt( $post );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		self::assertIsInt( $editor );
		wp_set_current_user( $editor );

		$html = $this->render_overview( $post );

		self::assertStringContainsString( 'Musterprojekt', $html );
		self::assertStringContainsString( 'Clicks from Google', $html );
		self::assertStringContainsString( '1,234', $html );
		self::assertStringContainsString( '+10 %', $html );
		self::assertStringContainsString( 'Titel &lt;script&gt;', $html, 'escaped' );
		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringContainsString( '2 more steps in RankSphere', $html );
		self::assertStringContainsString( 'Edit draft', $html, 'the WordPress draft of the text' );
		self::assertStringContainsString( 'Kurze Sätze', $html );
		self::assertStringNotContainsString( 'javascript:', $html, 'only https links' );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		self::assertIsInt( $subscriber );
		wp_set_current_user( $subscriber );
		$this->expectException( \WPDieException::class );
		( new OverviewPage() )->render();
	}

	public function test_not_connected_the_page_explains_and_the_widget_stays_away(): void {
		wp_set_current_user( $this->admin );

		ob_start();
		( new OverviewPage() )->render();
		self::assertStringContainsString( 'not connected to RankSphere yet', (string) ob_get_clean() );

		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		set_current_screen( 'dashboard' );
		( new DashboardWidget() )->add();
		self::assertStringNotContainsString( DashboardWidget::ID, (string) wp_json_encode( $GLOBALS['wp_meta_boxes'] ?? array() ) );
		self::assertSame( array(), $this->asked );
	}

	public function test_the_widget_shows_figures_and_the_next_step(): void {
		$this->connect();

		ob_start();
		( new DashboardWidget() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Named in AI answers', $html );
		self::assertStringContainsString( '25 %', $html );
		self::assertStringContainsString( 'Next step', $html );
	}

	public function test_the_box_on_the_edit_screen_loads_the_page_from_ranksphere(): void {
		$this->connect();
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		self::assertIsInt( $post );
		$author = self::factory()->user->create( array( 'role' => 'editor' ) );
		self::assertIsInt( $author );
		wp_set_current_user( $author );
		unset( $GLOBALS['wp_rest_application_password_uuid'] );

		$response = $this->dispatch( self::insights_request( $post ) );

		self::assertSame( 200, $response->get_status() );
		$html = self::text( $response, 'html' );
		self::assertStringContainsString( 'wartung &lt;b&gt;', $html, 'escaped' );
		self::assertStringContainsString( 'Pos. 4.2', $html );
		self::assertStringContainsString( 'Title too long', $html );
		self::assertStringContainsString( 'Backlinks point to 2 dead addresses', $html );
		self::assertCount( 1, $this->asked );
		self::assertStringContainsString( 'url=' . rawurlencode( (string) get_permalink( $post ) ), $this->asked[0] );

		// A contributor may not edit someone else's post – and gets nothing.
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		self::assertIsInt( $contributor );
		wp_set_current_user( $contributor );
		self::assertSame( 403, $this->dispatch( self::insights_request( $post ) )->get_status() );
	}

	public function test_a_draft_from_ranksphere_links_to_its_text(): void {
		$this->connect();
		$post = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		self::assertIsInt( $post );
		update_post_meta( $post, Drafts::META, 'text-7' );
		wp_set_current_user( $this->admin );

		$html = self::text( $this->dispatch( self::insights_request( $post ) ), 'html' );

		self::assertStringContainsString( 'https://ranksphere.test/projects/muster/content?draft=7', $html );
		self::assertSame( array(), $this->asked, 'no figures for a draft' );
	}

	/**
	 * Renders the overview page with a text whose WordPress draft exists.
	 *
	 * @param int $post The draft.
	 */
	private function render_overview( int $post ): string {
		$this->draft = $post;

		ob_start();
		( new OverviewPage() )->render();

		return (string) ob_get_clean();
	}

	/**
	 * GET /page-insights as the box' script sends it.
	 *
	 * @param int $post The post.
	 *
	 * @phpstan-return WP_REST_Request<array<string, mixed>>
	 */
	private static function insights_request( int $post ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/ranksphere/v1/page-insights' );
		$request->set_query_params( array( 'post_id' => $post ) );

		return $request;
	}

	/**
	 * The request carries the site token and a valid signature over path and query.
	 *
	 * @param string       $url  Address.
	 * @param array<mixed> $args Request arguments.
	 */
	private function assert_signed( string $url, array $args ): void {
		$headers = $args['headers'];
		self::assertIsArray( $headers );
		self::assertSame( 'Bearer ' . self::TOKEN, $headers['Authorization'] );
		$timestamp = $headers[ RequestVerifier::HEADER_TIMESTAMP ];
		self::assertIsString( $timestamp );
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		/**
		 * The signed query.
		 *
		 * @var array<string, string> $query
		 */
		self::assertSame(
			( new Signature( self::SECRET ) )->sign( (int) $timestamp, 'GET', Signature::canonical_path( $path, $query ), '' ),
			$headers[ RequestVerifier::HEADER_SIGNATURE ]
		);
	}

	/**
	 * RankSphere's overview (WordPressApiController::overview in RankSphere).
	 *
	 * @return array<string, mixed>
	 */
	private function overview(): array {
		return array(
			'project'       => array(
				'name' => 'Musterprojekt',
				'url'  => 'https://ranksphere.test/projects/muster',
			),
			'period'        => array(
				'from' => '2026-09-04',
				'to'   => '2026-10-01',
				'days' => 28,
			),
			'verdict'       => array(
				'tone'  => 'watch',
				'label' => 'Verbesserbar',
				'text'  => '3 offene Schritte, nichts davon brennt.',
			),
			'search'        => array(
				'clicks'      => 1234,
				'impressions' => 40000,
				'position'    => 8.4,
				'previous'    => array(
					'clicks'      => 1122,
					'impressions' => 41000,
					'position'    => 9.1,
				),
				'url'         => 'https://ranksphere.test/projects/muster/search',
			),
			'ai'            => array(
				'mention_rate'  => 0.25,
				'citation_rate' => 0.1,
				'measured_at'   => '2026-10-01T03:00:00+00:00',
				'url'           => 'https://ranksphere.test/projects/muster/ai',
			),
			'data_problems' => array(),
			'tasks'         => array(
				array(
					'title'  => 'Titel <script>alert(1)</script>',
					'why'    => 'Begründung',
					'area'   => 'Website',
					'impact' => 'high',
					'state'  => 'open',
					'url'    => 'javascript:alert(1)',
				),
			),
			'tasks_total'   => 3,
			'texts'         => array(
				array(
					'title'             => 'Wartung für Ihre Anlage',
					'type'              => 'Leistungsseite',
					'state'             => 'Fertig',
					'open_questions'    => 0,
					'wordpress_post_id' => $this->draft,
					'url'               => 'https://ranksphere.test/projects/muster/content?draft=7',
				),
			),
			'texts_url'     => 'https://ranksphere.test/projects/muster/content',
			'voice'         => array(
				'address'         => 'Sie',
				'voice'           => null,
				'do'              => array( 'Kurze Sätze' ),
				'dont'            => array(),
				'preferred_terms' => array(),
				'taboo_words'     => array( 'Synergie' ),
			),
			'voice_url'     => 'https://ranksphere.test/projects/muster/settings',
		);
	}

	/**
	 * RankSphere's data for one page (WordPressApiController::page in RankSphere).
	 *
	 * @return array<string, mixed>
	 */
	private static function page(): array {
		return array(
			'path'             => '/hello/',
			'search'           => array(
				'clicks'               => 40,
				'previous_clicks'      => 32,
				'impressions'          => 900,
				'previous_impressions' => 850,
				'position'             => 5.1,
				'previous_position'    => 6.0,
			),
			'queries'          => array(
				array(
					'query'       => 'wartung <b>',
					'clicks'      => 12,
					'impressions' => 300,
					'position'    => 4.2,
				),
			),
			'analytics'        => null,
			'website_check'    => array(
				array(
					'code'     => 'title_too_long',
					'severity' => 'warning',
					'title'    => 'Title too long',
					'fix'      => null,
				),
			),
			'broken_backlinks' => 2,
			'url'              => 'https://ranksphere.test/projects/muster/search?tab=pages&search=%2Fhello%2F',
		);
	}
}
