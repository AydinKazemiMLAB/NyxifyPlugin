<?php
/**
 * Tests for the bootstrap.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use WooNyx\Plugin;

/**
 * Bootstrap hooks and the WooCommerce check.
 */
final class PluginTest extends TestCase {

	public function test_register_hooks_compatibility_declaration_and_boot(): void {
		$plugin = new Plugin( '/plugins/woonyx/woonyx.php' );

		$plugin->register();

		self::assertNotFalse( has_action( 'before_woocommerce_init', array( $plugin, 'declare_woocommerce_compatibility' ) ) );
		self::assertNotFalse( has_action( 'plugins_loaded', array( $plugin, 'boot' ) ) );
		self::assertNotFalse( has_action( 'init', array( $plugin, 'load_textdomain' ) ) );
	}

	public function test_load_textdomain_points_at_the_bundled_languages_folder(): void {
		Functions\when( 'plugin_basename' )->justReturn( 'woonyx/woonyx.php' );
		Functions\expect( 'load_plugin_textdomain' )->once()->with( 'woonyx', false, 'woonyx/languages' );

		( new Plugin( '/plugins/woonyx/woonyx.php' ) )->load_textdomain();
	}

	public function test_boot_without_woocommerce_only_adds_the_notice(): void {
		$plugin = new Plugin( '/plugins/woonyx/woonyx.php' );

		Actions\expectAdded( 'admin_notices' )->once()->with( array( $plugin, 'render_woocommerce_notice' ) );

		self::assertFalse( Plugin::woocommerce_is_ready() );
		$plugin->boot();
	}
}
