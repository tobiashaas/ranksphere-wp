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
use RankSphere\Content\PlaceholderLock;
use RankSphere\Insights\Insights;
use RankSphere\Insights\Suggestions;
use RankSphere\Insights\Value;
use RankSphere\Rest\ConnectionController;
use RankSphere\Seo\SeoService;
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
			'<div class="ranksphere ranksphere-page-box" data-ranksphere-page="%1$d" data-error="%2$s"><p class="rs-muted">%3$s</p></div>',
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
		$post_id = array(
			'type'     => 'integer',
			'required' => true,
			'minimum'  => 1,
		);
		$can     = static fn ( WP_REST_Request $request ): bool => current_user_can( 'edit_post', self::post_id( $request ) );

		register_rest_route(
			ConnectionController::NAMESPACE,
			'/page-suggestion',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'suggestion' ),
					'permission_callback' => $can,
					'args'                => array( 'post_id' => $post_id ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'suggest' ),
					'permission_callback' => $can,
					'args'                => array( 'post_id' => $post_id ),
				),
			)
		);
		register_rest_route(
			ConnectionController::NAMESPACE,
			'/page-suggestion/apply',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'apply' ),
				'permission_callback' => $can,
				'args'                => array(
					'post_id' => $post_id,
					'field'   => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => Suggestions::FIELDS,
					),
					'value'   => array(
						'type'      => 'string',
						'required'  => true,
						'minLength' => 1,
						'maxLength' => 500,
					),
				),
			)
		);
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
	 * `POST /page-suggestion`: asks RankSphere for a title and description.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function suggest( WP_REST_Request $request ): WP_REST_Response {
		$post = get_post( self::post_id( $request ) );

		if ( ! $post instanceof WP_Post ) {
			return new WP_REST_Response(
				array(
					'status' => 'failed',
					'html'   => '',
				)
			);
		}

		return self::state( $post, ( new Suggestions( $this->store ) )->start( $post ) );
	}

	/**
	 * `GET /page-suggestion`: where the suggestion stands (the script polls while it is pending).
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function suggestion( WP_REST_Request $request ): WP_REST_Response {
		$post = get_post( self::post_id( $request ) );

		if ( ! $post instanceof WP_Post ) {
			return new WP_REST_Response(
				array(
					'status' => 'failed',
					'html'   => '',
				)
			);
		}

		return self::state( $post, ( new Suggestions( $this->store ) )->status( $post ) );
	}

	/**
	 * `POST /page-suggestion/apply`: takes one suggested field over.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function apply( WP_REST_Request $request ): WP_REST_Response {
		$post  = get_post( self::post_id( $request ) );
		$field = $request->get_param( 'field' );
		$value = $request->get_param( 'value' );

		if ( ! $post instanceof WP_Post || ! is_string( $field ) || ! is_string( $value ) ) {
			return new WP_REST_Response(
				array(
					'status' => 'failed',
					'html'   => '',
				),
				400
			);
		}

		$result = ( new Suggestions( $this->store ) )->apply( $post, $field, $value );

		if ( $result instanceof \WP_Error || array() !== $result['unsupported'] ) {
			$html = '<p>' . esc_html__( 'The SEO plugin did not take the change.', 'ranksphere' ) . '</p>';

			return new WP_REST_Response(
				array(
					'status' => 'failed',
					'html'   => $html,
				)
			);
		}

		$html = Ui::note(
			'good',
			( 'title' === $field ? __( 'SEO title saved.', 'ranksphere' ) : __( 'Meta description saved.', 'ranksphere' ) )
			. ' ' . __( 'Reload the page before you save the post – otherwise the SEO plugin\'s fields still hold the old value and saving would bring it back.', 'ranksphere' )
		) . '<p><button type="button" class="rs-button rs-button-small rs-button-outline" data-ranksphere-reload>' . Ui::icon( 'refresh' ) . esc_html__( 'Reload page', 'ranksphere' ) . '</button></p>';

		return new WP_REST_Response(
			array(
				'status' => 'applied',
				'html'   => $html,
			)
		);
	}

	/**
	 * RankSphere's state of the suggestion as status and HTML for the box.
	 *
	 * @param WP_Post                $post  The post.
	 * @param array<mixed>|\WP_Error $state RankSphere's answer.
	 */
	private static function state( WP_Post $post, array|\WP_Error $state ): WP_REST_Response {
		$status = $state instanceof \WP_Error ? 'failed' : Value::text( $state, 'status' );

		$html = match ( true ) {
			$state instanceof \WP_Error => '<p>' . esc_html( OverviewPage::error_message( (string) $state->get_error_code() ) ) . '</p>' . self::suggest_button( __( 'Try again', 'ranksphere' ) ),
			'pending' === $status        => '<p class="rs-verdict rs-muted">' . Ui::icon( 'loader', 'ranksphere-spinner' ) . esc_html__( 'RankSphere is writing a suggestion …', 'ranksphere' ) . '</p>',
			'failed' === $status         => '<p>' . esc_html( '' !== Value::text( $state, 'error' ) ? Value::text( $state, 'error' ) : __( 'The suggestion could not be created.', 'ranksphere' ) ) . '</p>' . self::suggest_button( __( 'Try again', 'ranksphere' ) ),
			'done' === $status           => self::proposal( $post, $state ),
			default                      => self::suggestion_intro(),
		};

		return new WP_REST_Response(
			array(
				'status' => '' !== $status ? $status : 'none',
				'html'   => $html,
			)
		);
	}

	/**
	 * The suggestion next to what the SEO plugin holds now, one "Apply" per field.
	 *
	 * @param WP_Post      $post  The post.
	 * @param array<mixed> $state RankSphere's answer.
	 */
	private static function proposal( WP_Post $post, array $state ): string {
		$current = SeoService::current()->read( $post->ID );
		$labels  = array(
			'title'       => __( 'SEO title', 'ranksphere' ),
			'description' => __( 'Meta description', 'ranksphere' ),
		);
		$html    = '' !== Value::text( $state, 'why' ) ? Ui::verdict( 'neutral', Value::text( $state, 'why' ) ) : '';

		foreach ( $labels as $field => $label ) {
			$value = Value::text( $state, $field );

			if ( '' === $value ) {
				continue;
			}

			$now   = is_string( $current[ $field ] ) && '' !== $current[ $field ] ? $current[ $field ] : __( 'Default of the SEO plugin', 'ranksphere' );
			$html .= '<div class="ranksphere-proposal"><h4>' . esc_html( $label ) . '</h4>'
				. '<p class="ranksphere-before"><span class="screen-reader-text">' . esc_html__( 'Now:', 'ranksphere' ) . ' </span>' . esc_html( $now ) . '</p>'
				. '<p class="ranksphere-after"><span class="screen-reader-text">' . esc_html__( 'Suggestion:', 'ranksphere' ) . ' </span>' . esc_html( $value ) . '</p>'
				. '<p class="ranksphere-proposal-actions"><span class="rs-muted">' . esc_html(
					/* translators: %d: number of characters. */
					sprintf( _n( '%d character', '%d characters', mb_strlen( $value ), 'ranksphere' ), mb_strlen( $value ) )
				) . '</span> <button type="button" class="rs-button rs-button-small" data-ranksphere-apply="' . esc_attr( $field ) . '" data-value="' . esc_attr( $value ) . '">' . esc_html__( 'Apply', 'ranksphere' ) . '</button></p></div>';
		}

		return $html . self::suggest_button( __( 'New suggestion', 'ranksphere' ), 'rs-button-link' );
	}

	/**
	 * Before any suggestion: what it does, and the button.
	 */
	private static function suggestion_intro(): string {
		return '<p class="rs-muted">' . esc_html__( 'RankSphere suggests both from the searches this page is found with, its text and your company\'s voice. Nothing changes until you apply it.', 'ranksphere' ) . '</p>'
			. self::suggest_button( __( 'Create suggestion', 'ranksphere' ) );
	}

	/**
	 * The button that asks RankSphere.
	 *
	 * @param string $label   Button text.
	 * @param string $classes CSS classes.
	 */
	private static function suggest_button( string $label, string $classes = 'rs-button rs-button-small' ): string {
		return '<p><button type="button" class="' . esc_attr( $classes ) . '" data-ranksphere-suggest>' . ( 'rs-button-link' === $classes ? '' : Ui::icon( 'sparkles' ) ) . esc_html( $label ) . '</button></p>';
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

		$text     = self::ranksphere_text( $post, $connection->project_url );
		$revision = self::revision( $post );
		$history  = PostHistory::html( $post );

		if ( '' !== $revision ) {
			return $revision . ( '' !== $text ? self::link( $text, __( 'Text, questions and review', 'ranksphere' ) ) : '' ) . $history;
		}

		if ( 'publish' !== $post->post_status ) {
			return ( '' !== $text
				? '<p>' . esc_html__( 'This draft was written in RankSphere.', 'ranksphere' ) . '</p>' . self::link( $text, __( 'Text, questions and review', 'ranksphere' ) )
				: '' )
				. Ui::note( 'neutral', __( 'Google figures appear once the page is published and found.', 'ranksphere' ) )
				. self::rewrite( $post ) . $history;
		}

		$permalink = get_permalink( $post );
		$entry     = is_string( $permalink ) ? ( new Insights( $this->store ) )->page( $permalink ) : null;

		if ( null === $entry || null === $entry['data'] ) {
			return '<p>' . esc_html( OverviewPage::error_message( $entry['error'] ?? null ) ) . '</p>' . self::rewrite( $post ) . $history;
		}

		return $this->figures( $entry['data'] )
			. '<h4>' . esc_html__( 'Title and description', 'ranksphere' ) . '</h4><div data-ranksphere-suggestion>' . self::suggestion_intro() . '</div>'
			. self::rewrite( $post )
			. ( '' !== $text ? self::link( $text, __( 'Text, questions and review', 'ranksphere' ) ) : '' )
			. $history;
	}

	/**
	 * "Rewrite with RankSphere": the page "Texte" with this post chosen.
	 *
	 * @param WP_Post $post The post.
	 */
	private static function rewrite( WP_Post $post ): string {
		if ( ! current_user_can( TextsPage::CAPABILITY ) || ! array_key_exists( $post->post_type, \RankSphere\Content\Texts::post_types() ) ) {
			return '';
		}

		return '<h4>' . esc_html__( 'Text', 'ranksphere' ) . '</h4><p class="rs-muted">' . esc_html__( 'RankSphere rewrites this page from its text, the searches it is found with and your voice – as a draft; the page stays as it is until you take it over.', 'ranksphere' ) . '</p>'
			. '<p><a class="rs-button rs-button-small rs-button-outline" href="' . esc_url( TextsPage::url_for_post( $post->ID ) ) . '">' . Ui::icon( 'sparkles' ) . esc_html__( 'Rewrite with RankSphere', 'ranksphere' ) . '</a></p>';
	}

	/**
	 * On a revision draft: which post it revises and "Take over into the original".
	 *
	 * @param WP_Post $post The post being edited.
	 */
	private static function revision( WP_Post $post ): string {
		$original_id = Drafts::meta_int( $post->ID, Drafts::REVISES_META );
		$original    = $original_id > 0 ? get_post( $original_id ) : null;

		if ( null === $original ) {
			return '';
		}

		$title   = '' !== get_the_title( $original ) ? get_the_title( $original ) : __( '(no title)', 'ranksphere' );
		$link    = current_user_can( 'edit_post', $original->ID ) ? '<a href="' . esc_url( (string) get_edit_post_link( $original->ID ) ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title );
		$applied = Drafts::meta_int( $post->ID, Drafts::APPLIED_META );
		$html    = '<h4>' . esc_html__( 'Revision', 'ranksphere' ) . '</h4><p>' . sprintf(
			/* translators: %s: title of the original post (link). */
			esc_html__( 'Revision of %s. The original stays as it is until you take this over.', 'ranksphere' ),
			$link
		) . '</p>';

		if ( $applied > 0 ) {
			$format = get_option( 'date_format' );

			return $html . Ui::note(
				'good',
				sprintf(
					/* translators: %s: date. */
					__( 'Taken over into the original on %s.', 'ranksphere' ),
					(string) wp_date( is_string( $format ) && '' !== $format ? $format : 'Y-m-d', $applied )
				)
			);
		}

		if ( null === ApplyRevision::revision_for( $post->ID, $original->ID ) ) {
			return $html;
		}

		// A text with gaps would go live with them: fill them in here first.
		if ( PlaceholderLock::has_placeholders( $post->post_content ) ) {
			return $html . Ui::note( 'watch', __( 'Fill in the placeholders [[…]] first – then you can take this text over into the original.', 'ranksphere' ) );
		}

		return $html . '<p class="rs-muted">' . esc_html__( 'Save your changes here first. Then this opens the original with this text filled in – nothing changes until you save it there; the current version stays as revision.', 'ranksphere' ) . '</p>'
			. '<p><a class="rs-button rs-button-small" href="' . esc_url( ApplyRevision::url( $post->ID, $original->ID ) ) . '">' . Ui::icon( 'send' ) . esc_html__( 'Take over into the original', 'ranksphere' ) . '</a></p>';
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
			$clicks      = Value::number( $search, 'clicks' );
			$impressions = Value::number( $search, 'impressions' );
			$position    = Value::number( $search, 'position' );
			$html       .= '<p class="rs-muted">' . esc_html__( 'Google, last 28 days', 'ranksphere' ) . '</p><div class="rs-mini-figures">'
				. self::mini( __( 'Clicks', 'ranksphere' ), 'pointer', null === $clicks ? '–' : Value::count( $clicks ), Ui::delta( $clicks, Value::number( $search, 'previous_clicks' ) ) )
				. self::mini( __( 'Impressions', 'ranksphere' ), 'eye', null === $impressions ? '–' : Value::count( $impressions ), Ui::delta( $impressions, Value::number( $search, 'previous_impressions' ) ) )
				. self::mini( __( 'Average position', 'ranksphere' ), 'target', null === $position ? '–' : number_format_i18n( $position, 1 ), Ui::delta( $position, Value::number( $search, 'previous_position' ), true, true ) )
				. '</div>';
		}

		if ( array() !== $queries ) {
			$html .= '<h4>' . esc_html__( 'Found with', 'ranksphere' ) . '</h4><ul class="ranksphere-queries">';

			foreach ( $queries as $query ) {
				$position = Value::number( $query, 'position' );
				$html    .= '<li><span>' . esc_html( Value::text( $query, 'query' ) ) . '</span>'
					. ( null === $position ? '' : ' <span class="rs-muted">' . esc_html(
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
				$severity = Value::text( $finding, 'severity' );
				$html    .= '<li>' . Ui::icon( 'error' === $severity ? 'triangle-alert' : ( 'warning' === $severity ? 'circle-alert' : 'circle-dot' ), 'error' === $severity ? 'rs-tone-act' : ( 'warning' === $severity ? 'rs-tone-watch' : 'rs-tone-neutral' ) ) . '<span>' . esc_html( Value::text( $finding, 'title' ) ) . '</span></li>';
			}

			$html .= '</ul>';
		}

		if ( $broken > 0 ) {
			$html .= Ui::note(
				'watch',
				/* translators: %d: number of dead addresses with backlinks. */
				sprintf( _n( 'Backlinks point to %d dead address of this page.', 'Backlinks point to %d dead addresses of this page.', $broken, 'ranksphere' ), $broken )
			);
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

		return TextsPage::url( (int) $match[1] );
	}

	/**
	 * A link in its own line (into RankSphere: new tab).
	 *
	 * @param string $url   The address.
	 * @param string $label The link text.
	 */
	private static function link( string $url, string $label ): string {
		return '' === $url ? '' : '<p>' . Ui::link( $url, $label ) . '</p>';
	}

	/**
	 * One small figure.
	 *
	 * @param string $label Its name.
	 * @param string $icon  Icon key.
	 * @param string $value Formatted value.
	 * @param string $delta The change (HTML).
	 */
	private static function mini( string $label, string $icon, string $value, string $delta ): string {
		return '<div class="rs-mini"><span class="rs-mini-label">' . Ui::icon( $icon ) . esc_html( $label ) . '</span><span class="rs-figure">' . esc_html( $value ) . '</span>' . $delta . '</div>';
	}
}
