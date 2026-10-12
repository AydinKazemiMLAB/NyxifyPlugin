<?php
/**
 * The plugin's settings page under WooCommerce.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Settings;

use Nyxify\Features\Bots\AdminPinHandler;
use Nyxify\Features\Bots\AdminRemovalHandler;
use Nyxify\Features\Bots\AdminService;
use Nyxify\Features\Bots\BotRegistrationHandler;
use Nyxify\Features\Bots\BotRemovalHandler;
use Nyxify\Features\Bots\BotService;
use Nyxify\Features\Catalog\CatalogSyncHandler;
use Nyxify\Features\Catalog\PlanLimitNotice;
use Nyxify\Features\Store\ActivationHandler;
use Nyxify\Features\Store\SecretRotationHandler;
use Nyxify\Features\Store\StatusRefreshHandler;
use Nyxify\Features\Store\StatusService;
use Nyxify\Features\Store\StoreStatus;
use Nyxify\Shared\Admin\AdminAction;
use Nyxify\Shared\Admin\Notice;
use Nyxify\Shared\Admin\Notices;
use Nyxify\Shared\Http\CoreError;
use Nyxify\Shared\Http\ErrorMessages;
use Nyxify\Shared\Options\SecretStore;

/**
 * WooCommerce > Nyxify: a header with the connection's state, the result of the last action and the plan-limit notice
 * whenever one is stored, then:
 *
 * - while not connected (nothing is sent before a license key is entered): the three steps to start and the license
 *   form;
 * - while connected: the store's status on the core (fetched once per page load and shared by the sections) in four
 *   cards, the bots and the bot admins (only while that status can be read), and aside the admin PIN and the connection
 *   (store id, server, license, "Check connection", "Rotate secret" and the license form folded into "Activate
 *   again"), then the deactivation warning.
 */
final class SettingsPage {

	public const PAGE_SLUG = 'nyxify';

	public const STYLE_HANDLE = 'nyxify-admin';

	public const SCRIPT_HANDLE = 'nyxify-admin';

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
	 * Adds WooCommerce > Nyxify.
	 */
	public function add_menu(): void {
		$hook_suffix = add_submenu_page(
			'woocommerce',
			__( 'Nyxify', 'nyxify' ),
			__( 'Nyxify', 'nyxify' ),
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

		$settings = sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'nyxify' ) );

		return array( 'nyxify-settings' => $settings ) + $links;
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

		wp_enqueue_style( self::STYLE_HANDLE, plugins_url( 'assets/css/admin.css', $this->plugin_file ), array(), NYXIFY_VERSION );
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/js/admin.js', $this->plugin_file ),
			array(),
			NYXIFY_VERSION,
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
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'nyxify' ), '', array( 'response' => 403 ) );
		}

		$connection = $this->store->get_connection();
		$status     = null === $connection ? null : $this->status_service->get();

		?>
		<div class="wrap nyxify-settings">
			<?php $this->render_header( $status ); ?>
			<hr class="wp-header-end" />
			<?php $this->render_notice(); ?>
			<?php PlanLimitNotice::render( false ); ?>

			<?php if ( null === $connection || null === $status ) : ?>
				<?php $this->render_setup(); ?>
			<?php else : ?>
				<?php $this->status_section->render( $status ); ?>
				<div class="nyxify-layout">
					<div class="nyxify-layout__main">
						<?php if ( $status instanceof StoreStatus ) : ?>
							<?php $this->bots_section->render( $status ); ?>
							<?php $this->admins_section->render( $status ); ?>
						<?php else : ?>
							<section class="nyxify-card">
								<p class="nyxify-empty"><?php esc_html_e( 'The bots and the bot admins can be managed here once the store’s status can be read.', 'nyxify' ); ?></p>
							</section>
						<?php endif; ?>
					</div>
					<div class="nyxify-layout__side">
						<?php $this->pin_section->render( $status ); ?>
						<?php $this->render_connection( $connection->store_id, $status ); ?>
					</div>
				</div>
				<div class="nyxify-footer-note nyxify-deactivation-note">
					<?php Markup::icon( 'alert' ); ?>
					<div>
						<p><strong><?php echo esc_html( DeactivationWarning::message() ); ?></strong></p>
						<p><?php echo esc_html( DeactivationWarning::details() ); ?></p>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The header: the logo, the name, what Nyxify does and the connection's state.
	 *
	 * @param StoreStatus|CoreError|null $status The store's status, or null while not connected.
	 */
	private function render_header( StoreStatus|CoreError|null $status ): void {
		?>
		<div class="nyxify-header">
			<span class="nyxify-logo" aria-hidden="true">N</span>
			<div class="nyxify-header__title">
				<h1><?php esc_html_e( 'Nyxify', 'nyxify' ); ?></h1>
				<p><?php esc_html_e( 'Shop bots for WooCommerce on Telegram, Bale and Rubika', 'nyxify' ); ?></p>
			</div>
			<div class="nyxify-header__end">
				<?php
				if ( $status instanceof StoreStatus ) {
					Markup::pill( Markup::OK, __( 'Connected', 'nyxify' ) );
				} elseif ( $status instanceof CoreError ) {
					Markup::pill( Markup::WARNING, __( 'Status unavailable', 'nyxify' ) );
				} elseif ( $this->store->has_unreadable_connection() ) {
					Markup::pill( Markup::ERROR, __( 'Connection lost', 'nyxify' ) );
				} else {
					Markup::pill( Markup::OFF, __( 'Not connected', 'nyxify' ) );
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * While not connected: why, the three steps to start, the license form and the core address.
	 */
	private function render_setup(): void {
		$unreadable = $this->store->has_unreadable_connection();
		$steps      = array(
			array( __( 'Enter your license key', 'nyxify' ), __( 'It connects this shop to Nyxify.', 'nyxify' ) ),
			array( __( 'Connect your bots', 'nyxify' ), __( 'Paste the token each messenger gave you.', 'nyxify' ) ),
			array( __( 'Issue an admin PIN', 'nyxify' ), __( 'Send it to your bot to manage the shop from there.', 'nyxify' ) ),
		);

		?>
		<section class="nyxify-card nyxify-setup" aria-labelledby="nyxify-setup-heading">
			<?php Markup::card_heading( 'connection', __( 'Activate', 'nyxify' ), 'nyxify-setup-heading' ); ?>
			<?php if ( $unreadable ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( ErrorMessages::message( CoreError::CONNECTION_UNREADABLE ) ); ?></p></div>
			<?php else : ?>
				<p class="description"><?php echo esc_html( ErrorMessages::message( CoreError::NOT_ACTIVATED ) ); ?></p>
				<ol class="nyxify-steps">
					<?php foreach ( $steps as $index => $step ) : ?>
						<li<?php echo 0 === $index ? ' aria-current="step"' : ''; ?>><div><strong><?php echo esc_html( $step[0] ); ?></strong> <?php echo esc_html( $step[1] ); ?></div></li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
			<?php $this->render_license_form( false ); ?>
			<p class="nyxify-privacy"><?php Markup::icon( 'lock' ); ?><span><?php esc_html_e( 'Nothing is sent to the Nyxify server until you enter a license key.', 'nyxify' ); ?></span></p>
			<p class="description"><?php esc_html_e( 'Nyxify server:', 'nyxify' ); ?> <?php $this->render_core_url(); ?></p>
		</section>
		<?php
	}

	/**
	 * The connection's card: the store id, the core address, the license, "Check connection", then "Shared secret" and
	 * "Activate again" folded.
	 *
	 * @param string                $store_id The store's id.
	 * @param StoreStatus|CoreError $status   The store's status.
	 */
	private function render_connection( string $store_id, StoreStatus|CoreError $status ): void {
		?>
		<section class="nyxify-card nyxify-connection" aria-labelledby="nyxify-connection-heading">
			<?php Markup::card_heading( 'connection', __( 'Connection', 'nyxify' ), 'nyxify-connection-heading' ); ?>
			<dl class="nyxify-details">
				<dt><?php esc_html_e( 'Store ID', 'nyxify' ); ?></dt>
				<dd><code dir="ltr"><?php echo esc_html( $store_id ); ?></code></dd>
				<dt><?php esc_html_e( 'Nyxify server', 'nyxify' ); ?></dt>
				<dd><?php $this->render_core_url(); ?></dd>
				<?php if ( $status instanceof StoreStatus ) : ?>
					<dt><?php esc_html_e( 'License', 'nyxify' ); ?></dt>
					<dd><?php self::render_license( $status ); ?></dd>
				<?php endif; ?>
			</dl>
			<form method="post" action="<?php echo esc_url( AdminAction::form_url() ); ?>" class="nyxify-refresh-form nyxify-actions">
				<?php AdminAction::render_fields( StatusRefreshHandler::ACTION ); ?>
				<button type="submit" class="button"><?php Markup::icon( 'sync' ); ?><?php esc_html_e( 'Check connection', 'nyxify' ); ?></button>
				<?php if ( $status instanceof StoreStatus ) : ?>
					<span class="description">
						<?php
						/* translators: %s: date and time */
						echo esc_html( sprintf( __( 'Checked %s', 'nyxify' ), DateFormat::timestamp( $status->checked_at ) ) );
						?>
					</span>
				<?php endif; ?>
			</form>
			<div class="nyxify-folds">
				<details class="nyxify-rotate">
					<summary><?php esc_html_e( 'Shared secret', 'nyxify' ); ?></summary>
					<?php $this->render_rotation_form(); ?>
				</details>
				<details class="nyxify-reactivate">
					<summary><?php esc_html_e( 'Activate again', 'nyxify' ); ?></summary>
					<p class="description"><?php esc_html_e( 'Use this if the connection stops working. This site keeps its store ID and gets a new shared secret.', 'nyxify' ); ?></p>
					<?php $this->render_license_form( true ); ?>
				</details>
			</div>
		</section>
		<?php
	}

	/**
	 * The license's prefix and activation date.
	 *
	 * @param StoreStatus $status The store's status.
	 */
	private static function render_license( StoreStatus $status ): void {
		if ( null === $status->license_prefix ) {
			echo '&mdash;';
			return;
		}

		printf( '<code dir="ltr">%s</code>', esc_html( $status->license_prefix ) );
		if ( null !== $status->license_activated_at ) {
			printf(
				'<br /><span class="description">%s</span>',
				/* translators: %s: date */
				esc_html( sprintf( __( 'Activated %s', 'nyxify' ), DateFormat::core_date( $status->license_activated_at, false ) ) )
			);
		}
	}

	/**
	 * The core address, and where it was set when it isn't the default.
	 */
	private function render_core_url(): void {
		printf( '<code dir="ltr">%s</code>', esc_html( $this->core_url ) );
		if ( defined( 'NYXIFY_API_BASE_URL' ) ) {
			printf( '<br /><span class="description">%s</span>', esc_html__( 'Set by NYXIFY_API_BASE_URL in wp-config.php.', 'nyxify' ) );
		}
	}

	/**
	 * The "Rotate secret" button; the script asks for confirmation before the form is sent.
	 */
	private function render_rotation_form(): void {
		?>
		<p class="description"><?php esc_html_e( 'This site signs every request to Nyxify with a shared secret, kept encrypted here and never shown. Rotate it if you think it may have leaked; the store stays connected.', 'nyxify' ); ?></p>
		<form method="post" action="<?php echo esc_url( AdminAction::form_url() ); ?>" class="nyxify-rotate-form" data-nyxify-confirm="<?php echo esc_attr__( 'Replace the shared secret with a new one now? The store stays connected.', 'nyxify' ); ?>">
			<?php AdminAction::render_fields( SecretRotationHandler::ACTION ); ?>
			<p><button type="submit" class="button button-small"><?php esc_html_e( 'Rotate secret', 'nyxify' ); ?></button></p>
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
		<form method="post" action="<?php echo esc_url( AdminAction::form_url() ); ?>" class="nyxify-license-form nyxify-field">
			<?php AdminAction::render_fields( ActivationHandler::ACTION ); ?>
			<label for="nyxify-license-key"><?php esc_html_e( 'License key', 'nyxify' ); ?></label>
			<div class="nyxify-inline">
				<input type="text" id="nyxify-license-key" name="<?php echo esc_attr( ActivationHandler::FIELD ); ?>" class="code" dir="ltr" autocomplete="off" spellcheck="false" required placeholder="NX-XXXXXX-XXXXXX-XXXXXX-XXXXXX" aria-describedby="nyxify-license-key-description" />
				<button type="submit" class="button button-primary"><?php echo esc_html( $connected ? __( 'Activate again', 'nyxify' ) : __( 'Activate', 'nyxify' ) ); ?></button>
			</div>
			<div id="nyxify-license-key-description">
				<p class="description"><?php esc_html_e( 'The key is sent once to the Nyxify server and is not stored on this site.', 'nyxify' ); ?></p>
				<p class="description"><?php esc_html_e( 'Activating creates a WooCommerce REST API key (read/write) for your user account; Nyxify uses it to read your catalog and create orders. If this user or the key is deleted, the store is disconnected.', 'nyxify' ); ?></p>
			</div>
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
				esc_html__( 'Invalid fields:', 'nyxify' ),
				esc_html( implode( ', ', $notice->fields ) )
			);
		}
		if ( '' !== $notice->request_id ) {
			printf(
				'<p class="description">%s <code dir="ltr">%s</code></p>',
				esc_html__( 'Request ID (for Nyxify support):', 'nyxify' ),
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
			ActivationHandler::ACTIVATED       => __( 'The store is connected to Nyxify.', 'nyxify' ),
			SecretRotationHandler::ROTATED     => __( 'The shared secret was replaced with a new one. The store stays connected.', 'nyxify' ),
			BotRegistrationHandler::REGISTERED => __( 'The bot token was accepted. Nyxify is setting the bot up; check its status below.', 'nyxify' ),
			BotRemovalHandler::REMOVED         => __( 'The bot was removed. It no longer answers your customers.', 'nyxify' ),
			AdminPinHandler::ISSUED            => __( 'A new admin PIN was issued; the previous one no longer works. Bot admins stay admins.', 'nyxify' ),
			AdminPinHandler::ISSUED_REVOKED    => __( 'A new admin PIN was issued and every bot admin was logged out.', 'nyxify' ),
			AdminRemovalHandler::REVOKED       => __( 'The bot admin was removed. The current PIN still works for them until you issue a new one.', 'nyxify' ),
			CatalogSyncHandler::STARTED        => __( 'Nyxify is reading your catalog again. Check the catalog sync below in a few minutes.', 'nyxify' ),
			CatalogSyncHandler::RUNNING        => __( 'A catalog sync is already running. Check the catalog sync below in a few minutes.', 'nyxify' ),
		);

		return $messages[ $code ] ?? null;
	}
}
