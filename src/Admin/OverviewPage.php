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
		?>
		<div class="wrap ranksphere">
			<h1><?php esc_html_e( 'RankSphere', 'ranksphere' ); ?></h1>
			<?php
			if ( null === $overview ) {
				$this->render_not_connected();
			} elseif ( null === $overview['data'] ) {
				printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html( self::error_message( $overview['error'] ) ) );
				$this->render_footer( $overview['fetched_at'] );
			} else {
				$this->render_overview( $overview['data'] );
				$this->render_footer( $overview['fetched_at'] );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Why the overview could not be loaded, in words.
	 *
	 * @param string|null $code The stored error code.
	 */
	public static function error_message( ?string $code ): string {
		return match ( true ) {
			'ranksphere_http_401' === $code => __( 'RankSphere no longer accepts this connection. An administrator can connect the site again under RankSphere → Settings.', 'ranksphere' ),
			is_string( $code ) && str_starts_with( $code, 'ranksphere_http_5' ) => __( 'RankSphere is not reachable right now. The overview is loaded again in a few minutes.', 'ranksphere' ),
			default => __( 'The overview from RankSphere could not be loaded. It is tried again in a few minutes.', 'ranksphere' ),
		};
	}

	/**
	 * Not connected: what RankSphere does here, and who can connect it.
	 */
	private function render_not_connected(): void {
		?>
		<p><?php esc_html_e( 'This site is not connected to RankSphere yet. Once connected, this page shows how the site is found on Google and in AI answers, the most important next steps, the texts written in RankSphere and how your company writes.', 'ranksphere' ); ?></p>
		<?php if ( current_user_can( SettingsPage::CAPABILITY ) ) : ?>
			<p><a class="button button-primary" href="<?php echo esc_url( App::connect_url() ); ?>"><?php esc_html_e( 'Connect to RankSphere', 'ranksphere' ); ?></a></p>
		<?php else : ?>
			<p><?php esc_html_e( 'An administrator can connect the site.', 'ranksphere' ); ?></p>
			<?php
		endif;
	}

	/**
	 * The overview itself.
	 *
	 * @param array<mixed> $data RankSphere's answer.
	 */
	private function render_overview( array $data ): void {
		$project = Value::map( $data, 'project' ) ?? array();
		$verdict = Value::map( $data, 'verdict' ) ?? array();
		?>
		<div class="ranksphere-head">
			<div>
				<p class="ranksphere-project"><?php echo esc_html( Value::text( $project, 'name' ) ); ?></p>
				<?php if ( '' !== Value::text( $verdict, 'label' ) ) : ?>
					<p class="ranksphere-verdict ranksphere-tone-<?php echo esc_attr( sanitize_key( Value::text( $verdict, 'tone' ) ) ); ?>">
						<strong><?php echo esc_html( Value::text( $verdict, 'label' ) ); ?></strong>
						<?php echo esc_html( Value::text( $verdict, 'text' ) ); ?>
					</p>
				<?php endif; ?>
			</div>
			<?php self::open_link( Value::link( $project, 'url' ), __( 'Open in RankSphere', 'ranksphere' ), 'button button-primary' ); ?>
		</div>
		<?php
		foreach ( Value::texts( $data, 'data_problems' ) as $problem ) {
			printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html( $problem ) );
		}

		self::render_figures( $data );
		?>
		<div class="ranksphere-columns">
			<div class="ranksphere-card">
				<?php $this->render_tasks( $data ); ?>
			</div>
			<div class="ranksphere-card">
				<?php $this->render_texts( $data ); ?>
			</div>
		</div>
		<div class="ranksphere-card">
			<?php $this->render_voice( $data ); ?>
		</div>
		<?php
	}

	/**
	 * Clicks, impressions, position and AI mentions – the same tiles as the dashboard widget.
	 *
	 * @param array<mixed> $data    RankSphere's answer.
	 * @param bool         $compact Without links and period (dashboard widget).
	 */
	public static function render_figures( array $data, bool $compact = false ): void {
		$search   = Value::map( $data, 'search' );
		$previous = null === $search ? null : Value::map( $search, 'previous' );
		$ai       = Value::map( $data, 'ai' );
		$tiles    = array();

		if ( null !== $search ) {
			$clicks      = Value::number( $search, 'clicks' ) ?? 0.0;
			$impressions = Value::number( $search, 'impressions' ) ?? 0.0;
			$position    = Value::number( $search, 'position' );
			$tiles[]     = array( __( 'Clicks from Google', 'ranksphere' ), Value::count( $clicks ), Value::change( $clicks, null === $previous ? null : Value::number( $previous, 'clicks' ) ), Value::link( $search, 'url' ) );
			$tiles[]     = array( __( 'Impressions on Google', 'ranksphere' ), Value::count( $impressions ), Value::change( $impressions, null === $previous ? null : Value::number( $previous, 'impressions' ) ), Value::link( $search, 'url' ) );
			$tiles[]     = array( __( 'Average position', 'ranksphere' ), null === $position || 0.0 === $position ? '–' : number_format_i18n( $position, 1 ), '', Value::link( $search, 'url' ) );
		}

		if ( null !== $ai && null !== Value::number( $ai, 'mention_rate' ) ) {
			$tiles[] = array( __( 'Named in AI answers', 'ranksphere' ), Value::percent( Value::number( $ai, 'mention_rate' ) ), '', Value::link( $ai, 'url' ) );
		}

		if ( array() === $tiles ) {
			echo '<p>' . esc_html__( 'RankSphere has no figures for this site yet. They appear once Google Search Console is connected in RankSphere.', 'ranksphere' ) . '</p>';

			return;
		}

		$period = Value::map( $data, 'period' ) ?? array();
		?>
		<div class="ranksphere-figures">
			<?php foreach ( $tiles as list( $label, $value, $change, $url ) ) : ?>
				<div class="ranksphere-figure">
					<span class="ranksphere-figure-label"><?php echo esc_html( $label ); ?></span>
					<span class="ranksphere-figure-value"><?php echo esc_html( $value ); ?></span>
					<?php if ( '' !== $change ) : ?>
						<span class="ranksphere-figure-change <?php echo esc_attr( str_starts_with( $change, '+' ) ? 'is-up' : 'is-down' ); ?>">
							<?php
							/* translators: %s: change like "+12 %". */
							echo esc_html( sprintf( __( '%s vs. the 28 days before', 'ranksphere' ), $change ) );
							?>
						</span>
					<?php endif; ?>
					<?php
					if ( ! $compact ) {
						self::open_link( $url, __( 'Details', 'ranksphere' ), 'ranksphere-figure-link' );
					}
					?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php if ( ! $compact && '' !== Value::text( $period, 'from' ) ) : ?>
			<p class="description">
				<?php
				/* translators: 1: first day, 2: last day of the period. */
				echo esc_html( sprintf( __( 'Google: %1$s to %2$s (Google reports with two days delay).', 'ranksphere' ), self::date( Value::text( $period, 'from' ) ), self::date( Value::text( $period, 'to' ) ) ) );
				?>
			</p>
			<?php
		endif;
	}

	/**
	 * The most important open tasks.
	 *
	 * @param array<mixed> $data RankSphere's answer.
	 */
	private function render_tasks( array $data ): void {
		$tasks   = Value::maps( $data, 'tasks' );
		$total   = (int) ( Value::number( $data, 'tasks_total' ) ?? count( $tasks ) );
		$project = Value::map( $data, 'project' ) ?? array();
		?>
		<h2><?php esc_html_e( 'Next steps', 'ranksphere' ); ?></h2>
		<?php if ( array() === $tasks ) : ?>
			<p><?php esc_html_e( 'Nothing urgent right now.', 'ranksphere' ); ?></p>
			<?php
			return;
		endif;
		?>
		<ol class="ranksphere-tasks">
			<?php foreach ( $tasks as $task ) : ?>
				<li>
					<span class="ranksphere-pill ranksphere-impact-<?php echo esc_attr( sanitize_key( Value::text( $task, 'impact' ) ) ); ?>"><?php echo esc_html( Value::text( $task, 'area' ) ); ?></span>
					<strong><?php echo esc_html( Value::text( $task, 'title' ) ); ?></strong>
					<?php if ( '' !== Value::text( $task, 'why' ) ) : ?>
						<span class="ranksphere-muted"><?php echo esc_html( Value::text( $task, 'why' ) ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
		$more = $total - count( $tasks );
		self::open_link(
			Value::link( $project, 'url' ),
			$more > 0
				/* translators: %d: number of further tasks. */
				? sprintf( _n( '%d more step in RankSphere', '%d more steps in RankSphere', $more, 'ranksphere' ), $more )
				: __( 'All steps in RankSphere', 'ranksphere' )
		);
	}

	/**
	 * The latest texts from RankSphere, with the WordPress draft where there is one.
	 *
	 * @param array<mixed> $data RankSphere's answer.
	 */
	private function render_texts( array $data ): void {
		$texts = Value::maps( $data, 'texts' );
		?>
		<h2><?php esc_html_e( 'Texts from RankSphere', 'ranksphere' ); ?></h2>
		<?php if ( array() === $texts ) : ?>
			<p><?php esc_html_e( 'No texts yet. Texts written in RankSphere can be sent here as drafts.', 'ranksphere' ); ?></p>
		<?php else : ?>
			<table class="widefat striped ranksphere-texts">
				<tbody>
					<?php foreach ( $texts as $text ) : ?>
						<?php
						$post_id = (int) ( Value::number( $text, 'wordpress_post_id' ) ?? 0 );
						$edit    = $post_id > 0 && null !== get_post( $post_id ) && current_user_can( 'edit_post', $post_id ) ? get_edit_post_link( $post_id ) : null;
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( Value::text( $text, 'title' ) ); ?></strong><br>
								<span class="ranksphere-muted"><?php echo esc_html( Value::text( $text, 'type' ) . ' · ' . Value::text( $text, 'state' ) ); ?></span>
							</td>
							<td class="ranksphere-actions">
								<?php if ( is_string( $edit ) && '' !== $edit ) : ?>
									<a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit draft', 'ranksphere' ); ?></a>
								<?php else : ?>
									<?php self::open_link( Value::link( $text, 'url' ), __( 'Open in RankSphere', 'ranksphere' ) ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		endif;
		self::open_link( Value::link( $data, 'texts_url' ), __( 'Write a text in RankSphere', 'ranksphere' ) );
	}

	/**
	 * How the company writes – for everyone who writes in WordPress.
	 *
	 * @param array<mixed> $data RankSphere's answer.
	 */
	private function render_voice( array $data ): void {
		$voice = Value::map( $data, 'voice' );
		?>
		<h2><?php esc_html_e( 'How we write', 'ranksphere' ); ?></h2>
		<?php if ( null === $voice ) : ?>
			<p><?php esc_html_e( 'The voice is not described in RankSphere yet. RankSphere can work it out from the website.', 'ranksphere' ); ?></p>
			<?php
			self::open_link( Value::link( $data, 'voice_url' ), __( 'Describe the voice in RankSphere', 'ranksphere' ) );

			return;
		endif;

		$lists = array(
			array( __( 'Do', 'ranksphere' ), Value::texts( $voice, 'do' ) ),
			array( __( 'Don’t', 'ranksphere' ), Value::texts( $voice, 'dont' ) ),
			array( __( 'Preferred terms', 'ranksphere' ), Value::texts( $voice, 'preferred_terms' ) ),
			array( __( 'Never use', 'ranksphere' ), Value::texts( $voice, 'taboo_words' ) ),
		);
		?>
		<?php if ( '' !== Value::text( $voice, 'address' ) ) : ?>
			<p>
				<?php
				/* translators: %s: form of address, e.g. "Sie" or "du". */
				echo esc_html( sprintf( __( 'Address readers as: %s', 'ranksphere' ), Value::text( $voice, 'address' ) ) );
				?>
			</p>
		<?php endif; ?>
		<?php if ( '' !== Value::text( $voice, 'voice' ) ) : ?>
			<p><?php echo esc_html( Value::text( $voice, 'voice' ) ); ?></p>
		<?php endif; ?>
		<div class="ranksphere-voice">
			<?php foreach ( $lists as list( $label, $items ) ) : ?>
				<?php if ( array() !== $items ) : ?>
					<div>
						<h3><?php echo esc_html( $label ); ?></h3>
						<ul>
							<?php foreach ( $items as $item ) : ?>
								<li><?php echo esc_html( $item ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<?php
		self::open_link( Value::link( $data, 'voice_url' ), __( 'Change in RankSphere', 'ranksphere' ) );
	}

	/**
	 * When the figures were loaded, and "Refresh".
	 *
	 * @param int $fetched_at Unix time of the answer.
	 */
	private function render_footer( int $fetched_at ): void {
		$time_format = get_option( 'time_format' );
		?>
		<form class="ranksphere-footer" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::REFRESH_ACTION ); ?>">
			<?php wp_nonce_field( self::REFRESH_ACTION ); ?>
			<span class="ranksphere-muted">
				<?php
				/* translators: %s: time of day. */
				echo esc_html( sprintf( __( 'Loaded from RankSphere at %s.', 'ranksphere' ), (string) wp_date( is_string( $time_format ) && '' !== $time_format ? $time_format : 'H:i', $fetched_at ) ) );
				?>
			</span>
			<button type="submit" class="button-link"><?php esc_html_e( 'Refresh', 'ranksphere' ); ?></button>
		</form>
		<?php
	}

	/**
	 * A link into RankSphere (new tab); nothing without an address.
	 *
	 * @param string $url   The address.
	 * @param string $label The link text.
	 * @param string $classes CSS classes.
	 */
	public static function open_link( string $url, string $label, string $classes = '' ): void {
		if ( '' === $url ) {
			return;
		}

		printf(
			'<a class="%1$s" href="%2$s" target="_blank" rel="noopener">%3$s<span class="screen-reader-text"> %4$s</span></a>',
			esc_attr( $classes ),
			esc_url( $url ),
			esc_html( $label ),
			esc_html__( '(opens in a new tab)', 'ranksphere' )
		);
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
