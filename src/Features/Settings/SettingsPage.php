<?php
/**
 * The plugin's settings page under WooCommerce.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Settings;

use WooNyx\Features\Bots\AdminPinHandler;
use WooNyx\Features\Bots\AdminRemovalHandler;
use WooNyx\Features\Bots\AdminService;
use WooNyx\Features\Bots\BotRegistrationHandler;
use WooNyx\Features\Bots\BotRemovalHandler;
use WooNyx\Features\Bots\BotService;
use WooNyx\Features\Store\ActivationHandler;
use WooNyx\Features\Store\SecretRotationHandler;
use WooNyx\Features\Store\StatusService;
use WooNyx\Shared\Admin\AdminAction;
use WooNyx\Shared\Admin\Notice;
use WooNyx\Shared\Admin\Notices;
use WooNyx\Shared\Http\CoreError;
use WooNyx\Shared\Http\ErrorMessages;
use WooNyx\Shared\Options\SecretStore;

/**
 * WooCommerce > WooNyx: the connection status from the stored options, the core address, the result of the last
 * action, and only while connected (nothing is sent before a license key is entered): the store's status on the core
 * (fetched once per page load and shared by the sections), the bots, the admin PIN, the bot admins, the "Rotate secret"
 * button; then the license
 * form (open while not connected, folded into "Activate again" once connected) and, while connected, the deactivation
 * warning.
 */
final class SettingsPage {

	public const PAGE_SLUG = 'woonyx';

	public const STYLE_HANDLE = 'woonyx-admin';

	public const SCRIPT_HANDLE = 'woonyx-admin';

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
	 * Where the store's status comes from.
	 *
	 * @var StatusService
	 */
	private StatusService $status_service;

	/**
	 * The store's status on the core.
	 *
	 * @var StatusSection
	 */
	private StatusSection $status_section;

	/**
	 * The store's bots.
	 *
	 * @var BotsSection
	 */
	private BotsSection $bots_section;

	/**
	 * The bots' admin PIN.
	 *
	 * @var AdminPinSection
	 */
	private AdminPinSection $pin_section;

	/**
	 * The bots' admins.
	 *
	 * @var AdminsSection
	 */
	private AdminsSection $admins_section;

	/**
	 * Hook suffix of the page, once the menu is registered.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param string        $plugin_file Absolute path of the main plugin file.
	 * @param SecretStore   $store       Where the connection is kept.
	 * @param string        $core_url       The core address.
	 * @param StatusService $status_service The store's status on the core.
	 * @param BotService    $bot_service    The store's bots on the core.
	 * @param AdminService  $admin_service  The store's bot admins on the core.
	 */
	public function __construct( string $plugin_file, SecretStore $store, string $core_url, StatusService $status_service, BotService $bot_service, AdminService $admin_service ) {
		$this->plugin_file    = $plugin_file;
		$this->store          = $store;
		$this->core_url       = $core_url;
		$this->status_service = $status_service;
		$this->status_section = new StatusSection();
		$this->bots_section   = new BotsSection( $bot_service );
		$this->pin_section    = new AdminPinSection( $store );
		$this->admins_section = new AdminsSection( $admin_service );
	}

	/**
	 * Registers the menu, the plugins-list link, the stylesheet and the script.
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
	 * Loads the stylesheet and the script (confirmations before some forms are sent) on this page only.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( self::STYLE_HANDLE, plugins_url( 'assets/css/admin.css', $this->plugin_file ), array(), WOONYX_VERSION );
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/js/admin.js', $this->plugin_file ),
			array(),
			WOONYX_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Prints the page.
	 */
	public function render(): void {
		if ( ! current_user_can( AdminAction::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'woonyx' ), '', array( 'response' => 403 ) );
		}

		$connected = null !== $this->store->get_connection();

		?>
		<div class="wrap woonyx-settings">
			<h1><?php esc_html_e( 'WooNyx', 'woonyx' ); ?></h1>
			<hr class="wp-header-end" />
			<?php $this->render_notice(); ?>

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

			<?php if ( $connected ) : ?>
				<?php $status = $this->status_service->get(); ?>
				<?php $this->status_section->render( $status ); ?>
				<?php $this->bots_section->render( $status ); ?>
				<?php $this->pin_section->render( $status ); ?>
				<?php $this->admins_section->render( $status ); ?>
				<?php $this->render_rotation_form(); ?>
				<details class="woonyx-reactivate">
					<summary><?php esc_html_e( 'Activate again', 'woonyx' ); ?></summary>
					<p class="description"><?php esc_html_e( 'Use this if the connection stops working. This site keeps its store ID and gets a new shared secret.', 'woonyx' ); ?></p>
					<?php $this->render_license_form( $connected ); ?>
				</details>
				<div class="notice notice-warning inline woonyx-deactivation-note">
					<p><strong><?php echo esc_html( DeactivationWarning::message() ); ?></strong></p>
					<p><?php echo esc_html( DeactivationWarning::details() ); ?></p>
				</div>
			<?php else : ?>
				<h2><?php esc_html_e( 'Activate', 'woonyx' ); ?></h2>
				<?php $this->render_license_form( $connected ); ?>
				<p class="description"><?php esc_html_e( 'Nothing is sent to the WooNyx server until you enter a license key.', 'woonyx' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The "Rotate secret" button; the script asks for confirmation before the form is sent.
	 */
	private function render_rotation_form(): void {
		?>
		<h2><?php esc_html_e( 'Shared secret', 'woonyx' ); ?></h2>
		<p class="description"><?php esc_html_e( 'This site signs every request to WooNyx with a shared secret, kept encrypted here and never shown. Rotate it if you think it may have leaked; the store stays connected.', 'woonyx' ); ?></p>
		<form method="post" action="<?php echo esc_url( AdminAction::form_url() ); ?>" class="woonyx-rotate-form" data-woonyx-confirm="<?php echo esc_attr__( 'Replace the shared secret with a new one now? The store stays connected.', 'woonyx' ); ?>">
			<?php AdminAction::render_fields( SecretRotationHandler::ACTION ); ?>
			<p class="submit">
				<button type="submit" class="button"><?php esc_html_e( 'Rotate secret', 'woonyx' ); ?></button>
			</p>
		</form>
		<?php
	}

	/**
	 * The license form; the field is always empty (the key is never stored or echoed back).
	 *
	 * @param bool $connected Whether a connection is stored.
	 */
	private function render_license_form( bool $connected ): void {
		?>
		<form method="post" action="<?php echo esc_url( AdminAction::form_url() ); ?>" class="woonyx-license-form">
			<?php AdminAction::render_fields( ActivationHandler::ACTION ); ?>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="woonyx-license-key"><?php esc_html_e( 'License key', 'woonyx' ); ?></label></th>
						<td>
							<input type="text" id="woonyx-license-key" name="<?php echo esc_attr( ActivationHandler::FIELD ); ?>" class="regular-text code" dir="ltr" autocomplete="off" spellcheck="false" required placeholder="WN-XXXXXX-XXXXXX-XXXXXX-XXXXXX" aria-describedby="woonyx-license-key-description" />
							<div id="woonyx-license-key-description">
								<p class="description"><?php esc_html_e( 'The key is sent once to the WooNyx server and is not stored on this site.', 'woonyx' ); ?></p>
								<p class="description"><?php esc_html_e( 'Activating creates a WooCommerce REST API key (read/write) for your user account; WooNyx uses it to read your catalog and create orders. If this user or the key is deleted, the store is disconnected.', 'woonyx' ); ?></p>
							</div>
						</td>
					</tr>
				</tbody>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php echo esc_html( $connected ? __( 'Activate again', 'woonyx' ) : __( 'Activate', 'woonyx' ) ); ?></button>
			</p>
		</form>
		<?php
	}

	/**
	 * The result of the last action, once: a success, or the failure's message with its invalid fields and request id.
	 */
	private function render_notice(): void {
		$notice = Notices::take();
		if ( null === $notice ) {
			return;
		}

		if ( Notice::SUCCESS === $notice->type ) {
			$message = self::success_message( $notice->code );
			if ( null !== $message ) {
				printf( '<div class="notice notice-success"><p>%s</p></div>', esc_html( $message ) );
			}
			return;
		}

		echo '<div class="notice notice-error">';
		printf( '<p>%s</p>', esc_html( ErrorMessages::message( $notice->code, $notice->status ) ) );
		if ( array() !== $notice->fields ) {
			printf(
				'<p>%s <code dir="ltr">%s</code></p>',
				esc_html__( 'Invalid fields:', 'woonyx' ),
				esc_html( implode( ', ', $notice->fields ) )
			);
		}
		if ( '' !== $notice->request_id ) {
			printf(
				'<p class="description">%s <code dir="ltr">%s</code></p>',
				esc_html__( 'Request ID (for WooNyx support):', 'woonyx' ),
				esc_html( $notice->request_id )
			);
		}
		echo '</div>';
	}

	/**
	 * The message of a success code, or null for an unknown one.
	 *
	 * @param string $code Success code.
	 */
	private static function success_message( string $code ): ?string {
		$messages = array(
			ActivationHandler::ACTIVATED       => __( 'The store is connected to WooNyx.', 'woonyx' ),
			SecretRotationHandler::ROTATED     => __( 'The shared secret was replaced with a new one. The store stays connected.', 'woonyx' ),
			BotRegistrationHandler::REGISTERED => __( 'The bot token was accepted. WooNyx is setting the bot up; check its status below.', 'woonyx' ),
			BotRemovalHandler::REMOVED         => __( 'The bot was removed. It no longer answers your customers.', 'woonyx' ),
			AdminPinHandler::ISSUED            => __( 'A new admin PIN was issued; the previous one no longer works. Bot admins stay admins.', 'woonyx' ),
			AdminPinHandler::ISSUED_REVOKED    => __( 'A new admin PIN was issued and every bot admin was logged out.', 'woonyx' ),
			AdminRemovalHandler::REVOKED       => __( 'The bot admin was removed. The current PIN still works for them until you issue a new one.', 'woonyx' ),
		);

		return $messages[ $code ] ?? null;
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
