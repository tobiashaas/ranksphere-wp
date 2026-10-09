<?php
/**
 * RankSphere → Texts in the admin.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Admin;

use RankSphere\Connection\ConnectionStore;
use RankSphere\Content\Texts;
use RankSphere\Insights\Value;
use WP_Error;

/**
 * Texts written by RankSphere, started and finished in WordPress: start one (type, topic, facts),
 * follow it while RankSphere writes and reviews, answer the open questions, save it as draft – the
 * current user becomes the author, nothing is published. Forms go through admin-post.php with nonce
 * and capability; RankSphere's answers are read typed and printed escaped.
 */
final class TextsPage {

	public const SLUG = 'ranksphere-texts';

	/** Everyone who writes. */
	public const CAPABILITY = 'edit_posts';

	public const START_ACTION = 'ranksphere_text_start';

	public const ANSWER_ACTION = 'ranksphere_text_answer';

	public const DRAFT_ACTION = 'ranksphere_text_draft';

	public const NOTE_ACTION = 'ranksphere_text_note';

	public const RESTORE_ACTION = 'ranksphere_text_restore';

	/** Posts offered in the "existing post" list. */
	private const POSTS = 30;

	/** Transient prefix for the note after a form (per user). */
	private const NOTICE = 'ranksphere_notice_';

	/**
	 * Texts from RankSphere.
	 *
	 * @var Texts
	 */
	private readonly Texts $texts;

	/**
	 * Takes the connection store and the texts.
	 *
	 * @param ConnectionStore $store Where the connection lives.
	 * @param Texts|null      $texts The texts (null: from the store).
	 */
	public function __construct( private readonly ConnectionStore $store = new ConnectionStore(), ?Texts $texts = null ) {
		$this->texts = $texts ?? new Texts( $this->store );
	}

	/**
	 * Hooks the menu entry, the form handlers and the script.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::START_ACTION, array( $this, 'handle_start' ) );
		add_action( 'admin_post_' . self::ANSWER_ACTION, array( $this, 'handle_answer' ) );
		add_action( 'admin_post_' . self::DRAFT_ACTION, array( $this, 'handle_draft' ) );
		add_action( 'admin_post_' . self::NOTE_ACTION, array( $this, 'handle_note' ) );
		add_action( 'admin_post_' . self::RESTORE_ACTION, array( $this, 'handle_restore' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/**
	 * `GET /ranksphere/v1/editable-posts?post_type=…&search=…` – the posts of a type the current
	 * user may edit, for "rewrite an existing post" (logged-in users, cookie + REST nonce).
	 */
	public function register_route(): void {
		register_rest_route(
			\RankSphere\Rest\ConnectionController::NAMESPACE,
			'/editable-posts',
			array(
				'methods'             => 'GET',
				'callback'            => static fn ( \WP_REST_Request $request ): \WP_REST_Response => new \WP_REST_Response(
					array(
						'posts' => self::editable_posts(
							is_string( $request->get_param( 'post_type' ) ) ? $request->get_param( 'post_type' ) : '',
							is_string( $request->get_param( 'search' ) ) ? $request->get_param( 'search' ) : ''
						),
					)
				),
				'permission_callback' => static fn (): bool => current_user_can( self::CAPABILITY ),
				'args'                => array(
					'post_type' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[a-z0-9_-]{1,20}$',
					),
					'search'    => array(
						'type'      => 'string',
						'maxLength' => 100,
					),
				),
			)
		);
	}

	/**
	 * Posts of a type the current user may edit, newest change first.
	 *
	 * @param string $post_type The post type (one the user may write, Texts::post_types()).
	 * @param string $search    Words in the title.
	 *
	 * @return list<array{id: int, title: string}>
	 */
	public static function editable_posts( string $post_type, string $search = '' ): array {
		if ( ! array_key_exists( $post_type, Texts::post_types() ) ) {
			return array();
		}

		$type = get_post_type_object( $post_type );
		$args = array(
			'post_type'              => $post_type,
			'post_status'            => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'perm'                   => 'editable',
			'posts_per_page'         => self::POSTS,
			'orderby'                => 'modified',
			's'                      => sanitize_text_field( $search ),
			'search_columns'         => array( 'post_title' ),
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		// Authors and contributors see their own posts – the 30 newest of others would hide them.
		$others = null !== $type && is_string( $type->cap->edit_others_posts ?? null ) ? $type->cap->edit_others_posts : 'edit_others_posts';

		if ( ! current_user_can( $others ) ) {
			$args['author'] = get_current_user_id();
		}

		$query = new \WP_Query( $args );
		$posts = array();

		foreach ( $query->get_posts() as $post ) {
			if ( $post instanceof \WP_Post && current_user_can( 'edit_post', $post->ID ) ) {
				$posts[] = array(
					'id'    => $post->ID,
					'title' => self::post_label( $post ),
				);
			}
		}

		return $posts;
	}

	/**
	 * A post in the list: title, and the status when it is not published.
	 *
	 * @param \WP_Post $post The post.
	 */
	private static function post_label( \WP_Post $post ): string {
		$title  = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
		$title  = '' !== $title ? $title : __( '(no title)', 'ranksphere' );
		$status = get_post_status_object( $post->post_status );

		return 'publish' === $post->post_status || null === $status || ! is_string( $status->label ) ? $title : $title . ' – ' . $status->label;
	}

	/**
	 * RankSphere → Texts.
	 */
	public function add_menu(): void {
		add_submenu_page( OverviewPage::SLUG, __( 'Texts from RankSphere', 'ranksphere' ), __( 'Texts', 'ranksphere' ), self::CAPABILITY, self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * The page's script (type switch, refresh while RankSphere writes).
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! str_contains( $hook_suffix, self::SLUG ) ) {
			return;
		}

		wp_enqueue_script( 'ranksphere-texts', plugins_url( 'assets/texts.js', \RankSphere\PLUGIN_FILE ), array( 'wp-api-fetch' ), \RankSphere\VERSION, true );
		Ui::script_data(
			'ranksphere-texts',
			'rankSphereTexts',
			array(
				'none'        => __( 'Nothing found.', 'ranksphere' ),
				'topicNew'    => __( 'e.g. a question customers ask', 'ranksphere' ),
				'topicSource' => __( 'Optional – else the title of the post', 'ranksphere' ),
			)
		);
	}

	/**
	 * The page's address, for one text or the list.
	 *
	 * @param int $text RankSphere's id, 0 for the list.
	 */
	public static function url( int $text = 0 ): string {
		$url = admin_url( 'admin.php?page=' . self::SLUG );

		return $text > 0 ? add_query_arg( 'text', $text, $url ) : $url;
	}

	/**
	 * The form with an existing post chosen ("Rewrite with RankSphere" in the editor).
	 *
	 * @param int $post_id The post.
	 */
	public static function url_for_post( int $post_id ): string {
		return add_query_arg( 'post', $post_id, self::url() );
	}

	/**
	 * "Start text".
	 */
	public function handle_start(): void {
		$this->guard( self::START_ACTION );

		$types    = Value::maps( $this->overview_or_empty(), 'types' );
		$type     = self::posted( 'type' );
		$required = array();

		foreach ( $types as $option ) {
			if ( Value::text( $option, 'key' ) !== $type ) {
				continue;
			}

			foreach ( Value::maps( $option, 'required' ) as $field ) {
				$key   = Value::text( $field, 'key' );
				$value = self::posted( 'required_' . $type . '_' . $key, true );

				if ( '' !== $key && '' !== $value ) {
					$required[ $key ] = $value;
				}
			}
		}

		$post      = null;
		$post_type = sanitize_key( self::posted( 'post_type' ) );
		$topic     = self::posted( 'topic' );

		if ( 'existing' === self::posted( 'mode' ) ) {
			$post = get_post( absint( self::posted( 'source_post' ) ) );

			if ( ! $post instanceof \WP_Post || 'trash' === $post->post_status || ! current_user_can( 'edit_post', $post->ID ) ) {
				$this->notice( 'watch', __( 'Choose the post RankSphere should rewrite.', 'ranksphere' ) );
				$this->redirect( self::url() );
			}

			$topic = '' !== $topic ? $topic : html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
		}

		if ( mb_strlen( $topic ) < 3 ) {
			$this->notice( 'watch', __( 'Enter a topic.', 'ranksphere' ) );
			$this->redirect( self::url() );
		}

		$result = $this->texts->start(
			array(
				'type'      => $type,
				'topic'     => $topic,
				'post_type' => array_key_exists( $post_type, Texts::post_types() ) ? $post_type : '',
				'notes'     => self::posted( 'notes', true ),
				'required'  => $required,
			),
			$post instanceof \WP_Post ? $post : null
		);

		if ( $result instanceof WP_Error ) {
			$this->notice( 'act', self::error_text( $result ) );
			$this->redirect( self::url() );
		}

		$id = (int) ( Value::number( $result, 'id' ) ?? 0 );
		$this->notice( 'good', __( 'RankSphere is writing the text. This takes one to three minutes – the page updates itself.', 'ranksphere' ) );
		$this->redirect( self::url( $id ) );
	}

	/**
	 * "Write again with the answers".
	 */
	public function handle_answer(): void {
		$this->guard( self::ANSWER_ACTION );

		$id      = (int) self::posted( 'text' );
		$answers = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked the nonce.
		$raw = isset( $_POST['answers'] ) && is_array( $_POST['answers'] ) ? map_deep( wp_unslash( $_POST['answers'] ), 'sanitize_textarea_field' ) : array();

		// By the question's position: questions with brackets or quotes would not survive as field names.
		foreach ( $raw as $index => $answer ) {
			if ( is_int( $index ) && $index >= 0 && $index < 20 && is_string( $answer ) && '' !== trim( $answer ) ) {
				$answers[ $index ] = $answer;
			}
		}

		if ( array() === $answers ) {
			$this->notice( 'watch', __( 'Answer at least one question.', 'ranksphere' ) );
			$this->redirect( self::url( $id ) );
		}

		$result = $this->texts->answer( $id, $answers );
		$this->notice(
			$result instanceof WP_Error ? 'act' : 'good',
			$result instanceof WP_Error ? self::error_text( $result ) : __( 'RankSphere writes the text again with your answers.', 'ranksphere' )
		);
		$this->redirect( self::url( $id ) );
	}

	/**
	 * "Save as draft": into WordPress, then to the editor.
	 */
	public function handle_draft(): void {
		$this->guard( self::DRAFT_ACTION );

		$id     = (int) self::posted( 'text' );
		$result = $this->texts->save_draft( $id, sanitize_key( self::posted( 'post_type' ) ) );

		if ( $result instanceof WP_Error ) {
			$this->notice( 'act', self::error_text( $result ) );
			$this->redirect( self::url( $id ) );
		}

		$this->redirect( admin_url( 'post.php?post=' . $result['post_id'] . '&action=edit' ) );
	}

	/**
	 * "Revise with this note".
	 */
	public function handle_note(): void {
		$this->guard( self::NOTE_ACTION );

		$id   = (int) self::posted( 'text' );
		$note = self::posted( 'note', true );

		if ( mb_strlen( $note ) < 3 ) {
			$this->notice( 'watch', __( 'Write what RankSphere should change.', 'ranksphere' ) );
			$this->redirect( self::url( $id ) );
		}

		$result = $this->texts->note( $id, $note );
		$this->notice(
			$result instanceof WP_Error ? 'act' : 'good',
			$result instanceof WP_Error ? self::error_text( $result ) : __( 'RankSphere revises the text with your note.', 'ranksphere' )
		);
		$this->redirect( self::url( $id ) );
	}

	/**
	 * "Restore this version".
	 */
	public function handle_restore(): void {
		$this->guard( self::RESTORE_ACTION );

		$id      = (int) self::posted( 'text' );
		$version = (int) self::posted( 'version' );
		$result  = $this->texts->restore( $id, $version );
		$this->notice(
			$result instanceof WP_Error ? 'act' : 'good',
			$result instanceof WP_Error
				? self::error_text( $result )
				: sprintf(
					/* translators: %d: version number. */
					__( 'Version %d is the current one again.', 'ranksphere' ),
					$version
				)
		);
		$this->redirect( self::url( $id ) );
	}

	/**
	 * Renders the page: one text, or the list with "New text".
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to see RankSphere.', 'ranksphere' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects what to show.
		$id = isset( $_GET['text'] ) && is_numeric( $_GET['text'] ) ? (int) $_GET['text'] : 0;

		echo '<div class="wrap ranksphere">';

		if ( null === $this->store->get() ) {
			Ui::out( '<div class="rs-header"><h1>' . esc_html__( 'Texts', 'ranksphere' ) . '</h1></div>' . Ui::note( 'neutral', __( 'This site is not connected to RankSphere.', 'ranksphere' ) ) );
			echo '</div>';

			return;
		}

		Ui::out( $id > 0 ? $this->detail( $id ) : $this->list() );
		echo '</div>';
	}

	/**
	 * The list and "New text".
	 */
	private function list(): string {
		$overview = $this->texts->overview();
		$html     = '<div class="rs-header"><div><h1>' . esc_html__( 'Texts', 'ranksphere' ) . '</h1><p class="rs-muted">'
			. esc_html__( 'RankSphere writes texts in your company\'s voice, only with facts you confirm – open points become questions. You save the result here as a draft; nothing is published.', 'ranksphere' )
			. '</p></div></div>' . $this->pending_notice();

		if ( $overview instanceof WP_Error ) {
			return $html . Ui::note( 'act', self::error_text( $overview ) );
		}

		$blocked = Value::text( $overview, 'blocked' );
		$form    = '' !== $blocked ? Ui::note( 'watch', $blocked ) : $this->start_form( Value::maps( $overview, 'types' ) );
		$ideas   = '' !== $blocked ? array() : $this->texts->ideas();

		return $html . Ui::grid(
			Ui::tile( __( 'New text', 'ranksphere' ), 'plus', $form, array( 'span' => 'side' ) )
			. '<div class="rs-span-wide rs-stack">'
			. ( array() !== $ideas ? Ui::tile( __( 'What to write about', 'ranksphere' ), 'sparkles', self::idea_list( $ideas ), array( 'span' => 'full' ) ) : '' )
			. Ui::tile( __( 'Texts', 'ranksphere' ), 'file-text', $this->text_list( Value::maps( $overview, 'texts' ) ), array( 'span' => 'full' ) )
			. '</div>'
		);
	}

	/**
	 * Ideas from RankSphere's tasks – one click fills the form.
	 *
	 * @param list<array<mixed>> $ideas RankSphere's ideas.
	 */
	private static function idea_list( array $ideas ): string {
		$items = '';

		foreach ( array_slice( $ideas, 0, 5 ) as $idea ) {
			$url    = add_query_arg(
				array_filter(
					array(
						'type'  => Value::text( $idea, 'type' ),
						'topic' => Value::text( $idea, 'topic' ),
						'path'  => Value::text( $idea, 'page' ),
					),
					static fn ( string $value ): bool => '' !== $value
				),
				self::url()
			);
			$items .= '<li><div class="rs-list-main"><span class="rs-list-title">' . esc_html( Value::text( $idea, 'title' ) ) . '</span>'
				. '<span class="rs-muted">' . esc_html( Value::text( $idea, 'why' ) ) . '</span>'
				. '<span class="rs-meta">' . Ui::pill( Value::text( $idea, 'type_label' ), 'primary' ) . esc_html( Value::text( $idea, 'area' ) ) . '</span></div>'
				. '<a class="rs-button rs-button-small rs-button-outline" href="' . esc_url( $url . '#rs-new' ) . '">' . esc_html__( 'Use', 'ranksphere' ) . '</a></li>';
		}

		return '<ul class="rs-list">' . $items . '</ul>';
	}

	/**
	 * The form for a new text: post type, new or an existing post (chosen from a list – no
	 * address to type), kind of text (with its hint and own facts), topic, notes. Filled in from
	 * the address when coming from an idea or from the editor (`type`, `topic`, `post`, `path`).
	 *
	 * @param list<array<mixed>> $types RankSphere's text types.
	 */
	private function start_form( array $types ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only fills the form.
		$prefill = array(
			'type'  => isset( $_GET['type'] ) && is_string( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '',
			'topic' => isset( $_GET['topic'] ) && is_string( $_GET['topic'] ) ? sanitize_text_field( wp_unslash( $_GET['topic'] ) ) : '',
			'post'  => isset( $_GET['post'] ) && is_string( $_GET['post'] ) ? absint( $_GET['post'] ) : 0,
			'path'  => isset( $_GET['path'] ) && is_string( $_GET['path'] ) ? sanitize_text_field( wp_unslash( $_GET['path'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$post_types = Texts::post_types();
		$source     = $prefill['post'] > 0 ? get_post( $prefill['post'] ) : ( str_starts_with( $prefill['path'], '/' ) ? get_post( url_to_postid( home_url( $prefill['path'] ) ) ) : null );
		$source     = $source instanceof \WP_Post && array_key_exists( $source->post_type, $post_types ) && current_user_can( 'edit_post', $source->ID ) ? $source : null;
		$post_type  = null !== $source ? $source->post_type : ( array_key_exists( 'post', $post_types ) ? 'post' : (string) array_key_first( $post_types ) );
		$keys       = array_map( static fn ( array $type ): string => Value::text( $type, 'key' ), $types );
		$chosen     = in_array( $prefill['type'], $keys, true ) ? $prefill['type'] : self::default_kind( $post_type, $keys );
		$options    = '';
		$extra      = '';
		$hint       = '';

		foreach ( $types as $type ) {
			$key      = Value::text( $type, 'key' );
			$options .= '<option value="' . esc_attr( $key ) . '" data-hint="' . esc_attr( Value::text( $type, 'hint' ) ) . '"' . selected( $key, $chosen, false ) . '>' . esc_html( Value::text( $type, 'label' ) ) . '</option>';
			$fields   = '';
			$hint     = $key === $chosen ? Value::text( $type, 'hint' ) : $hint;

			foreach ( Value::maps( $type, 'required' ) as $field ) {
				$name    = 'required_' . $key . '_' . Value::text( $field, 'key' );
				$fields .= '<div class="rs-field"><label for="' . esc_attr( $name ) . '">' . esc_html( Value::text( $field, 'label' ) ) . '</label>'
					. '<textarea id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" rows="3" maxlength="3000"></textarea></div>';
			}

			if ( '' !== $fields ) {
				$extra .= '<div data-ranksphere-type-fields="' . esc_attr( $key ) . '"' . ( $key === $chosen ? '' : ' hidden' ) . '>' . $fields . '</div>';
			}
		}

		$type_options = '';

		foreach ( $post_types as $name => $label ) {
			$type_options .= '<option value="' . esc_attr( $name ) . '" data-kind="' . esc_attr( self::default_kind( $name, $keys ) ) . '"' . selected( $name, $post_type, false ) . '>' . esc_html( $label ) . '</option>';
		}

		$posts = self::editable_posts( $post_type );

		if ( null !== $source && ! in_array( $source->ID, array_column( $posts, 'id' ), true ) ) {
			array_unshift(
				$posts,
				array(
					'id'    => $source->ID,
					'title' => self::post_label( $source ),
				)
			);
		}

		$post_options = '<option value="">' . esc_html__( '– choose –', 'ranksphere' ) . '</option>';

		foreach ( $posts as $post ) {
			$post_options .= '<option value="' . esc_attr( (string) $post['id'] ) . '"' . selected( $post['id'], null !== $source ? $source->ID : 0, false ) . '>' . esc_html( $post['title'] ) . '</option>';
		}

		$existing = null !== $source;

		return '<form class="rs-form" id="rs-new" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::START_ACTION ) . '">'
			. wp_nonce_field( self::START_ACTION, '_wpnonce', true, false )
			. '<div class="rs-field"><label for="rs-post-type">' . esc_html__( 'Post type', 'ranksphere' ) . '</label>'
			. '<select id="rs-post-type" name="post_type" data-ranksphere-post-type>' . $type_options . '</select></div>'
			. '<fieldset class="rs-field rs-choice"><legend>' . esc_html__( 'What should RankSphere write?', 'ranksphere' ) . '</legend>'
			. '<label><input type="radio" name="mode" value="new" data-ranksphere-mode' . checked( ! $existing, true, false ) . '> ' . esc_html__( 'Something new', 'ranksphere' ) . '</label>'
			. '<label><input type="radio" name="mode" value="existing" data-ranksphere-mode' . checked( $existing, true, false ) . '> ' . esc_html__( 'A rewrite of an existing one', 'ranksphere' ) . '</label></fieldset>'
			. '<div class="rs-field" data-ranksphere-existing' . ( $existing ? '' : ' hidden' ) . '><label for="rs-source">' . esc_html__( 'Which one?', 'ranksphere' ) . '</label>'
			. '<input type="search" class="rs-search" placeholder="' . esc_attr__( 'Search by title …', 'ranksphere' ) . '" aria-label="' . esc_attr__( 'Search by title', 'ranksphere' ) . '" data-ranksphere-post-search hidden>'
			. '<select id="rs-source" name="source_post" data-ranksphere-posts>' . $post_options . '</select>'
			. '<span class="rs-muted">' . esc_html__( 'RankSphere builds on its text. A published page stays as it is – the rewrite becomes a draft you take over yourself.', 'ranksphere' ) . '</span></div>'
			. '<div class="rs-field"><label for="rs-type">' . esc_html__( 'Kind of text', 'ranksphere' ) . '</label>'
			. '<select id="rs-type" name="type" data-ranksphere-type>' . $options . '</select>'
			. '<span class="rs-muted" data-ranksphere-type-hint>' . esc_html( $hint ) . '</span></div>'
			. '<div class="rs-field"><label for="rs-topic">' . esc_html__( 'Topic', 'ranksphere' ) . '</label>'
			. '<input type="text" id="rs-topic" name="topic" value="' . esc_attr( $prefill['topic'] ) . '"' . ( $existing ? '' : ' required' ) . ' minlength="3" maxlength="300" placeholder="' . esc_attr( $existing ? __( 'Optional – else the title of the post', 'ranksphere' ) : __( 'e.g. a question customers ask', 'ranksphere' ) ) . '"></div>'
			. $extra
			. '<div class="rs-field"><label for="rs-notes">' . esc_html__( 'Facts and notes (optional)', 'ranksphere' ) . '</label>'
			. '<textarea id="rs-notes" name="notes" rows="4" maxlength="5000" placeholder="' . esc_attr__( 'What RankSphere should know: prices, process, what makes you different …', 'ranksphere' ) . '"></textarea></div>'
			. '<div class="rs-actions"><button type="submit" class="rs-button">' . Ui::icon( 'sparkles' ) . esc_html__( 'Write text', 'ranksphere' ) . '</button>'
			. '<span class="rs-muted rs-small">' . esc_html__( 'Takes one to three minutes.', 'ranksphere' ) . '</span></div>'
			. '</form>';
	}

	/**
	 * The kind of text a post type usually gets: pages present a service, posts answer a question.
	 *
	 * @param string   $post_type The post type.
	 * @param string[] $keys      RankSphere's kinds of text.
	 *
	 * @phpstan-param list<string> $keys
	 */
	private static function default_kind( string $post_type, array $keys ): string {
		$wanted = 'post' === $post_type ? 'guide' : 'landing';

		return in_array( $wanted, $keys, true ) ? $wanted : (string) ( $keys[0] ?? '' );
	}

	/**
	 * The latest texts.
	 *
	 * @param list<array<mixed>> $texts RankSphere's texts.
	 */
	private function text_list( array $texts ): string {
		if ( array() === $texts ) {
			return '<p class="rs-muted">' . esc_html__( 'No texts yet. Start the first one on the left.', 'ranksphere' ) . '</p>';
		}

		$items = '';

		foreach ( $texts as $text ) {
			$id      = (int) ( Value::number( $text, 'id' ) ?? 0 );
			$post_id = (int) ( Value::number( $text, 'wordpress_post_id' ) ?? 0 );
			$edit    = $post_id > 0 && null !== get_post( $post_id ) && current_user_can( 'edit_post', $post_id ) ? (string) get_edit_post_link( $post_id ) : '';
			$by      = Value::text( $text, 'by' );
			$source  = (int) ( Value::number( $text, 'source_post_id' ) ?? 0 );
			$for     = $source > 0 && null !== get_post( $source ) ? ' · ' . sprintf(
				/* translators: %s: title of the existing post. */
				__( 'for "%s"', 'ranksphere' ),
				html_entity_decode( get_the_title( $source ), ENT_QUOTES, 'UTF-8' )
			) : '';
			$items .= '<li><div class="rs-list-main"><a class="rs-list-title" href="' . esc_url( self::url( $id ) ) . '">' . esc_html( Value::text( $text, 'title' ) ) . '</a>'
				. '<span class="rs-meta">' . Ui::pill( Value::text( $text, 'state' ), Value::text( $text, 'tone' ) ) . esc_html( Value::text( $text, 'type' ) . $for )
				. ( '' !== $by ? ' · ' . esc_html( $by ) : '' ) . ( '' !== Value::text( $text, 'created_at' ) ? ' · ' . esc_html( self::date( Value::text( $text, 'created_at' ) ) ) : '' ) . '</span></div>'
				. ( '' !== $edit ? '<a class="rs-link" href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit draft', 'ranksphere' ) . '</a>' : '' ) . '</li>';
		}

		return '<ul class="rs-list">' . $items . '</ul>';
	}

	/**
	 * One text: state, open questions, draft, review, SEO fields, the text itself.
	 *
	 * @param int $id RankSphere's id.
	 */
	private function detail( int $id ): string {
		$text = $this->texts->get( $id );
		$back = '<a class="rs-link" href="' . esc_url( self::url() ) . '">' . Ui::icon( 'arrow-left' ) . esc_html__( 'All texts', 'ranksphere' ) . '</a>';

		if ( $text instanceof WP_Error ) {
			return '<div class="rs-header"><div>' . $back . '</div></div>' . Ui::note( 'act', self::error_text( $text ) );
		}

		$status = Value::text( $text, 'status' );
		$source = self::source_post( $text );
		$for    = '';

		if ( null !== $source ) {
			$title = '' !== get_the_title( $source ) ? get_the_title( $source ) : __( '(no title)', 'ranksphere' );
			$for   = ' · ' . sprintf(
				/* translators: %s: title of the existing post (link). */
				esc_html__( 'for %s', 'ranksphere' ),
				current_user_can( 'edit_post', $source->ID ) ? '<a href="' . esc_url( (string) get_edit_post_link( $source->ID ) ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title )
			);
		}

		$html = '<div class="rs-header"><div>' . $back . '<h1>' . esc_html( Value::text( $text, 'title' ) ) . '</h1>'
			. '<p class="rs-meta">' . Ui::pill( Value::text( $text, 'state' ), Value::text( $text, 'tone' ) ) . esc_html( Value::text( $text, 'type' ) )
			. ( '' !== Value::text( $text, 'by' ) ? ' · ' . esc_html( Value::text( $text, 'by' ) ) : '' ) . $for . '</p></div>'
			. Ui::link( Value::link( $text, 'url' ), __( 'Open in RankSphere', 'ranksphere' ) ) . '</div>' . $this->pending_notice();

		if ( 'pending' === $status ) {
			return $html . Ui::grid(
				Ui::tile(
					__( 'In progress', 'ranksphere' ),
					'loader',
					'<p>' . esc_html__( 'RankSphere writes the text and checks it against your voice and the facts – one to three minutes. This page updates itself.', 'ranksphere' ) . '</p>',
					array(
						'span'  => 'full',
						'tone'  => 'focus',
						'title' => __( 'RankSphere is writing …', 'ranksphere' ),
					)
				)
			) . '<span data-ranksphere-refresh hidden></span>';
		}

		if ( 'failed' === $status ) {
			return $html . Ui::note( 'act', '' !== Value::text( $text, 'error' ) ? Value::text( $text, 'error' ) : __( 'The text could not be written.', 'ranksphere' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects what to show.
		$number = isset( $_GET['version'] ) && is_numeric( $_GET['version'] ) ? (int) $_GET['version'] : 0;

		if ( $number > 0 ) {
			return $html . $this->version_view( $id, $number, Value::maps( $text, 'versions' ) );
		}

		$questions = Value::texts( $text, 'questions' );
		$tiles     = '';

		if ( array() !== $questions ) {
			$tiles .= Ui::tile(
				__( 'Next step', 'ranksphere' ),
				'question',
				$this->answer_form( $id, $questions ),
				array(
					'span'  => 'full',
					'tone'  => 'focus',
					'title' => sprintf(
						/* translators: %d: number of open questions. */
						_n( '%d fact is missing', '%d facts are missing', count( $questions ), 'ranksphere' ),
						count( $questions )
					),
				)
			);
		}

		$tiles   .= Ui::tile( __( 'Text', 'ranksphere' ), 'file-text', '<div class="rs-prose">' . wp_kses_post( Value::text( $text, 'html' ) ) . '</div>', array( 'span' => 'wide' ) );
		$versions = Value::maps( $text, 'versions' );
		$tiles   .= '<div class="rs-span-side rs-stack">'
			. Ui::tile( __( 'In WordPress', 'ranksphere' ), 'send', $this->draft_form( $id, $text, array() !== $questions ), array( 'span' => 'full' ) )
			. Ui::tile( __( 'Refine', 'ranksphere' ), 'pen-line', self::note_form( $id ), array( 'span' => 'full' ) )
			. Ui::tile( __( 'Review', 'ranksphere' ), 'clipboard-check', self::review( Value::map( $text, 'review' ) ), array( 'span' => 'full' ) )
			. Ui::tile( __( 'Search result', 'ranksphere' ), 'search', self::snippet( $text ), array( 'span' => 'full' ) )
			. ( count( $versions ) > 1 ? Ui::tile( __( 'Versions', 'ranksphere' ), 'refresh', self::version_list( $id, $versions ), array( 'span' => 'full' ) ) : '' )
			. '</div>';

		return $html . Ui::grid( $tiles );
	}

	/**
	 * An older version: its text, and going back to it.
	 *
	 * @param int                $id       RankSphere's id.
	 * @param int                $number   The version.
	 * @param list<array<mixed>> $versions All versions, newest first.
	 */
	private function version_view( int $id, int $number, array $versions ): string {
		$version = $this->texts->version( $id, $number );

		if ( $version instanceof WP_Error ) {
			return Ui::note( 'act', self::error_text( $version ) );
		}

		$current = (int) ( Value::number( $versions[0] ?? array(), 'number' ) ?? 0 );
		$body    = '<p>' . esc_html( Value::text( $version, 'label' ) ) . '<br><span class="rs-muted rs-small">' . self::version_meta( $version ) . '</span></p>'
			. '<div class="rs-actions">'
			. ( $number !== $current ? self::restore_form( $id, $number, __( 'Restore this version', 'ranksphere' ), 'rs-button' ) : '' )
			. '<a class="rs-link" href="' . esc_url( self::url( $id ) ) . '">' . esc_html__( 'Back to the current version', 'ranksphere' ) . '</a></div>';

		return Ui::grid(
			Ui::tile(
				__( 'Earlier version', 'ranksphere' ),
				'refresh',
				$body,
				array(
					'span'  => 'full',
					'tone'  => 'focus',
					'title' => sprintf(
						/* translators: %d: version number. */
						__( 'Version %d', 'ranksphere' ),
						$number
					),
				)
			)
			. Ui::tile( __( 'Text', 'ranksphere' ), 'file-text', '<div class="rs-prose">' . wp_kses_post( Value::text( $version, 'html' ) ) . '</div>', array( 'span' => 'wide' ) )
			. '<div class="rs-span-side rs-stack">'
			. Ui::tile( __( 'Search result', 'ranksphere' ), 'search', self::snippet( $version ), array( 'span' => 'full' ) )
			. Ui::tile( __( 'Versions', 'ranksphere' ), 'refresh', self::version_list( $id, $versions, $number ), array( 'span' => 'full' ) )
			. '</div>'
		);
	}

	/**
	 * Every version, newest first: what made it, who, score – look at it or go back to it.
	 *
	 * @param int                $id       RankSphere's id.
	 * @param list<array<mixed>> $versions The versions, newest first.
	 * @param int                $shown    The version on screen (0: the current one).
	 */
	private static function version_list( int $id, array $versions, int $shown = 0 ): string {
		$items = '';

		foreach ( $versions as $i => $version ) {
			$number = (int) ( Value::number( $version, 'number' ) ?? 0 );
			$label  = sprintf(
				/* translators: 1: version number, 2: what made it, e.g. "First draft". */
				__( '%1$d. %2$s', 'ranksphere' ),
				$number,
				Value::text( $version, 'label' )
			);
			$action = 0 === $i
				? '<span class="rs-muted rs-small">' . esc_html__( 'current', 'ranksphere' ) . '</span>'
				: ( $number === $shown ? '' : '<a class="rs-link" href="' . esc_url( add_query_arg( 'version', $number, self::url( $id ) ) ) . '">' . esc_html__( 'View', 'ranksphere' ) . '</a>' );
			$items .= '<li' . ( $number === $shown ? ' class="rs-current"' : '' ) . '><div class="rs-list-main"><span>' . esc_html( $label ) . '</span><span class="rs-muted rs-small">' . self::version_meta( $version ) . '</span></div>' . $action . '</li>';
		}

		return '<ul class="rs-list">' . $items . '</ul>';
	}

	/**
	 * Who and when, and the score.
	 *
	 * @param array<mixed> $version A version.
	 */
	private static function version_meta( array $version ): string {
		$score = Value::number( $version, 'score' );

		return esc_html(
			implode(
				' · ',
				array_filter(
					array(
						Value::text( $version, 'by' ),
						self::date( Value::text( $version, 'created_at' ) ),
						null === $score ? '' : sprintf(
							/* translators: %d: review score out of 100. */
							__( '%d/100', 'ranksphere' ),
							(int) $score
						),
					),
					static fn ( string $part ): bool => '' !== $part
				)
			)
		);
	}

	/**
	 * "Restore" for one version.
	 *
	 * @param int    $id      RankSphere's id.
	 * @param int    $number  The version.
	 * @param string $label   Button text.
	 * @param string $classes Button classes.
	 */
	private static function restore_form( int $id, int $number, string $label, string $classes ): string {
		return '<form class="rs-inline-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::RESTORE_ACTION ) . '">'
			. '<input type="hidden" name="text" value="' . esc_attr( (string) $id ) . '">'
			. '<input type="hidden" name="version" value="' . esc_attr( (string) $number ) . '">'
			. wp_nonce_field( self::RESTORE_ACTION, '_wpnonce', true, false )
			. '<button type="submit" class="' . esc_attr( $classes ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * "Refine": a note on the text, RankSphere revises the current version with it.
	 *
	 * @param int $id RankSphere's id.
	 */
	private static function note_form( int $id ): string {
		return '<form class="rs-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::NOTE_ACTION ) . '">'
			. '<input type="hidden" name="text" value="' . esc_attr( (string) $id ) . '">'
			. wp_nonce_field( self::NOTE_ACTION, '_wpnonce', true, false )
			. '<div class="rs-field"><label for="rs-note">' . esc_html__( 'What should change?', 'ranksphere' ) . '</label>'
			. '<textarea id="rs-note" name="note" rows="3" minlength="3" maxlength="2000" required placeholder="' . esc_attr__( 'e.g. shorter, more about prices, fewer technical terms', 'ranksphere' ) . '"></textarea>'
			. '<span class="rs-muted">' . esc_html__( 'RankSphere changes only that and checks the text again. The version before stays under "Versions".', 'ranksphere' ) . '</span></div>'
			. '<div class="rs-actions"><button type="submit" class="rs-button rs-button-outline">' . Ui::icon( 'pen-line' ) . esc_html__( 'Revise with this note', 'ranksphere' ) . '</button></div></form>';
	}

	/**
	 * The existing post a text was written for (null: a new post, or it is gone).
	 *
	 * @param array<mixed> $text The text.
	 */
	private static function source_post( array $text ): ?\WP_Post {
		$id   = (int) ( Value::number( Value::map( $text, 'source' ) ?? array(), 'post_id' ) ?? 0 );
		$post = $id > 0 ? get_post( $id ) : null;

		return $post instanceof \WP_Post && 'trash' !== $post->post_status ? $post : null;
	}

	/**
	 * The open questions with a field each.
	 *
	 * @param int      $id        RankSphere's id.
	 * @param string[] $questions The questions.
	 *
	 * @phpstan-param list<string> $questions
	 */
	private function answer_form( int $id, array $questions ): string {
		$fields = '';

		foreach ( $questions as $i => $question ) {
			$fields .= '<div class="rs-field"><label for="rs-answer-' . $i . '">' . esc_html( $question ) . '</label>'
				. '<textarea id="rs-answer-' . $i . '" name="answers[' . (int) $i . ']" rows="2" maxlength="3000"></textarea></div>';
		}

		return '<p>' . esc_html__( 'RankSphere only writes what you confirm. Answer what you know – the text is written again with it. Placeholders [[…]] mark the spots in the text.', 'ranksphere' ) . '</p>'
			. '<form class="rs-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::ANSWER_ACTION ) . '">'
			. '<input type="hidden" name="text" value="' . esc_attr( (string) $id ) . '">'
			. wp_nonce_field( self::ANSWER_ACTION, '_wpnonce', true, false )
			. $fields
			. '<div class="rs-actions"><button type="submit" class="rs-button">' . Ui::icon( 'refresh' ) . esc_html__( 'Write again with the answers', 'ranksphere' ) . '</button></div></form>';
	}

	/**
	 * "Save as draft" – or the link to the draft that exists.
	 *
	 * @param int          $id         RankSphere's id.
	 * @param array<mixed> $text       The text.
	 * @param bool         $open_facts Whether facts are still missing.
	 */
	private function draft_form( int $id, array $text, bool $open_facts ): string {
		$post_id = (int) ( Value::number( $text, 'wordpress_post_id' ) ?? 0 );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;
		$types   = Texts::post_types();
		$fixed   = Value::text( $text, 'post_type' );
		$default = '' !== $fixed ? $fixed : Value::text( $text, 'default_post_type' );
		$note    = $open_facts
			? Ui::note( 'watch', __( 'With open placeholders the draft can be saved, but not published – fill them in first.', 'ranksphere' ) )
			: Ui::note( 'neutral', __( 'Saved as draft with you as author. Nothing is published – that stays your step in WordPress.', 'ranksphere' ) );

		if ( null !== Value::map( $text, 'source' ) ) {
			return $this->source_form( $id, $text, $post, $open_facts );
		}

		if ( null !== $post && 'publish' === $post->post_status ) {
			return Ui::note( 'good', __( 'This text is published.', 'ranksphere' ) ) . ( current_user_can( 'edit_post', $post_id ) ? '<p>' . Ui::link( (string) get_edit_post_link( $post_id ), __( 'Open in WordPress', 'ranksphere' ) ) . '</p>' : '' );
		}

		$select = '';

		if ( '' === $fixed && count( $types ) > 1 ) {
			$options = '';

			foreach ( $types as $name => $label ) {
				$options .= '<option value="' . esc_attr( $name ) . '"' . ( $name === $default ? ' selected' : '' ) . '>' . esc_html( $label ) . '</option>';
			}

			$select = '<div class="rs-field"><label for="rs-post-type">' . esc_html__( 'Create as', 'ranksphere' ) . '</label><select id="rs-post-type" name="post_type">' . $options . '</select></div>';
		} else {
			$select = '<input type="hidden" name="post_type" value="' . esc_attr( '' !== $fixed ? $fixed : (string) array_key_first( $types ) ) . '">';
		}

		$edit = null !== $post && current_user_can( 'edit_post', $post_id ) ? '<a class="rs-link" href="' . esc_url( (string) get_edit_post_link( $post_id ) ) . '">' . esc_html__( 'Edit draft', 'ranksphere' ) . '</a>' : '';

		return $note . '<form class="rs-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::DRAFT_ACTION ) . '">'
			. '<input type="hidden" name="text" value="' . esc_attr( (string) $id ) . '">'
			. wp_nonce_field( self::DRAFT_ACTION, '_wpnonce', true, false )
			. $select
			. '<div class="rs-actions"><button type="submit" class="rs-button' . ( null !== $post ? ' rs-button-outline' : '' ) . '">'
			. esc_html( null !== $post ? __( 'Update draft', 'ranksphere' ) : __( 'Save as draft', 'ranksphere' ) ) . '</button>' . $edit . '</div></form>';
	}

	/**
	 * Saving a text written for an existing post: into the post while it is unpublished, else as
	 * revision draft the author takes over in the editor.
	 *
	 * @param int           $id         RankSphere's id.
	 * @param array<mixed>  $text       The text.
	 * @param \WP_Post|null $saved      The post the text went into last time.
	 * @param bool          $open_facts Whether facts are still missing.
	 */
	private function source_form( int $id, array $text, ?\WP_Post $saved, bool $open_facts ): string {
		$source = self::source_post( $text );

		if ( null === $source || ! current_user_can( 'edit_post', $source->ID ) ) {
			return Ui::note( 'act', __( 'The post this text is for no longer exists or you may not edit it.', 'ranksphere' ) );
		}

		$title     = '' !== get_the_title( $source ) ? get_the_title( $source ) : __( '(no title)', 'ranksphere' );
		$published = 'revision' === \RankSphere\Content\Drafts::mode_for( $source );
		$revision  = null !== $saved && $saved->ID !== $source->ID && \RankSphere\Content\Drafts::meta_int( $saved->ID, \RankSphere\Content\Drafts::REVISES_META ) === $source->ID && in_array( $saved->post_status, \RankSphere\Content\Drafts::EDITABLE, true ) ? $saved : null;
		$notes     = $open_facts ? Ui::note( 'watch', __( 'With open placeholders the draft can be saved, but not published – fill them in first.', 'ranksphere' ) ) : '';

		if ( $published ) {
			$notes .= Ui::note(
				'neutral',
				in_array( $source->post_status, \RankSphere\Content\Drafts::EDITABLE, true )
					? sprintf(
						/* translators: %s: title of the draft. */
						__( 'WordPress keeps no earlier versions of "%s", so it stays as it is. RankSphere saves the rewrite as a draft next to it; in its editor you take it over into the original.', 'ranksphere' ),
						$title
					)
					: sprintf(
						/* translators: %s: title of the published post. */
						__( '"%s" is published and stays as it is. RankSphere saves the rewrite as a draft next to it; in its editor you take it over into the original.', 'ranksphere' ),
						$title
					)
			);
			$label = null !== $revision ? __( 'Update the revision', 'ranksphere' ) : __( 'Save as revision', 'ranksphere' );
			$edit  = null !== $revision ? '<a class="rs-link" href="' . esc_url( (string) get_edit_post_link( $revision->ID ) ) . '">' . esc_html__( 'Open the revision', 'ranksphere' ) . '</a>' : '';
		} else {
			$notes .= Ui::note(
				'neutral',
				sprintf(
					/* translators: %s: title of the draft. */
					__( 'The text goes into the draft "%s". Its content before stays as revision.', 'ranksphere' ),
					$title
				)
			);
			$label = __( 'Put into the draft', 'ranksphere' );
			$edit  = '<a class="rs-link" href="' . esc_url( (string) get_edit_post_link( $source->ID ) ) . '">' . esc_html__( 'Edit draft', 'ranksphere' ) . '</a>';
		}

		return $notes . '<form class="rs-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::DRAFT_ACTION ) . '">'
			. '<input type="hidden" name="text" value="' . esc_attr( (string) $id ) . '">'
			. '<input type="hidden" name="post_type" value="' . esc_attr( $source->post_type ) . '">'
			. wp_nonce_field( self::DRAFT_ACTION, '_wpnonce', true, false )
			. '<div class="rs-actions"><button type="submit" class="rs-button">' . esc_html( $label ) . '</button>' . $edit . '</div></form>';
	}

	/**
	 * The review: score, passed or not, the points to fix.
	 *
	 * @param array<mixed>|null $review RankSphere's review.
	 */
	private static function review( ?array $review ): string {
		if ( null === $review ) {
			return '<p class="rs-muted">' . esc_html__( 'No review yet.', 'ranksphere' ) . '</p>';
		}

		$passed = true === ( $review['passed'] ?? null );
		$html   = '<div class="rs-score"><span class="rs-figure">' . esc_html( number_format_i18n( Value::number( $review, 'score' ) ?? 0.0 ) ) . '</span><span class="rs-muted">/ 100</span></div>'
			. Ui::verdict( $passed ? 'good' : 'watch', $passed ? __( 'Passed RankSphere\'s review.', 'ranksphere' ) : __( 'Not passed yet – see the points below.', 'ranksphere' ) );
		$items  = '';

		foreach ( array_slice( Value::maps( $review, 'issues' ), 0, 6 ) as $issue ) {
			$priority = Value::text( $issue, 'priority' );
			$items   .= '<li><div class="rs-list-main"><span class="rs-meta">' . Ui::pill(
				$priority,
				match ( $priority ) {
				'P0' => 'act',
				'P1' => 'watch',
				default => 'neutral',
				}
			) . '</span><span>' . esc_html( Value::text( $issue, 'problem' ) ) . '</span>'
				. ( '' !== Value::text( $issue, 'fix' ) ? '<span class="rs-muted rs-small">' . esc_html( Value::text( $issue, 'fix' ) ) . '</span>' : '' ) . '</div></li>';
		}

		return $html . ( '' !== $items ? '<ul class="rs-list">' . $items . '</ul>' : '' );
	}

	/**
	 * SEO title and description, as in the search result.
	 *
	 * @param array<mixed> $text The text.
	 */
	private static function snippet( array $text ): string {
		$html = '';

		foreach ( array(
			'seo_title'        => __( 'SEO title', 'ranksphere' ),
			'meta_description' => __( 'Meta description', 'ranksphere' ),
		) as $key => $label ) {
			$value = Value::text( $text, $key );

			if ( '' !== $value ) {
				$html .= '<div class="rs-list-main"><span class="rs-label">' . esc_html( $label ) . '</span><span>' . esc_html( $value ) . '</span><span class="rs-muted rs-small">'
					. esc_html(
						/* translators: %d: number of characters. */
						sprintf( _n( '%d character', '%d characters', mb_strlen( $value ), 'ranksphere' ), mb_strlen( $value ) )
					) . '</span></div>';
			}
		}

		return '' !== $html ? $html : '<p class="rs-muted">' . esc_html__( 'None yet.', 'ranksphere' ) . '</p>';
	}

	/**
	 * The note left by the last form, once.
	 */
	private function pending_notice(): string {
		$key  = self::NOTICE . get_current_user_id();
		$note = get_transient( $key );

		if ( ! is_array( $note ) || ! is_string( $note[0] ?? null ) || ! is_string( $note[1] ?? null ) ) {
			return '';
		}

		delete_transient( $key );

		return '<div class="rs-grid"><div class="rs-span-full">' . Ui::note( $note[0], $note[1] ) . '</div></div>';
	}

	/**
	 * Leaves a note for the next page view.
	 *
	 * @param string $tone good|watch|act|neutral.
	 * @param string $text The note.
	 */
	private function notice( string $tone, string $text ): void {
		set_transient( self::NOTICE . get_current_user_id(), array( $tone, $text ), MINUTE_IN_SECONDS );
	}

	/**
	 * What to say about a failed call: RankSphere's own sentence where it sent one.
	 *
	 * @param WP_Error $error The error.
	 */
	public static function error_text( WP_Error $error ): string {
		$data = $error->get_error_data();

		if ( is_array( $data ) && is_string( $data['message'] ?? null ) && '' !== $data['message'] ) {
			return $data['message'];
		}

		return str_starts_with( (string) $error->get_error_code(), 'ranksphere_http_' ) || 'ranksphere_not_connected' === $error->get_error_code()
			? OverviewPage::error_message( (string) $error->get_error_code() )
			: $error->get_error_message();
	}

	/**
	 * Nonce and capability of a form.
	 *
	 * @param string $action The form's action.
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to see RankSphere.', 'ranksphere' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Overview from RankSphere, or nothing.
	 *
	 * @return array<mixed>
	 */
	private function overview_or_empty(): array {
		$overview = $this->texts->overview();

		return $overview instanceof WP_Error ? array() : $overview;
	}

	/**
	 * A posted field (after guard() checked the nonce).
	 *
	 * @param string $name      Field name.
	 * @param bool   $multiline Keep line breaks.
	 */
	private static function posted( string $name, bool $multiline = false ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() checked the nonce.
		if ( ! isset( $_POST[ $name ] ) || ! is_string( $_POST[ $name ] ) ) {
			return '';
		}

		return $multiline ? sanitize_textarea_field( wp_unslash( $_POST[ $name ] ) ) : sanitize_text_field( wp_unslash( $_POST[ $name ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Back to a page.
	 *
	 * @param string $url Where to.
	 */
	private function redirect( string $url ): never {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * A date from RankSphere in the site's format.
	 *
	 * @param string $iso ISO 8601.
	 */
	private static function date( string $iso ): string {
		$time   = strtotime( $iso );
		$format = get_option( 'date_format' );

		return false === $time ? '' : (string) wp_date( is_string( $format ) && '' !== $format ? $format : 'Y-m-d', $time );
	}
}
