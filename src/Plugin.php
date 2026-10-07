<?php
/**
 * Plugin bootstrap.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use WooNyx\Features\Settings\SettingsPage;
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
		if ( ! self::woocommerce_is_ready() ) {
			add_action( 'admin_notices', array( $this, 'render_woocommerce_notice' ) );
			return;
		}

		if ( is_admin() ) {
			( new SettingsPage( $this->plugin_file, new SecretStore( Encryptor::for_site() ), CoreClient::configured_base_url() ) )->register();
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
