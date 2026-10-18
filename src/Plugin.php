<?php
/**
 * Plugin bootstrap.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Nyxify\Features\Bots\AdminPinHandler;
use Nyxify\Features\Bots\AdminPinIssuer;
use Nyxify\Features\Bots\AdminRemovalHandler;
use Nyxify\Features\Bots\AdminService;
use Nyxify\Features\Bots\BotRegistrationHandler;
use Nyxify\Features\Bots\BotRemovalHandler;
use Nyxify\Features\Bots\BotService;
use Nyxify\Features\Bots\BotToken;
use Nyxify\Features\Catalog\CatalogFlush;
use Nyxify\Features\Catalog\CatalogSender;
use Nyxify\Features\Catalog\CatalogSyncHandler;
use Nyxify\Features\Catalog\ChangeCollector;
use Nyxify\Features\Catalog\FlushScheduler;
use Nyxify\Features\Catalog\PendingChanges;
use Nyxify\Features\Catalog\PlanLimitNotice;
use Nyxify\Features\Catalog\Schema;
use Nyxify\Features\Orders\OrderCollector;
use Nyxify\Features\Settings\DeactivationWarning;
use Nyxify\Features\Settings\SettingsPage;
use Nyxify\Features\Store\ActivationHandler;
use Nyxify\Features\Store\Activator;
use Nyxify\Features\Store\Deactivator;
use Nyxify\Features\Store\LicenseKey;
use Nyxify\Features\Store\SecretRotationHandler;
use Nyxify\Features\Store\SecretRotator;
use Nyxify\Features\Store\StatusRefreshHandler;
use Nyxify\Features\Store\StatusService;
use Nyxify\Features\Sync\PullScheduler;
use Nyxify\Features\Sync\SyncPull;
use Nyxify\Shared\Logging\Logger;
use Nyxify\Shared\Http\CoreClient;
use Nyxify\Shared\Options\SecretStore;
use Nyxify\Shared\Security\Encryptor;

/**
 * Wires the plugin into WordPress and WooCommerce.
 */
final class Plugin {

	/**
	 * Oldest WooCommerce version the plugin runs on (keep in sync with the "WC requires at least" header).
	 */
	public const MIN_WOOCOMMERCE_VERSION = '9.0';

	/**
	 * Absolute path of the main plugin file.
	 *
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file Absolute path of the main plugin file.
	 */
	public function __construct( string $plugin_file ) {
		$this->plugin_file = $plugin_file;
	}

	/**
	 * Registers the hooks that run before WooCommerce is known to be available.
	 */
	public function register(): void {
		add_action( 'before_woocommerce_init', array( $this, 'declare_woocommerce_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'boot' ) );
		register_activation_hook( $this->plugin_file, array( $this, 'activate' ) );
		register_deactivation_hook( $this->plugin_file, array( $this, 'deactivate' ) );
	}

	/**
	 * Creates the current site's catalog queue table. Other sites of a network get theirs on their next request
	 * (boot()), as do sites updated by upload, which doesn't run this hook.
	 */
	public function activate(): void {
		Schema::maybe_install();
	}

	/**
	 * Tells the core the plugin was deactivated and, once it confirms, forgets the connection (see Deactivator). Runs
	 * whether or not WooCommerce is active, and never throws.
	 *
	 * @param bool $network_wide Whether the plugin is being deactivated for the whole network.
	 */
	public function deactivate( bool $network_wide = false ): void {
		Deactivator::run( $network_wide, plugin_basename( $this->plugin_file ) );
	}

	/**
	 * Declares compatibility with High-Performance Order Storage: the plugin only uses the WooCommerce CRUD.
	 */
	public function declare_woocommerce_compatibility(): void {
		if ( class_exists( FeaturesUtil::class ) ) {
			FeaturesUtil::declare_compatibility( 'custom_order_tables', $this->plugin_file, true );
		}
	}

	/**
	 * Starts the features once every plugin is loaded, or explains why it can't.
	 */
	public function boot(): void {
		// Deactivating stops the bots whether or not WooCommerce is active, so the warning doesn't wait for it.
		if ( is_admin() ) {
			( new DeactivationWarning( $this->plugin_file, new SecretStore( Encryptor::for_site() ) ) )->register();
		}

		if ( ! self::woocommerce_is_ready() ) {
			add_action( 'admin_notices', array( $this, 'render_woocommerce_notice' ) );
			return;
		}

		Schema::maybe_install();
		// Order and catalog changes come from every kind of request: the admin, REST, checkout, cron and WP-CLI.
		$changes = new PendingChanges( new FlushScheduler(), new Logger() );
		$changes->register();
		( new OrderCollector( $changes ) )->register();
		( new ChangeCollector( $changes ) )->register();
		add_action( FlushScheduler::HOOK, array( CatalogFlush::class, 'run_for_site' ) );
		// The pull fallback (contract §5.2): the recurring pull and the immediate one run the same pull.
		add_action( PullScheduler::HOOK, array( SyncPull::class, 'run_for_site' ) );
		add_action( PullScheduler::NOW_HOOK, array( SyncPull::class, 'run_for_site' ) );

		if ( is_admin() ) {
			$store    = new SecretStore( Encryptor::for_site() );
			$core_url = CoreClient::configured_base_url();
			$client   = new CoreClient( $store, $core_url );
			$logger   = new Logger();

			( new SettingsPage( $this->plugin_file, $store, $core_url, new StatusService( $client, $logger ), new BotService( $client, $logger ), new AdminService( $client, $logger ) ) )->register();
			( new StatusRefreshHandler() )->register();
			add_action( 'admin_init', array( PullScheduler::class, 'ensure_for_site' ) );
			( new PlanLimitNotice() )->register();
			// The services behind the forms are built only when a form is posted.
			( new ActivationHandler( static fn( LicenseKey $license, int $user_id ) => Activator::for_site()->activate( $license, $user_id ) ) )->register();
			( new SecretRotationHandler( static fn() => SecretRotator::for_site()->rotate() ) )->register();
			( new BotRegistrationHandler( static fn( string $platform, BotToken $token ) => BotService::for_site()->register( $platform, $token ) ) )->register();
			( new BotRemovalHandler( static fn( string $platform ) => BotService::for_site()->remove( $platform ) ) )->register();
			( new AdminPinHandler( static fn( bool $revoke_admins ) => AdminPinIssuer::for_site()->issue( $revoke_admins ) ) )->register();
			( new AdminRemovalHandler( static fn( int $id ) => AdminService::for_site()->revoke( $id ) ) )->register();
			( new CatalogSyncHandler( static fn() => CatalogSender::for_site()->request_sync(), $logger ) )->register();
		}
	}

	/**
	 * Whether a supported WooCommerce is active.
	 */
	public static function woocommerce_is_ready(): bool {
		return class_exists( 'WooCommerce' )
			&& function_exists( 'WC' )
			&& version_compare( WC()->version, self::MIN_WOOCOMMERCE_VERSION, '>=' );
	}

	/**
	 * Admin notice shown while WooCommerce is missing, inactive or too old, on the Plugins screen only (guideline 11).
	 */
	public function render_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) || ! self::on_plugins_screen() ) {
			return;
		}

		$message = sprintf(
			/* translators: %s: the oldest supported WooCommerce version, e.g. 9.0 */
			__( 'Nyxify needs WooCommerce %s or later. Install, activate or update WooCommerce to use it.', 'nyxify' ),
			self::MIN_WOOCOMMERCE_VERSION
		);

		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
	}

	/**
	 * Whether the current admin screen is the Plugins screen (of a site or of the network).
	 */
	public static function on_plugins_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();
		return null !== $screen && 'plugins' === $screen->base;
	}
}
