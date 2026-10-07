<?php
/**
 * Plugin Name:          WooNyx
 * Description:          Sell your WooCommerce products through Telegram, Bale and Rubika bots.
 * Version:              0.1.0
 * Requires at least:    6.5
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * Author:               Nyxon
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          woonyx
 * Domain Path:          /languages
 * WC requires at least: 9.0
 * WC tested up to:      11.1.2
 *
 * @package WooNyx
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WOONYX_VERSION', '0.1.0' );
define( 'WOONYX_PLUGIN_FILE', __FILE__ );

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action( 'admin_notices', 'woonyx_render_missing_autoload_notice' );
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

( new \WooNyx\Plugin( WOONYX_PLUGIN_FILE ) )->register();

/**
 * Shown when the plugin was copied without its Composer autoloader (a development checkout without `composer install`).
 */
function woonyx_render_missing_autoload_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	// The plugin class that normally loads the translations isn't available without the autoloader.
	load_plugin_textdomain( 'woonyx', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'WooNyx is incomplete: its vendor folder is missing. Reinstall the plugin from its release zip.', 'woonyx' )
	);
}
