<?php
/**
 * The RankSphere widget on the dashboard.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Admin;

use RankSphere\Connection\ConnectionStore;
use RankSphere\Insights\Insights;
use RankSphere\Insights\Value;

/**
 * The figures and the next step, for everyone who writes – only while the site is connected, and
 * hidden like any other widget under "Screen Options".
 */
final class DashboardWidget {

	public const ID = 'ranksphere_dashboard';

	/**
	 * Takes the connection store.
	 *
	 * @param ConnectionStore $store Where the connection lives.
	 */
	public function __construct( private readonly ConnectionStore $store = new ConnectionStore() ) {}

	/**
	 * Hooks the widget.
	 */
	public function register(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'add' ) );
	}

	/**
	 * Adds the widget for people who may see the overview.
	 */
	public function add(): void {
		if ( null === $this->store->get() || ! current_user_can( OverviewPage::CAPABILITY ) ) {
			return;
		}

		wp_add_dashboard_widget( self::ID, __( 'RankSphere', 'ranksphere' ), array( $this, 'render' ) );
	}

	/**
	 * Renders the widget.
	 */
	public function render(): void {
		$overview = ( new Insights( $this->store ) )->overview();

		if ( null === $overview ) {
			return;
		}

		Ui::out(
			'<div class="ranksphere rs-widget">'
			. ( null === $overview['data'] ? Ui::note( 'watch', OverviewPage::error_message( $overview['error'] ) ) : self::content( $overview['data'] ) )
			. '</div>'
		);
	}

	/**
	 * Verdict, four small figures, the next step, the links.
	 *
	 * @param array<mixed> $data RankSphere's answer.
	 */
	private static function content( array $data ): string {
		$verdict  = Value::map( $data, 'verdict' ) ?? array();
		$task     = Value::maps( $data, 'tasks' )[0] ?? null;
		$search   = Value::map( $data, 'search' );
		$previous = null === $search ? null : Value::map( $search, 'previous' );
		$ai       = Value::map( $data, 'ai' );
		$html     = '' !== Value::text( $verdict, 'label' ) ? Ui::verdict( Value::text( $verdict, 'tone' ), Value::text( $verdict, 'label' ) . ' – ' . Value::text( $verdict, 'text' ) ) : '';
		$minis    = '';

		if ( null !== $search ) {
			foreach ( array(
				array( __( 'Clicks', 'ranksphere' ), 'pointer', 'clicks' ),
				array( __( 'Impressions', 'ranksphere' ), 'eye', 'impressions' ),
			) as list( $label, $icon, $key ) ) {
				$now    = Value::number( $search, $key );
				$minis .= self::mini( $label, $icon, null === $now ? '–' : Value::count( $now ), Ui::delta( $now, null === $previous ? null : Value::number( $previous, $key ) ) );
			}

			$position = Value::number( $search, 'position' );
			$minis   .= self::mini( __( 'Average position', 'ranksphere' ), 'target', null === $position ? '–' : number_format_i18n( $position, 1 ), '' );
		}

		$rate   = null === $ai ? null : Value::number( $ai, 'mention_rate' );
		$minis .= self::mini( __( 'Named in AI answers', 'ranksphere' ), 'sparkles', null === $rate ? '–' : Value::percent( $rate ), '' );
		$html  .= '<div class="rs-mini-figures">' . $minis . '</div>';

		if ( null !== $task ) {
			$html .= '<div class="rs-next"><p class="rs-next-label">' . esc_html__( 'Next step', 'ranksphere' ) . '</p><p><strong>' . esc_html( Value::text( $task, 'title' ) ) . '</strong></p></div>';
		}

		return $html . '<p class="rs-actions"><a class="rs-link" href="' . esc_url( admin_url( 'admin.php?page=' . OverviewPage::SLUG ) ) . '">' . esc_html__( 'RankSphere overview', 'ranksphere' ) . '</a>'
			. ( current_user_can( TextsPage::CAPABILITY ) ? '<a class="rs-link" href="' . esc_url( TextsPage::url() ) . '">' . esc_html__( 'Write a text', 'ranksphere' ) . '</a>' : '' ) . '</p>';
	}

	/**
	 * One small figure.
	 *
	 * @param string $label Its name.
	 * @param string $icon  Icon key.
	 * @param string $value Formatted value.
	 * @param string $delta The change (HTML) or ''.
	 */
	private static function mini( string $label, string $icon, string $value, string $delta ): string {
		return '<div class="rs-mini"><span class="rs-mini-label">' . Ui::icon( $icon ) . esc_html( $label ) . '</span><span class="rs-figure">' . esc_html( $value ) . '</span>' . $delta . '</div>';
	}
}
