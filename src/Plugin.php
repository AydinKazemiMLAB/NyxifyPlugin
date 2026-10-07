<?php
/**
 * Plugin bootstrap.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use WooNyx\Features\Bots\AdminPinHandler;
use WooNyx\Features\Bots\AdminPinIssuer;
use WooNyx\Features\Bots\AdminRemovalHandler;
use WooNyx\Features\Bots\AdminService;
use WooNyx\Features\Bots\BotRegistrationHandler;
use WooNyx\Features\Bots\BotRemovalHandler;
use WooNyx\Features\Bots\BotService;
use WooNyx\Features\Bots\BotToken;
use WooNyx\Features\Settings\DeactivationWarning;
use WooNyx\Features\Settings\SettingsPage;
use WooNyx\Features\Store\ActivationHandler;
use WooNyx\Features\Store\Activator;
use WooNyx\Features\Store\Deactivator;
use WooNyx\Features\Store\LicenseKey;
use WooNyx\Features\Store\SecretRotationHandler;
use WooNyx\Features\Store\SecretRotator;
use WooNyx\Features\Store\StatusRefreshHandler;
use WooNyx\Features\Store\StatusService;
use WooNyx\Shared\Logging\Logger;
use WooNyx\Shared\Http\CoreClient;
use WooNyx\Shared\Options\SecretStore;
use WooNyx\Shared\Security\Encryptor;

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
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( $this, 'boot' ) );
		register_deactivation_hook( $this->plugin_file, array( $this, 'deactivate' ) );
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
	 * Loads the translations shipped in `languages/`. Translations from translate.wordpress.org (in
	 * `wp-content/languages/plugins/`) take precedence; WordPress loads them on its own.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'woonyx', false, dirname( plugin_basename( $this->plugin_file ) ) . '/languages' );
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

		if ( is_admin() ) {
			$store    = new SecretStore( Encryptor::for_site() );
			$core_url = CoreClient::configured_base_url();
			$client   = new CoreClient( $store, $core_url );
			$logger   = new Logger();

			( new SettingsPage( $this->plugin_file, $store, $core_url, new StatusService( $client, $logger ), new BotService( $client, $logger ), new AdminService( $client, $logger ) ) )->register();
			( new StatusRefreshHandler() )->register();
			// The services behind the forms are built only when a form is posted.
			( new ActivationHandler( static fn( LicenseKey $license, int $user_id ) => Activator::for_site()->activate( $license, $user_id ) ) )->register();
			( new SecretRotationHandler( static fn() => SecretRotator::for_site()->rotate() ) )->register();
			( new BotRegistrationHandler( static fn( string $platform, BotToken $token ) => BotService::for_site()->register( $platform, $token ) ) )->register();
			( new BotRemovalHandler( static fn( string $platform ) => BotService::for_site()->remove( $platform ) ) )->register();
			( new AdminPinHandler( static fn( bool $revoke_admins ) => AdminPinIssuer::for_site()->issue( $revoke_admins ) ) )->register();
			( new AdminRemovalHandler( static fn( int $id ) => AdminService::for_site()->revoke( $id ) ) )->register();
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
	 * Admin notice shown while WooCommerce is missing, inactive or too old.
	 */
	public function render_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$message = sprintf(
			/* translators: %s: the oldest supported WooCommerce version, e.g. 9.0 */
			__( 'WooNyx needs WooCommerce %s or later. Install, activate or update WooCommerce to use it.', 'woonyx' ),
			self::MIN_WOOCOMMERCE_VERSION
		);

		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
	}
}
