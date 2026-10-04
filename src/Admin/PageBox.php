<?php
/**
 * The RankSphere box on the edit screen.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Admin;

use RankSphere\Connection\ConnectionStore;
use RankSphere\Content\Drafts;
use RankSphere\Insights\Insights;
use RankSphere\Insights\Value;
use RankSphere\Rest\ConnectionController;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/**
 * What RankSphere knows about the page being edited: Google figures and searches, findings of the
 * website check, dead backlinks. A classic meta box – it shows in the block editor and the classic
 * editor alike, also for custom post types. The data loads after the editor (a small script asks
 * `ranksphere/v1/page-insights`, which the server answers from RankSphere's cached data), so
 * opening a post never waits for RankSphere.
 */
final class PageBox {

	public const ID = 'ranksphere-page';

	/** Searches shown in the box. */
	private const QUERIES = 5;

	/**
	 * Takes the connection store.
	 *
	 * @param ConnectionStore $store Where the connection lives.
	 */
	public function __construct( private readonly ConnectionStore $store = new ConnectionStore() ) {}

	/**
	 * Hooks the box, its script and its route.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/**
	 * Adds the box to public post types while the site is connected.
	 *
	 * @param string $post_type The post type being edited.
	 * @param mixed  $post      The post (WP_Post on the edit screen).
	 */
	public function add( string $post_type, mixed $post ): void {
		if ( ! $post instanceof WP_Post || null === $this->store->get() || ! self::supports( $post_type ) ) {
			return;
		}

		add_meta_box( self::ID, __( 'RankSphere', 'ranksphere' ), array( $this, 'render' ), $post_type, 'side', 'low' );
	}

	/**
	 * The placeholder the script fills.
	 *
	 * @param WP_Post $post The post.
	 */
	public function render( WP_Post $post ): void {
		printf(
			'<div class="ranksphere ranksphere-page-box" data-ranksphere-page="%1$d" data-error="%2$s"><p class="ranksphere-muted">%3$s</p></div>',
			(int) $post->ID,
			esc_attr__( 'RankSphere could not be reached.', 'ranksphere' ),
			esc_html__( 'Loading data from RankSphere …', 'ranksphere' )
		);
	}

	/**
	 * The script on the edit screen.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) || null === $this->store->get() ) {
			return;
		}

		wp_enqueue_script( 'ranksphere-page-box', plugins_url( 'assets/page-box.js', \RankSphere\PLUGIN_FILE ), array( 'wp-api-fetch' ), \RankSphere\VERSION, true );
		wp_enqueue_style( 'ranksphere-admin', plugins_url( 'assets/admin.css', \RankSphere\PLUGIN_FILE ), array(), \RankSphere\VERSION );
	}

	/**
	 * `GET /ranksphere/v1/page-insights?post_id=…` – for logged-in editors (cookie + REST nonce),
	 * not for RankSphere.
	 */
	public function register_route(): void {
		register_rest_route(
			ConnectionController::NAMESPACE,
			'/page-insights',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'insights' ),
				'permission_callback' => static fn ( WP_REST_Request $request ): bool => current_user_can( 'edit_post', self::post_id( $request ) ),
				'args'                => array(
					'post_id' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
				),
			)
		);
	}

	/**
	 * The box's content as HTML (escaped here, inserted by the script).
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function insights( WP_REST_Request $request ): WP_REST_Response {
		$post = get_post( self::post_id( $request ) );

		return new WP_REST_Response( array( 'html' => $post instanceof WP_Post ? $this->html( $post ) : '' ) );
	}

	/**
	 * The requested post.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	private static function post_id( WP_REST_Request $request ): int {
		$id = $request->get_param( 'post_id' );

		return is_numeric( $id ) ? (int) $id : 0;
	}

	/**
	 * Whether a post type gets the box: public ones with an address of their own.
	 *
	 * @param string $post_type The post type.
	 */
	public static function supports( string $post_type ): bool {
		$type = get_post_type_object( $post_type );

		return null !== $type && $type->public && 'attachment' !== $post_type;
	}

	/**
	 * The content for one post.
	 *
	 * @param WP_Post $post The post.
	 */
	private function html( WP_Post $post ): string {
		$connection = $this->store->get();

		if ( null === $connection ) {
			return '<p>' . esc_html__( 'This site is not connected to RankSphere.', 'ranksphere' ) . '</p>';
		}

		$text = self::ranksphere_text( $post, $connection->project_url );

		if ( 'publish' !== $post->post_status ) {
			return ( '' !== $text
				? '<p>' . esc_html__( 'This draft was written in RankSphere.', 'ranksphere' ) . '</p>' . self::link( $text, __( 'Text and review in RankSphere', 'ranksphere' ) )
				: '' )
				. '<p class="ranksphere-muted">' . esc_html__( 'Google figures appear once the page is published and found.', 'ranksphere' ) . '</p>';
		}

		$permalink = get_permalink( $post );
		$entry     = is_string( $permalink ) ? ( new Insights( $this->store ) )->page( $permalink ) : null;

		if ( null === $entry || null === $entry['data'] ) {
			return '<p>' . esc_html( OverviewPage::error_message( $entry['error'] ?? null ) ) . '</p>';
		}

		return $this->figures( $entry['data'] ) . ( '' !== $text ? self::link( $text, __( 'Text and review in RankSphere', 'ranksphere' ) ) : '' );
	}

	/**
	 * Figures, searches and findings of one page.
	 *
	 * @param array<mixed> $data RankSphere's answer for the page.
	 */
	private function figures( array $data ): string {
		$search   = Value::map( $data, 'search' );
		$queries  = array_slice( Value::maps( $data, 'queries' ), 0, self::QUERIES );
		$findings = Value::maps( $data, 'website_check' );
		$broken   = (int) ( Value::number( $data, 'broken_backlinks' ) ?? 0 );
		$html     = '';

		if ( null === $search ) {
			$html .= '<p>' . esc_html__( 'No Google data for this page in the last 28 days.', 'ranksphere' ) . '</p>';
		} else {
			$clicks   = Value::number( $search, 'clicks' ) ?? 0.0;
			$change   = Value::change( $clicks, Value::number( $search, 'previous_clicks' ) );
			$position = Value::number( $search, 'position' );
			$rows     = array(
				__( 'Clicks', 'ranksphere' )           => Value::count( $clicks ) . ( '' !== $change ? ' (' . $change . ')' : '' ),
				__( 'Impressions', 'ranksphere' )      => Value::count( Value::number( $search, 'impressions' ) ?? 0.0 ),
				__( 'Average position', 'ranksphere' ) => null === $position ? '–' : number_format_i18n( $position, 1 ),
			);
			$html    .= '<p class="ranksphere-muted">' . esc_html__( 'Google, last 28 days', 'ranksphere' ) . '</p><table class="ranksphere-facts">';

			foreach ( $rows as $label => $value ) {
				$html .= '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
			}

			$html .= '</table>';
		}

		if ( array() !== $queries ) {
			$html .= '<h4>' . esc_html__( 'Found with', 'ranksphere' ) . '</h4><ul class="ranksphere-queries">';

			foreach ( $queries as $query ) {
				$position = Value::number( $query, 'position' );
				$html    .= '<li><span>' . esc_html( Value::text( $query, 'query' ) ) . '</span>'
					. ( null === $position ? '' : ' <span class="ranksphere-muted">' . esc_html(
						/* translators: %s: average position, e.g. 4.2. */
						sprintf( __( 'Pos. %s', 'ranksphere' ), number_format_i18n( $position, 1 ) )
					) . '</span>' )
					. '</li>';
			}

			$html .= '</ul>';
		}

		if ( array() !== $findings ) {
			$html .= '<h4>' . esc_html__( 'Website check', 'ranksphere' ) . '</h4><ul class="ranksphere-findings">';

			foreach ( $findings as $finding ) {
				$html .= '<li class="ranksphere-severity-' . esc_attr( sanitize_key( Value::text( $finding, 'severity' ) ) ) . '">' . esc_html( Value::text( $finding, 'title' ) ) . '</li>';
			}

			$html .= '</ul>';
		}

		if ( $broken > 0 ) {
			$html .= '<p>' . esc_html(
				/* translators: %d: number of dead addresses with backlinks. */
				sprintf( _n( 'Backlinks point to %d dead address of this page.', 'Backlinks point to %d dead addresses of this page.', $broken, 'ranksphere' ), $broken )
			) . '</p>';
		}

		return $html . self::link( Value::link( $data, 'url' ), __( 'This page in RankSphere', 'ranksphere' ) );
	}

	/**
	 * The text in RankSphere behind a draft sent from there; '' for other posts.
	 *
	 * @param WP_Post $post        The post.
	 * @param string  $project_url The project in RankSphere.
	 */
	private static function ranksphere_text( WP_Post $post, string $project_url ): string {
		$id = get_post_meta( $post->ID, Drafts::META, true );

		if ( ! is_string( $id ) || 1 !== preg_match( '/^text-(\d+)$/', $id, $match ) || '' === $project_url ) {
			return '';
		}

		return add_query_arg( 'draft', $match[1], untrailingslashit( $project_url ) . '/content' );
	}

	/**
	 * A link into RankSphere as HTML.
	 *
	 * @param string $url   The address.
	 * @param string $label The link text.
	 */
	private static function link( string $url, string $label ): string {
		if ( '' === $url ) {
			return '';
		}

		return sprintf(
			'<p><a href="%1$s" target="_blank" rel="noopener">%2$s<span class="screen-reader-text"> %3$s</span></a></p>',
			esc_url( $url ),
			esc_html( $label ),
			esc_html__( '(opens in a new tab)', 'ranksphere' )
		);
	}
}
