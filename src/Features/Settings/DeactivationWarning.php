<?php
/**
 * The warning before the plugin is deactivated.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Settings;

use Nyxify\Shared\Options\SecretStore;

/**
 * Asks for confirmation on the plugins list before Nyxify is deactivated (its Deactivate link, and the bulk
 * "Deactivate" with its row checked), while the store is connected; the settings page shows the same text as a note.
 *
 * The script is only loaded on `plugins.php`. On a site's plugins list it needs a readable connection; on the network
 * admin's it is always loaded (checking every site's connection there would cost a decryption per site, and the link
 * only exists while the plugin is network-active).
 */
final class DeactivationWarning {

	public const SCRIPT_HANDLE = 'nyxify-plugins';

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
	 * Constructor.
	 *
	 * @param string      $plugin_file Absolute path of the main plugin file.
	 * @param SecretStore $store       Where the connection is kept.
	 */
	public function __construct( string $plugin_file, SecretStore $store ) {
		$this->plugin_file = $plugin_file;
		$this->store       = $store;
	}

	/**
	 * Registers the script loader.
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * The warning, shown in the confirmation and on the settings page.
	 */
	public static function message(): string {
		return __( 'Deactivating Nyxify stops your shop’s bots. To connect again you will need your license key.', 'nyxify' );
	}

	/**
	 * What deactivating does with the connection, for the settings page.
	 */
	public static function details(): string {
		return __( 'When the plugin is deactivated, Nyxify is told and this site’s connection and WooCommerce REST API key are removed. If the Nyxify server can’t be reached, or your subscription has expired, Nyxify keeps the store connected and both stay on this site until you delete the plugin.', 'nyxify' );
	}

	/**
	 * Loads the confirmation on the plugins list while the store is connected.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'plugins.php' !== $hook_suffix ) {
			return;
		}

		if ( ! is_network_admin() && null === $this->store->get_connection() ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/js/plugins.js', $this->plugin_file ),
			array(),
			NYXIFY_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$settings = wp_json_encode(
			array(
				'basename' => plugin_basename( $this->plugin_file ),
				'message'  => self::message(),
			),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
		);

		wp_add_inline_script( self::SCRIPT_HANDLE, 'window.nyxifyPlugins = ' . (string) $settings . ';', 'before' );
	}
}
