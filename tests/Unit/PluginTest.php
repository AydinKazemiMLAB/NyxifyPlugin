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
use Mockery;
use WooNyx\Features\Catalog\Schema;
use WooNyx\Plugin;

/**
 * Bootstrap hooks and the WooCommerce check.
 */
final class PluginTest extends TestCase {

	public function test_register_hooks_compatibility_declaration_and_boot(): void {
		$plugin = new Plugin( '/plugins/woonyx/woonyx.php' );
		Functions\expect( 'register_activation_hook' )->once()->with( '/plugins/woonyx/woonyx.php', array( $plugin, 'activate' ) );
		Functions\expect( 'register_deactivation_hook' )->once()->with( '/plugins/woonyx/woonyx.php', array( $plugin, 'deactivate' ) );

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

	public function test_activation_creates_the_catalog_queue_table_when_missing(): void {
		$wpdb         = Mockery::mock( \wpdb::class );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_charset_collate' )->andReturn( '' );
		$wpdb->shouldReceive( 'esc_like' )->andReturnArg( 0 );
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SHOW TABLES' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_woonyx_catalog_queue' );
		$GLOBALS['wpdb'] = $wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The test's database.
		Functions\when( 'get_option' )->justReturn( false );
		Functions\expect( 'dbDelta' )->once();
		Functions\expect( 'update_option' )->once()->with( Schema::OPTION_VERSION, Schema::VERSION, true );

		try {
			( new Plugin( '/plugins/woonyx/woonyx.php' ) )->activate();
		} finally {
			unset( $GLOBALS['wpdb'] );
		}
	}

	public function test_activation_with_the_current_table_does_nothing(): void {
		Functions\when( 'get_option' )->justReturn( Schema::VERSION );
		Functions\expect( 'dbDelta' )->never();

		( new Plugin( '/plugins/woonyx/woonyx.php' ) )->activate();
	}

	public function test_deactivation_without_a_connection_sends_nothing(): void {
		Functions\when( 'plugin_basename' )->justReturn( 'woonyx/woonyx.php' );
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\expect( 'wp_remote_request' )->never();

		( new Plugin( '/plugins/woonyx/woonyx.php' ) )->deactivate();
	}

	public function test_boot_without_woocommerce_only_adds_the_notice(): void {
		$plugin = new Plugin( '/plugins/woonyx/woonyx.php' );
		Functions\when( 'is_admin' )->justReturn( false );

		Actions\expectAdded( 'admin_notices' )->once()->with( array( $plugin, 'render_woocommerce_notice' ) );

		self::assertFalse( Plugin::woocommerce_is_ready() );
		$plugin->boot();
	}

	public function test_the_deactivation_warning_does_not_wait_for_woocommerce(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_salt' )->justReturn( 'salt' );

		( new Plugin( '/plugins/woonyx/woonyx.php' ) )->boot();

		self::assertFalse( Plugin::woocommerce_is_ready() );
		self::assertNotFalse( has_action( 'admin_enqueue_scripts', 'WooNyx\Features\Settings\DeactivationWarning->enqueue_assets()' ) );
	}
}
