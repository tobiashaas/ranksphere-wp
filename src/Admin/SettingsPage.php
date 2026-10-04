<?php
/**
 * The RankSphere admin page.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Admin;

use RankSphere\Connection\Connection;
use RankSphere\Connection\ConnectionStore;
use RankSphere\Connection\Disconnector;
use RankSphere\Seo\SeoPlugins;
use RankSphere\Support\App;

/**
 * "RankSphere" in the admin menu: connect, see the connection, disconnect. The overview from
 * RankSphere (tasks, voice, drafts) follows in M4.
 */
final class SettingsPage {

	public const SLUG = 'ranksphere';

	/** Only administrators connect the site or see its data. */
	public const CAPABILITY = 'manage_options';

	/** The admin-post action (and nonce action) that disconnects. */
	public const DISCONNECT_ACTION = 'ranksphere_disconnect';

	/**
	 * Takes the connection store.
	 *
	 * @param ConnectionStore $store Where the connection lives.
	 */
	public function __construct( private readonly ConnectionStore $store = new ConnectionStore() ) {}

	/**
	 * Hooks the menu entry and the disconnect handler.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::DISCONNECT_ACTION, array( $this, 'handle_disconnect' ) );
	}

	/**
	 * Adds the top-level menu entry.
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
	}

	/**
	 * "Disconnect" (admin-post.php): nonce and capability, then back to the page.
	 */
	public function handle_disconnect(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage RankSphere.', 'ranksphere' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::DISCONNECT_ACTION );

		( new Disconnector( $this->store ) )->disconnect( ConnectionStore::REASON_ADMIN, true, true );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage RankSphere.', 'ranksphere' ) );
		}

		$connection = $this->store->get();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'RankSphere', 'ranksphere' ); ?></h1>
			<?php
			if ( null === $connection ) {
				$this->render_not_connected();
			} else {
				$this->render_connected( $connection );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Explains what connecting does and starts it in RankSphere.
	 */
	private function render_not_connected(): void {
		$note = $this->store->last_disconnect();

		if ( null !== $note ) {
			printf( '<div class="notice notice-info inline"><p>%s</p></div>', esc_html( $this->disconnect_message( $note['reason'] ) ) );
		}
		?>
		<p><?php esc_html_e( 'This site is not connected to RankSphere yet.', 'ranksphere' ); ?></p>
		<p><?php esc_html_e( 'Once connected, texts written in RankSphere arrive here as drafts and SEO titles and descriptions can be applied with one click. Nothing is sent to RankSphere before you connect.', 'ranksphere' ); ?></p>
		<p><?php esc_html_e( 'RankSphere asks WordPress for an application password. You approve it on the next page and can revoke it at any time under Users → Profile.', 'ranksphere' ); ?></p>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( App::connect_url() ); ?>">
				<?php esc_html_e( 'Connect to RankSphere', 'ranksphere' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Shows the connection and offers to end it.
	 *
	 * @param Connection $connection The connection.
	 */
	private function render_connected( Connection $connection ): void {
		$user        = get_userdata( $connection->user_id );
		$seo_plugin  = SeoPlugins::active();
		$date_format = get_option( 'date_format' );
		?>
		<p><?php esc_html_e( 'This site is connected to RankSphere.', 'ranksphere' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Project', 'ranksphere' ); ?></th>
				<td><a href="<?php echo esc_url( $connection->project_url ); ?>"><?php echo esc_html( $connection->project_name ); ?></a></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Connected since', 'ranksphere' ); ?></th>
				<td><?php echo esc_html( (string) wp_date( is_string( $date_format ) && '' !== $date_format ? $date_format : 'Y-m-d', $connection->connected_at ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Approved by', 'ranksphere' ); ?></th>
				<td><?php echo esc_html( false !== $user ? $user->display_name : '–' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'SEO plugin', 'ranksphere' ); ?></th>
				<td>
					<?php
					echo esc_html(
						null !== $seo_plugin
							? trim( $seo_plugin['name'] . ' ' . $seo_plugin['version'] )
							: __( 'None detected', 'ranksphere' )
					);
					?>
				</td>
			</tr>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::DISCONNECT_ACTION ); ?>">
			<?php wp_nonce_field( self::DISCONNECT_ACTION ); ?>
			<p><?php esc_html_e( 'Disconnecting removes the application password RankSphere uses. Drafts and changes already made stay as they are.', 'ranksphere' ); ?></p>
			<?php submit_button( __( 'Disconnect', 'ranksphere' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Why the last connection ended, in words.
	 *
	 * @param string $reason One of the ConnectionStore::REASON_* constants.
	 */
	private function disconnect_message( string $reason ): string {
		return match ( $reason ) {
			ConnectionStore::REASON_REVOKED      => __( 'The connection ended because the application password for RankSphere was revoked.', 'ranksphere' ),
			ConnectionStore::REASON_USER_DELETED => __( 'The connection ended because the user who approved it was deleted.', 'ranksphere' ),
			ConnectionStore::REASON_RANKSPHERE   => __( 'RankSphere disconnected this site.', 'ranksphere' ),
			default                              => __( 'This site was disconnected from RankSphere.', 'ranksphere' ),
		};
	}
}
