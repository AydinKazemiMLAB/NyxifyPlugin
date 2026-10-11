<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: removes its data. Nothing is sent to the core here.
 *
 * @package Nyxify
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

\Nyxify\Uninstaller::run();
