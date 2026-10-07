<?php
/**
 * The plugin's settings page under WooCommerce.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Settings;

use WooNyx\Shared\Admin\AdminAction;
use WooNyx\Shared\Http\CoreError;
use WooNyx\Shared\Http\ErrorMessages;
use WooNyx\Shared\Options\SecretStore;

/**
 * WooCommerce > WooNyx: the connection status (read from the stored options only; the page sends nothing to the
 * core) and the core address. Later phases add the license, bots, PIN and admins forms (see AdminAction).
 */
final class SettingsPage {

	public const PAGE_SLUG = 'woonyx';

	public const STYLE_HANDLE = 'woonyx-admin';

	/**
	 * Absolute path of the main plugin file.
	 *
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * Where the connection is kept.
	 *
	 * @var SecretStore
	 */
	private SecretStore $store;

	/**
	 * The core address (not a secret).
	 *
	 * @var string
	 */
	private string $core_url;

	/**
	 * Hook suffix of the page, once the menu is registered.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param string      $plugin_file Absolute path of the main plugin file.
	 * @param SecretStore $store       Where the connection is kept.
	 * @param string      $core_url    The core address.
	 */
	public function __construct( string $plugin_file, SecretStore $store, string $core_url ) {
		$this->plugin_file = $plugin_file;
		$this->store       = $store;
		$this->core_url    = $core_url;
	}

	/**
	 * Registers the menu, the plugins-list link and the stylesheet.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( $this->plugin_file ), array( $this, 'add_settings_link' ) );
	}

	/**
	 * Adds WooCommerce > WooNyx.
	 */
	public function add_menu(): void {
		$hook_suffix = add_submenu_page(
			'woocommerce',
			__( 'WooNyx', 'woonyx' ),
			__( 'WooNyx', 'woonyx' ),
			AdminAction::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);

		$this->hook_suffix = is_string( $hook_suffix ) ? $hook_suffix : '';
	}

	/**
	 * The page's address.
	 */
	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Puts a "Settings" link first in the plugin's row of the plugins list.
	 *
	 * @param array<int|string, string> $links Action links.
	 * @return array<int|string, string>
	 */
	public function add_settings_link( array $links ): array {
		if ( ! current_user_can( AdminAction::CAPABILITY ) ) {
			return $links;
		}

		$settings = sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'woonyx' ) );

		return array( 'woonyx-settings' => $settings ) + $links;
	}

	/**
	 * Loads the stylesheet on this page only.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( self::STYLE_HANDLE, plugins_url( 'assets/css/admin.css', $this->plugin_file ), array(), WOONYX_VERSION );
	}

	/**
	 * Prints the page.
	 */
	public function render(): void {
		if ( ! current_user_can( AdminAction::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'woonyx' ), '', array( 'response' => 403 ) );
		}

		?>
		<div class="wrap woonyx-settings">
			<h1><?php esc_html_e( 'WooNyx', 'woonyx' ); ?></h1>

			<h2><?php esc_html_e( 'Connection', 'woonyx' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'woonyx' ); ?></th>
						<td><?php $this->render_status(); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'WooNyx server', 'woonyx' ); ?></th>
						<td>
							<code dir="ltr"><?php echo esc_html( $this->core_url ); ?></code>
							<?php if ( defined( 'WOONYX_API_BASE_URL' ) ) : ?>
								<p class="description"><?php esc_html_e( 'Set by WOONYX_API_BASE_URL in wp-config.php.', 'woonyx' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<p class="description"><?php esc_html_e( 'Nothing is sent to the WooNyx server until you enter a license key.', 'woonyx' ); ?></p>
		</div>
		<?php
	}

	/**
	 * The connection state as stored here: connected (with the store id), unreadable, or not connected.
	 */
	private function render_status(): void {
		$connection = $this->store->get_connection();

		if ( null !== $connection ) {
			printf(
				'<span class="woonyx-status woonyx-status--connected">%s</span><p class="description">%s <code dir="ltr">%s</code></p>',
				esc_html__( 'Connected', 'woonyx' ),
				esc_html__( 'Store ID:', 'woonyx' ),
				esc_html( $connection->store_id )
			);
			return;
		}

		$unreadable = $this->store->has_unreadable_connection();

		printf(
			'<span class="woonyx-status %s">%s</span><p class="description">%s</p>',
			esc_attr( $unreadable ? 'woonyx-status--error' : 'woonyx-status--disconnected' ),
			esc_html( $unreadable ? __( 'Connection lost', 'woonyx' ) : __( 'Not connected', 'woonyx' ) ),
			esc_html( ErrorMessages::message( $unreadable ? CoreError::CONNECTION_UNREADABLE : CoreError::NOT_ACTIVATED ) )
		);
	}
}
