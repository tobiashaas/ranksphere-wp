<?php
/**
 * The RankSphere admin page.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Admin;

use RankSphere\Support\Options;

/**
 * "RankSphere" in the admin menu: the connection status for now; the overview from RankSphere
 * (tasks, voice, drafts) follows once the site is connected.
 */
final class SettingsPage {

	public const SLUG = 'ranksphere';

	/** Only administrators connect the site or see its data. */
	public const CAPABILITY = 'manage_options';

	/**
	 * Hooks the menu entry.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
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
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage RankSphere.', 'ranksphere' ) );
		}

		$connection = get_option( Options::CONNECTION );
		$connected  = is_array( $connection ) && array() !== $connection;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'RankSphere', 'ranksphere' ); ?></h1>
			<p>
				<?php
				echo $connected
					? esc_html__( 'This site is connected to RankSphere.', 'ranksphere' )
					: esc_html__( 'This site is not connected to RankSphere yet.', 'ranksphere' );
				?>
			</p>
		</div>
		<?php
	}
}
