<?php
/**
 * PHPUnit bootstrap: unit tests run without WordPress; Brain Monkey stands in for its functions and hooks.
 *
 * @package Nyxify
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'NYXIFY_VERSION' ) ) {
	define( 'NYXIFY_VERSION', '0.0.0-test' );
}
