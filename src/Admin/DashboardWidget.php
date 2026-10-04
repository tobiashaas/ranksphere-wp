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

		echo '<div class="ranksphere ranksphere-widget">';

		if ( null === $overview['data'] ) {
			echo '<p>' . esc_html( OverviewPage::error_message( $overview['error'] ) ) . '</p>';
		} else {
			$this->render_data( $overview['data'] );
		}

		echo '</div>';
	}

	/**
	 * Figures, the next step and the links.
	 *
	 * @param array<mixed> $data RankSphere's answer.
	 */
	private function render_data( array $data ): void {
		$verdict = Value::map( $data, 'verdict' ) ?? array();
		$task    = Value::maps( $data, 'tasks' )[0] ?? null;

		if ( '' !== Value::text( $verdict, 'label' ) ) {
			printf(
				'<p class="ranksphere-verdict ranksphere-tone-%1$s"><strong>%2$s</strong> %3$s</p>',
				esc_attr( sanitize_key( Value::text( $verdict, 'tone' ) ) ),
				esc_html( Value::text( $verdict, 'label' ) ),
				esc_html( Value::text( $verdict, 'text' ) )
			);
		}

		OverviewPage::render_figures( $data, true );

		if ( null !== $task ) {
			printf(
				'<p class="ranksphere-next"><span class="ranksphere-muted">%1$s</span><br><strong>%2$s</strong></p>',
				esc_html__( 'Next step', 'ranksphere' ),
				esc_html( Value::text( $task, 'title' ) )
			);
		}
		?>
		<p class="ranksphere-widget-links">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . OverviewPage::SLUG ) ); ?>"><?php esc_html_e( 'RankSphere overview', 'ranksphere' ); ?></a>
		</p>
		<?php
	}
}
