<?php
/**
 * RankSphere → Overview in the admin.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Admin;

use RankSphere\Connection\ConnectionStore;
use RankSphere\Insights\Insights;
use RankSphere\Insights\Value;
use RankSphere\Support\App;

/**
 * What RankSphere knows about the site, for everyone who writes here: figures, the most important
 * tasks, the latest texts and how the company writes. Rendered on the server from the cached
 * answer (Insights); every detail links into RankSphere.
 */
final class OverviewPage {

	public const SLUG = 'ranksphere';

	/** People who write in WordPress see the overview; connecting stays with administrators. */
	public const CAPABILITY = 'edit_posts';

	/** The admin-post action (and nonce action) that fetches the overview again. */
	public const REFRESH_ACTION = 'ranksphere_refresh';

	/**
	 * Takes the connection store.
	 *
	 * @param ConnectionStore $store Where the connection lives.
	 */
	public function __construct( private readonly ConnectionStore $store = new ConnectionStore() ) {}

	/**
	 * Hooks the menu, the refresh handler and the stylesheet.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 9 );
		add_action( 'admin_post_' . self::REFRESH_ACTION, array( $this, 'handle_refresh' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_style' ) );
	}

	/**
	 * The top-level entry; the settings page hangs below it.
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'RankSphere', 'ranksphere' ),
			__( 'RankSphere', 'ranksphere' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-chart-area',
			81
		);
		add_submenu_page( self::SLUG, __( 'RankSphere overview', 'ranksphere' ), __( 'Overview', 'ranksphere' ), self::CAPABILITY, self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * The stylesheet on RankSphere's pages and the dashboard.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public static function enqueue_style( string $hook_suffix ): void {
		if ( 'index.php' !== $hook_suffix && ! str_contains( $hook_suffix, 'ranksphere' ) ) {
			return;
		}

		wp_enqueue_style( 'ranksphere-admin', plugins_url( 'assets/admin.css', \RankSphere\PLUGIN_FILE ), array(), \RankSphere\VERSION );
	}

	/**
	 * "Refresh" (admin-post.php): forgets the cached overview.
	 */
	public function handle_refresh(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to see RankSphere.', 'ranksphere' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::REFRESH_ACTION );
		Insights::forget();

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to see RankSphere.', 'ranksphere' ) );
		}

		$overview = ( new Insights( $this->store ) )->overview();

		echo '<div class="wrap ranksphere">';

		if ( null === $overview ) {
			Ui::out( $this->not_connected() );
		} elseif ( null === $overview['data'] ) {
			Ui::out( '<div class="rs-header"><h1>' . esc_html__( 'RankSphere', 'ranksphere' ) . '</h1></div>' . Ui::note( 'watch', self::error_message( $overview['error'] ) ) . $this->footer( $overview['fetched_at'] ) );
		} else {
			Ui::out( $this->overview( $overview['data'] ) . $this->footer( $overview['fetched_at'] ) );
		}

		echo '</div>';
	}

	/**
	 * Why the overview could not be loaded, in words.
	 *
	 * @param string|null $code The stored error code.
	 */
	public static function error_message( ?string $code ): string {
		return match ( true ) {
			'ranksphere_http_401' === $code => __( 'RankSphere no longer accepts this connection. An administrator can connect the site again under RankSphere → Settings.', 'ranksphere' ),
			'ranksphere_not_connected' === $code => __( 'This site is not connected to RankSphere.', 'ranksphere' ),
			is_string( $code ) && str_starts_with( $code, 'ranksphere_http_5' ) => __( 'RankSphere is not reachable right now. The overview is loaded again in a few minutes.', 'ranksphere' ),
			default => __( 'The overview from RankSphere could not be loaded. It is tried again in a few minutes.', 'ranksphere' ),
		};
	}

	/**
	 * Not connected: what RankSphere does here, and who can connect it.
	 */
	private function not_connected(): string {
		$action = current_user_can( SettingsPage::CAPABILITY )
			? '<div class="rs-actions"><a class="rs-button" href="' . esc_url( App::connect_url() ) . '">' . esc_html__( 'Connect to RankSphere', 'ranksphere' ) . '</a></div>'
			: Ui::note( 'neutral', __( 'An administrator can connect the site.', 'ranksphere' ) );

		return '<div class="rs-header"><h1>' . esc_html__( 'RankSphere', 'ranksphere' ) . '</h1></div>' . Ui::grid(
			Ui::tile(
				__( 'Not connected yet', 'ranksphere' ),
				'sparkles',
				'<p>' . esc_html__( 'This site is not connected to RankSphere yet. Once connected, this page shows how the site is found on Google and in AI answers, the most important next steps, the texts written in RankSphere and how your company writes.', 'ranksphere' ) . '</p>' . $action,
				array( 'span' => 'full' )
			)
		);
	}

	/**
	 * The overview: focus step and verdict, figures, next steps, texts, voice.
	 *
	 * @param array<mixed> $data RankSphere's answer.
	 */
	private function overview( array $data ): string {
		$project = Value::map( $data, 'project' ) ?? array();
		$verdict = Value::map( $data, 'verdict' ) ?? array();
		$tasks   = Value::maps( $data, 'tasks' );
		$top     = $tasks[0] ?? null;
		$html    = '<div class="rs-header"><div><h1>' . esc_html( Value::text( $project, 'name' ) ) . '</h1><p class="rs-muted">' . esc_html( self::period( $data ) ) . '</p></div>'
			. Ui::link( Value::link( $project, 'url' ), __( 'Open in RankSphere', 'ranksphere' ), 'rs-button rs-button-outline' ) . '</div>';

		foreach ( Value::texts( $data, 'data_problems' ) as $problem ) {
			$html .= '<div class="rs-grid"><div class="rs-span-full">' . Ui::note( 'watch', $problem ) . '</div></div>';
		}

		$first = null !== $top
			? Ui::tile(
				__( 'Next step', 'ranksphere' ),
				'target',
				( '' !== Value::text( $top, 'why' ) ? '<p>' . esc_html( Value::text( $top, 'why' ) ) . '</p>' : '' )
				. '<div class="rs-actions">' . Ui::pill( Value::text( $top, 'area' ), 'primary' ) . Ui::link( Value::link( $top, 'url' ), __( 'Open in RankSphere', 'ranksphere' ) ) . '</div>',
				array(
					'span'  => 'focus',
					'tone'  => 'focus',
					'title' => Value::text( $top, 'title' ),
				)
			)
			: Ui::tile( __( 'Next step', 'ranksphere' ), 'target', Ui::verdict( 'good', __( 'Nothing urgent right now.', 'ranksphere' ) ), array( 'span' => 'focus' ) );

		$state = Ui::tile(
			__( 'Overall', 'ranksphere' ),
			'sparkles',
			'<p class="rs-tile-title">' . esc_html( Value::text( $verdict, 'label' ) ) . '</p>' . Ui::verdict( Value::text( $verdict, 'tone' ), Value::text( $verdict, 'text' ) ),
			array( 'span' => 'side' )
		);

		return $html . Ui::grid( $first . $state . self::figures( $data, 'quarter' ) )
			. Ui::grid(
				Ui::tile( __( 'Next steps', 'ranksphere' ), 'list-checks', $this->tasks( $data ), array( 'span' => 'half' ) )
				. Ui::tile(
					__( 'Texts', 'ranksphere' ),
					'file-text',
					$this->texts( $data ),
					array(
						'span'  => 'half',
						'aside' => current_user_can( TextsPage::CAPABILITY ) ? '<a class="rs-button rs-button-small" href="' . esc_url( TextsPage::url() ) . '">' . Ui::icon( 'plus' ) . esc_html__( 'New text', 'ranksphere' ) . '</a>' : '',
					)
				)
				. Ui::tile( __( 'How we write', 'ranksphere' ), 'pen-line', $this->voice( $data ), array( 'span' => 'full' ) )
			);
	}

	/**
	 * The figure tiles: clicks, impressions, position, AI mentions.
	 *
	 * @param array<mixed> $data RankSphere's answer.
	 * @param string       $span Tile width.
	 */
	public static function figures( array $data, string $span ): string {
		$search   = Value::map( $data, 'search' );
		$previous = null === $search ? null : Value::map( $search, 'previous' );
		$ai       = Value::map( $data, 'ai' );
		$tiles    = '';

		if ( null === $search ) {
			return Ui::tile( __( 'Google', 'ranksphere' ), 'search', Ui::note( 'neutral', __( 'RankSphere has no figures for this site yet. They appear once Google Search Console is connected in RankSphere.', 'ranksphere' ) ), array( 'span' => 'full' ) );
		}

		$items = array(
			array( __( 'Clicks from Google', 'ranksphere' ), 'pointer', 'clicks', false ),
			array( __( 'Impressions on Google', 'ranksphere' ), 'eye', 'impressions', false ),
			array( __( 'Average position', 'ranksphere' ), 'target', 'position', true ),
		);

		foreach ( $items as list( $label, $icon, $key, $position ) ) {
			$now    = Value::number( $search, $key );
			$before = null === $previous ? null : Value::number( $previous, $key );
			$value  = null === $now ? '–' : ( $position ? number_format_i18n( $now, 1 ) : Value::count( $now ) );
			$tiles .= Ui::tile( $label, $icon, Ui::figure( $value ) . Ui::delta( $now, $before, $position, $position ), array( 'span' => $span ) );
		}

		$rate   = null === $ai ? null : Value::number( $ai, 'mention_rate' );
		$tiles .= Ui::tile(
			__( 'Named in AI answers', 'ranksphere' ),
			'sparkles',
			null !== $rate
				? Ui::figure( Value::percent( $rate ) ) . '<p class="rs-muted rs-small">' . esc_html__( 'of the questions RankSphere asks ChatGPT, Gemini & Co.', 'ranksphere' ) . '</p>'
				: '<p class="rs-muted">' . esc_html__( 'Not measured yet.', 'ranksphere' ) . '</p>',
			array( 'span' => $span )
		);

		return $tiles;
	}

	/**
	 * The most important open tasks.
	 *
	 * @param array<mixed> $data RankSphere's answer.
	 */
	private function tasks( array $data ): string {
		$tasks   = Value::maps( $data, 'tasks' );
		$total   = (int) ( Value::number( $data, 'tasks_total' ) ?? count( $tasks ) );
		$project = Value::map( $data, 'project' ) ?? array();

		if ( array() === $tasks ) {
			return Ui::verdict( 'good', __( 'Nothing urgent right now.', 'ranksphere' ) );
		}

		$items = '';

		foreach ( $tasks as $task ) {
			$impact = Value::text( $task, 'impact' );
			$items .= '<li><div class="rs-list-main"><span class="rs-list-title">' . esc_html( Value::text( $task, 'title' ) ) . '</span>'
				. ( '' !== Value::text( $task, 'why' ) ? '<span class="rs-muted">' . esc_html( Value::text( $task, 'why' ) ) . '</span>' : '' )
				. '<span class="rs-meta">' . Ui::pill( Value::text( $task, 'area' ), 'high' === $impact ? 'act' : ( 'medium' === $impact ? 'watch' : 'neutral' ) ) . '</span></div></li>';
		}

		$more = $total - count( $tasks );

		return '<ul class="rs-list">' . $items . '</ul><div class="rs-actions">' . Ui::link(
			Value::link( $project, 'url' ),
			$more > 0
				/* translators: %d: number of further tasks. */
				? sprintf( _n( '%d more step in RankSphere', '%d more steps in RankSphere', $more, 'ranksphere' ), $more )
				: __( 'All steps in RankSphere', 'ranksphere' )
		) . '</div>';
	}

	/**
	 * The latest texts – opened on the page "Texts" here in WordPress.
	 *
	 * @param array<mixed> $data RankSphere's answer.
	 */
	private function texts( array $data ): string {
		$texts = Value::maps( $data, 'texts' );

		if ( array() === $texts ) {
			return '<p class="rs-muted">' . esc_html__( 'No texts yet. Under "Texts" RankSphere writes them for you – in your voice, as WordPress drafts.', 'ranksphere' ) . '</p>';
		}

		$items = '';

		foreach ( $texts as $text ) {
			$id     = (int) ( Value::number( $text, 'id' ) ?? 0 );
			$tone   = Value::text( $text, 'tone' );
			$items .= '<li><div class="rs-list-main">'
				. ( $id > 0 ? '<a class="rs-list-title" href="' . esc_url( TextsPage::url( $id ) ) . '">' . esc_html( Value::text( $text, 'title' ) ) . '</a>' : '<span class="rs-list-title">' . esc_html( Value::text( $text, 'title' ) ) . '</span>' )
				. '<span class="rs-meta">' . Ui::pill( Value::text( $text, 'state' ), '' !== $tone ? $tone : 'neutral' ) . esc_html( Value::text( $text, 'type' ) ) . '</span></div></li>';
		}

		return '<ul class="rs-list">' . $items . '</ul><div class="rs-actions"><a class="rs-link" href="' . esc_url( TextsPage::url() ) . '">' . esc_html__( 'All texts', 'ranksphere' ) . '</a></div>';
	}

	/**
	 * How the company writes – for everyone who writes in WordPress.
	 *
	 * @param array<mixed> $data RankSphere's answer.
	 */
	private function voice( array $data ): string {
		$voice = Value::map( $data, 'voice' );

		if ( null === $voice ) {
			return Ui::note( 'neutral', __( 'The voice is not described in RankSphere yet. RankSphere can work it out from the website.', 'ranksphere' ) )
				. '<div class="rs-actions">' . Ui::link( Value::link( $data, 'voice_url' ), __( 'Describe the voice in RankSphere', 'ranksphere' ) ) . '</div>';
		}

		$lists = array(
			array( __( 'Do', 'ranksphere' ), Value::texts( $voice, 'do' ) ),
			array( __( 'Don’t', 'ranksphere' ), Value::texts( $voice, 'dont' ) ),
			array( __( 'Preferred terms', 'ranksphere' ), Value::texts( $voice, 'preferred_terms' ) ),
			array( __( 'Never use', 'ranksphere' ), Value::texts( $voice, 'taboo_words' ) ),
		);
		$html  = '';

		if ( '' !== Value::text( $voice, 'address' ) ) {
			$html .= '<p>' . Ui::pill(
				/* translators: %s: form of address, e.g. "Sie" or "du". */
				sprintf( __( 'Address readers as: %s', 'ranksphere' ), Value::text( $voice, 'address' ) ),
				'primary'
			) . '</p>';
		}

		if ( '' !== Value::text( $voice, 'voice' ) ) {
			$html .= '<p>' . esc_html( Value::text( $voice, 'voice' ) ) . '</p>';
		}

		$columns = '';

		foreach ( $lists as list( $label, $items ) ) {
			if ( array() !== $items ) {
				$columns .= '<div><h3>' . esc_html( $label ) . '</h3><ul><li>' . implode( '</li><li>', array_map( 'esc_html', $items ) ) . '</li></ul></div>';
			}
		}

		return $html . ( '' !== $columns ? '<div class="rs-voice">' . $columns . '</div>' : '' )
			. '<div class="rs-actions">' . Ui::link( Value::link( $data, 'voice_url' ), __( 'Change in RankSphere', 'ranksphere' ) ) . '</div>';
	}

	/**
	 * When the figures were loaded, and "Refresh".
	 *
	 * @param int $fetched_at Unix time of the answer.
	 */
	private function footer( int $fetched_at ): string {
		$time_format = get_option( 'time_format' );

		return '<form class="rs-meta" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::REFRESH_ACTION ) . '">'
			. wp_nonce_field( self::REFRESH_ACTION, '_wpnonce', true, false )
			. '<span>' . esc_html(
				/* translators: %s: time of day. */
				sprintf( __( 'Loaded from RankSphere at %s.', 'ranksphere' ), (string) wp_date( is_string( $time_format ) && '' !== $time_format ? $time_format : 'H:i', $fetched_at ) )
			) . '</span><button type="submit" class="rs-button-link">' . esc_html__( 'Refresh', 'ranksphere' ) . '</button></form>';
	}

	/**
	 * "Google: 4 Sep to 1 Oct" – the period of the figures.
	 *
	 * @param array<mixed> $data RankSphere's answer.
	 */
	private static function period( array $data ): string {
		$period = Value::map( $data, 'period' ) ?? array();

		if ( '' === Value::text( $period, 'from' ) ) {
			return '';
		}

		/* translators: 1: first day, 2: last day of the period. */
		return sprintf( __( 'Google: %1$s to %2$s (Google reports with two days delay).', 'ranksphere' ), self::date( Value::text( $period, 'from' ) ), self::date( Value::text( $period, 'to' ) ) );
	}

	/**
	 * A date from RankSphere (Y-m-d) in the site's format.
	 *
	 * @param string $date The date.
	 */
	private static function date( string $date ): string {
		$time        = strtotime( $date . ' 12:00:00' );
		$date_format = get_option( 'date_format' );

		return false === $time ? $date : (string) wp_date( is_string( $date_format ) && '' !== $date_format ? $date_format : 'Y-m-d', $time );
	}
}
