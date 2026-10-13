<?php
/**
 * Plugin Name:          Nyxify – Shop Bots for WooCommerce
 * Description:          Sell your WooCommerce products through Telegram, Bale and Rubika bots.
 * Version:              1.0.0
 * Requires at least:    6.5
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * Author:               Nyxon
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          nyxify
 * Domain Path:          /languages
 * WC requires at least: 9.0
 * WC tested up to:      11.2.1
 *
 * @package Nyxify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NYXIFY_VERSION', '1.0.0' );
define( 'NYXIFY_PLUGIN_FILE', __FILE__ );

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action( 'admin_notices', 'nyxify_render_missing_autoload_notice' );
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

( new \Nyxify\Plugin( NYXIFY_PLUGIN_FILE ) )->register();

/**
 * Shown when the plugin was copied without its Composer autoloader (a development checkout without `composer install`).
 */
function nyxify_render_missing_autoload_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Nyxify is incomplete: its vendor folder is missing. Reinstall the plugin from its release zip.', 'nyxify' )
	);
}
