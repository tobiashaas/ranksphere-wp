<?php
/**
 * Taking a revision from RankSphere over into the published original.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Admin;

use RankSphere\Content\Drafts;
use RankSphere\Content\TextHistory;
use RankSphere\Rest\ConnectionController;
use RankSphere\Seo\SeoService;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/**
 * A text RankSphere wrote for a published post lives in a revision draft (Drafts::REVISES_META).
 * "Take over into the original" opens the original in its editor with the revision's title and
 * text filled in – as unsaved changes. Nothing goes live until the author clicks "Update"
 * themselves. Before that, the original's content is kept as WordPress revision; once saved, the
 * revision's SEO fields follow (with SEO history) and the post's history points to that WordPress
 * revision, where WordPress compares and restores.
 *
 * Block editor and classic editor; page builders with their own editor are not covered.
 */
final class ApplyRevision {

	/** Query argument on the original's edit screen: the revision draft to take over. */
	public const PARAM = 'ranksphere_apply';

	/** Classic editor: hidden fields sent with the post form. */
	private const FIELD = 'ranksphere_applied_from';

	private const BEFORE_FIELD = 'ranksphere_applied_before';

	private const NONCE_FIELD = 'ranksphere_applied_nonce';

	/**
	 * Takes the history.
	 *
	 * @param TextHistory $history What RankSphere did with a post's text.
	 */
	public function __construct( private readonly TextHistory $history = new TextHistory() ) {}

	/**
	 * Hooks the script, the route and the classic editor's save.
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_action( 'save_post', array( $this, 'classic_saved' ), 20, 2 );
	}

	/**
	 * The link on the revision draft: the original's editor with the revision filled in.
	 *
	 * @param int $revision The revision draft.
	 * @param int $original The published post.
	 */
	public static function url( int $revision, int $original ): string {
		return add_query_arg(
			array(
				'post'      => $original,
				'action'    => 'edit',
				self::PARAM => $revision,
				'_wpnonce'  => wp_create_nonce( self::PARAM . '_' . $revision ),
			),
			admin_url( 'post.php' )
		);
	}

	/**
	 * The revision draft of a post that may be taken over: valid link, same original, both editable.
	 *
	 * @param int $revision The revision draft.
	 * @param int $original The original.
	 */
	public static function revision_for( int $revision, int $original ): ?WP_Post {
		$post = get_post( $revision );

		if ( ! $post instanceof WP_Post || 'trash' === $post->post_status || Drafts::meta_int( $revision, Drafts::REVISES_META ) !== $original ) {
			return null;
		}

		return current_user_can( 'edit_post', $revision ) && current_user_can( 'edit_post', $original ) ? $post : null;
	}

	/**
	 * On the original's edit screen with a valid link: keeps its content as revision and hands the
	 * revision's title and text to the script.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue( string $hook_suffix ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- checked right below.
		if ( 'post.php' !== $hook_suffix || ! isset( $_GET[ self::PARAM ], $_GET['post'], $_GET['_wpnonce'] ) || ! is_string( $_GET[ self::PARAM ] ) || ! is_string( $_GET['post'] ) || ! is_string( $_GET['_wpnonce'] ) ) {
			return;
		}

		$revision = absint( $_GET[ self::PARAM ] );
		$original = absint( $_GET['post'] );
		$nonce    = sanitize_key( wp_unslash( $_GET['_wpnonce'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( false === wp_verify_nonce( $nonce, self::PARAM . '_' . $revision ) ) {
			return;
		}

		$post = self::revision_for( $revision, $original );

		if ( null === $post ) {
			return;
		}

		$before = TextHistory::keep_current( $original );

		wp_enqueue_script( 'ranksphere-apply-revision', plugins_url( 'assets/apply-revision.js', \RankSphere\PLUGIN_FILE ), array( 'wp-api-fetch' ), \RankSphere\VERSION, true );
		wp_localize_script(
			'ranksphere-apply-revision',
			'rankSphereApply',
			array(
				'original' => $original,
				'revision' => $revision,
				'before'   => $before,
				'title'    => $post->post_title,
				'content'  => $post->post_content,
				'block'    => use_block_editor_for_post( $original ),
				'nonce'    => wp_create_nonce( self::FIELD ),
				'fields'   => array(
					'revision' => self::FIELD,
					'before'   => self::BEFORE_FIELD,
					'nonce'    => self::NONCE_FIELD,
				),
				'inserted' => __( 'RankSphere\'s revision is filled in. Check it and click "Update" to publish it – until then nothing changes on the website. The current version stays as revision.', 'ranksphere' ),
				'applied'  => __( 'Revision taken over, with its SEO title and description. Reload the page before you save again – the SEO plugin\'s fields may still show the old values.', 'ranksphere' ),
			)
		);
	}

	/**
	 * `POST /ranksphere/v1/revisions/applied` – the block editor saved the original with the revision.
	 */
	public function register_route(): void {
		$id = array(
			'type'     => 'integer',
			'required' => true,
			'minimum'  => 1,
		);

		register_rest_route(
			ConnectionController::NAMESPACE,
			'/revisions/applied',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'applied_route' ),
				'permission_callback' => static fn ( WP_REST_Request $request ): bool => null !== self::revision_for( self::param( $request, 'revision' ), self::param( $request, 'original' ) ),
				'args'                => array(
					'original' => $id,
					'revision' => $id,
					'before'   => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
				),
			)
		);
	}

	/**
	 * The route's answer.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function applied_route( WP_REST_Request $request ): WP_REST_Response {
		$this->applied( self::param( $request, 'original' ), self::param( $request, 'revision' ), self::param( $request, 'before' ) );

		return new WP_REST_Response( array( 'applied' => true ) );
	}

	/**
	 * A numeric parameter of the route (0 without).
	 *
	 * @param WP_REST_Request $request The request.
	 * @param string          $name    The parameter.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	private static function param( WP_REST_Request $request, string $name ): int {
		$value = $request->get_param( $name );

		return is_numeric( $value ) ? absint( $value ) : 0;
	}

	/**
	 * Classic editor: the post form came back with the revision filled in.
	 *
	 * @param int     $post_id The saved post.
	 * @param WP_Post $post    The saved post.
	 */
	public function classic_saved( int $post_id, WP_Post $post ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked right below.
		$revision = isset( $_POST[ self::FIELD ] ) && is_string( $_POST[ self::FIELD ] ) ? absint( $_POST[ self::FIELD ] ) : 0;
		$nonce    = isset( $_POST[ self::NONCE_FIELD ] ) && is_string( $_POST[ self::NONCE_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		$before   = isset( $_POST[ self::BEFORE_FIELD ] ) && is_string( $_POST[ self::BEFORE_FIELD ] ) ? absint( $_POST[ self::BEFORE_FIELD ] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( 0 === $revision || false !== wp_is_post_revision( $post_id ) || defined( 'DOING_AUTOSAVE' ) || 'publish' !== $post->post_status ) {
			return;
		}

		if ( false === wp_verify_nonce( $nonce, self::FIELD ) ) {
			return;
		}

		if ( null !== self::revision_for( $revision, $post_id ) ) {
			$this->applied( $post_id, $revision, $before );
		}
	}

	/**
	 * The original was saved with the revision: SEO fields follow, the revision is marked, the
	 * history points to the content before.
	 *
	 * @param int $original The original.
	 * @param int $revision The revision draft.
	 * @param int $before   The WordPress revision with the original's content before.
	 */
	public function applied( int $original, int $revision, int $before ): void {
		if ( $before > 0 && wp_is_post_revision( $before ) !== $original ) {
			$before = 0;
		}

		$seo     = SeoService::current();
		$changes = array_filter(
			$seo->read( $revision ),
			static fn ( mixed $value ): bool => null !== $value && '' !== $value && array() !== $value
		);

		if ( array() !== $changes ) {
			try {
				// Only what differs is written and recorded (SEO history, undoable).
				$seo->update( $original, $changes, 'revision' );
			} catch ( \RuntimeException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- the text is taken over; fields the SEO plugin refused stay on the revision.
			}
		}

		update_post_meta( $revision, Drafts::APPLIED_META, time() );
		$text = get_post_meta( $revision, Drafts::META, true );
		$this->history->record( $original, TextHistory::APPLIED, is_string( $text ) ? Drafts::text_id( $text ) : 0, $revision, $before );
	}
}
