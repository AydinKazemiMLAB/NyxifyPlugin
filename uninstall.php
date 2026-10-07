<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: removes its data. Nothing is sent to the core here.
 *
 * @package WooNyx
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

\WooNyx\Uninstaller::run();
