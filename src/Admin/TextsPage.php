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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
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

		wp_enqueue_script( 'ranksphere-texts', plugins_url( 'assets/texts.js', \RankSphere\PLUGIN_FILE ), array(), \RankSphere\VERSION, true );
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

		$target = self::posted( 'target_page' );
		$result = $this->texts->start(
			array(
				'type'        => $type,
				'topic'       => self::posted( 'topic' ),
				'target_page' => '' !== $target ? '/' . ltrim( (string) wp_parse_url( $target, PHP_URL_PATH ), '/' ) : '',
				'notes'       => self::posted( 'notes', true ),
				'required'    => $required,
			)
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

		foreach ( $raw as $question => $answer ) {
			if ( is_string( $question ) && is_string( $answer ) && '' !== trim( $answer ) ) {
				$answers[ sanitize_text_field( $question ) ] = $answer;
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

		return $html . Ui::grid(
			Ui::tile( __( 'New text', 'ranksphere' ), 'plus', $form, array( 'span' => 'side' ) )
			. Ui::tile( __( 'Texts', 'ranksphere' ), 'file-text', $this->text_list( Value::maps( $overview, 'texts' ) ), array( 'span' => 'wide' ) )
		);
	}

	/**
	 * The form for a new text: type (with its hint and own facts), topic, page, notes.
	 *
	 * @param list<array<mixed>> $types RankSphere's text types.
	 */
	private function start_form( array $types ): string {
		$options = '';
		$extra   = '';

		foreach ( $types as $i => $type ) {
			$key      = Value::text( $type, 'key' );
			$options .= '<option value="' . esc_attr( $key ) . '" data-hint="' . esc_attr( Value::text( $type, 'hint' ) ) . '"' . ( 0 === $i ? ' selected' : '' ) . '>' . esc_html( Value::text( $type, 'label' ) ) . '</option>';
			$fields   = '';

			foreach ( Value::maps( $type, 'required' ) as $field ) {
				$name    = 'required_' . $key . '_' . Value::text( $field, 'key' );
				$fields .= '<div class="rs-field"><label for="' . esc_attr( $name ) . '">' . esc_html( Value::text( $field, 'label' ) ) . '</label>'
					. '<textarea id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" rows="3" maxlength="3000"></textarea></div>';
			}

			if ( '' !== $fields ) {
				$extra .= '<div data-ranksphere-type-fields="' . esc_attr( $key ) . '"' . ( 0 === $i ? '' : ' hidden' ) . '>' . $fields . '</div>';
			}
		}

		$hint = Value::text( $types[0] ?? array(), 'hint' );

		return '<form class="rs-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::START_ACTION ) . '">'
			. wp_nonce_field( self::START_ACTION, '_wpnonce', true, false )
			. '<div class="rs-field"><label for="rs-type">' . esc_html__( 'Kind of text', 'ranksphere' ) . '</label>'
			. '<select id="rs-type" name="type" data-ranksphere-type>' . $options . '</select>'
			. '<span class="rs-muted" data-ranksphere-type-hint>' . esc_html( $hint ) . '</span></div>'
			. '<div class="rs-field"><label for="rs-topic">' . esc_html__( 'Topic', 'ranksphere' ) . '</label>'
			. '<input type="text" id="rs-topic" name="topic" required minlength="3" maxlength="300" placeholder="' . esc_attr__( 'e.g. a question customers ask', 'ranksphere' ) . '"></div>'
			. $extra
			. '<div class="rs-field"><label for="rs-notes">' . esc_html__( 'Facts and notes (optional)', 'ranksphere' ) . '</label>'
			. '<textarea id="rs-notes" name="notes" rows="4" maxlength="5000" placeholder="' . esc_attr__( 'What RankSphere should know: prices, process, what makes you different …', 'ranksphere' ) . '"></textarea></div>'
			. '<div class="rs-field"><label for="rs-target">' . esc_html__( 'For an existing page (optional)', 'ranksphere' ) . '</label>'
			. '<input type="text" id="rs-target" name="target_page" maxlength="300" placeholder="/leistungen/"></div>'
			. '<div class="rs-actions"><button type="submit" class="rs-button">' . Ui::icon( 'sparkles' ) . esc_html__( 'Write text', 'ranksphere' ) . '</button>'
			. '<span class="rs-muted rs-small">' . esc_html__( 'Takes one to three minutes.', 'ranksphere' ) . '</span></div>'
			. '</form>';
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
			$items  .= '<li><div class="rs-list-main"><a class="rs-list-title" href="' . esc_url( self::url( $id ) ) . '">' . esc_html( Value::text( $text, 'title' ) ) . '</a>'
				. '<span class="rs-meta">' . Ui::pill( Value::text( $text, 'state' ), Value::text( $text, 'tone' ) ) . esc_html( Value::text( $text, 'type' ) )
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
		$html   = '<div class="rs-header"><div>' . $back . '<h1>' . esc_html( Value::text( $text, 'title' ) ) . '</h1>'
			. '<p class="rs-meta">' . Ui::pill( Value::text( $text, 'state' ), Value::text( $text, 'tone' ) ) . esc_html( Value::text( $text, 'type' ) )
			. ( '' !== Value::text( $text, 'by' ) ? ' · ' . esc_html( Value::text( $text, 'by' ) ) : '' ) . '</p></div>'
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

		$tiles .= Ui::tile( __( 'Text', 'ranksphere' ), 'file-text', '<div class="rs-prose">' . wp_kses_post( Value::text( $text, 'html' ) ) . '</div>', array( 'span' => 'wide' ) );
		$tiles .= '<div class="rs-span-side rs-stack">'
			. Ui::tile( __( 'In WordPress', 'ranksphere' ), 'send', $this->draft_form( $id, $text, array() !== $questions ), array( 'span' => 'full' ) )
			. Ui::tile( __( 'Review', 'ranksphere' ), 'clipboard-check', self::review( Value::map( $text, 'review' ) ), array( 'span' => 'full' ) )
			. Ui::tile( __( 'Search result', 'ranksphere' ), 'search', self::snippet( $text ), array( 'span' => 'full' ) )
			. '</div>';

		return $html . Ui::grid( $tiles );
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
				. '<textarea id="rs-answer-' . $i . '" name="answers[' . esc_attr( $question ) . ']" rows="2" maxlength="3000"></textarea></div>';
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
