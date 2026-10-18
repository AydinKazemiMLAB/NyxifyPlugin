<?php
/**
 * Tests for the bootstrap.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Nyxify\Features\Catalog\Schema;
use Nyxify\Plugin;

/**
 * Bootstrap hooks and the WooCommerce check.
 */
final class PluginTest extends TestCase {

	public function test_register_hooks_compatibility_declaration_and_boot(): void {
		$plugin = new Plugin( '/plugins/nyxify/nyxify.php' );
		Functions\expect( 'register_activation_hook' )->once()->with( '/plugins/nyxify/nyxify.php', array( $plugin, 'activate' ) );
		Functions\expect( 'register_deactivation_hook' )->once()->with( '/plugins/nyxify/nyxify.php', array( $plugin, 'deactivate' ) );

		$plugin->register();

		self::assertNotFalse( has_action( 'before_woocommerce_init', array( $plugin, 'declare_woocommerce_compatibility' ) ) );
		self::assertNotFalse( has_action( 'plugins_loaded', array( $plugin, 'boot' ) ) );
		self::assertFalse( has_action( 'init' ) );
	}

	public function test_activation_creates_the_catalog_queue_table_when_missing(): void {
		$wpdb         = Mockery::mock( \wpdb::class );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_charset_collate' )->andReturn( '' );
		$wpdb->shouldReceive( 'esc_like' )->andReturnArg( 0 );
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SHOW TABLES' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_nyxify_catalog_queue' );
		$GLOBALS['wpdb'] = $wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The test's database.
		Functions\when( 'get_option' )->justReturn( false );
		Functions\expect( 'dbDelta' )->once();
		Functions\expect( 'update_option' )->once()->with( Schema::OPTION_VERSION, Schema::VERSION, true );

		try {
			( new Plugin( '/plugins/nyxify/nyxify.php' ) )->activate();
		} finally {
			unset( $GLOBALS['wpdb'] );
		}
	}

	public function test_activation_with_the_current_table_does_nothing(): void {
		Functions\when( 'get_option' )->justReturn( Schema::VERSION );
		Functions\expect( 'dbDelta' )->never();

		( new Plugin( '/plugins/nyxify/nyxify.php' ) )->activate();
	}

	public function test_deactivation_without_a_connection_sends_nothing(): void {
		Functions\when( 'plugin_basename' )->justReturn( 'nyxify/nyxify.php' );
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\expect( 'wp_remote_request' )->never();

		( new Plugin( '/plugins/nyxify/nyxify.php' ) )->deactivate();
	}

	public function test_boot_without_woocommerce_only_adds_the_notice(): void {
		$plugin = new Plugin( '/plugins/nyxify/nyxify.php' );
		Functions\when( 'is_admin' )->justReturn( false );

		Actions\expectAdded( 'admin_notices' )->once()->with( array( $plugin, 'render_woocommerce_notice' ) );

		self::assertFalse( Plugin::woocommerce_is_ready() );
		$plugin->boot();
	}

	public function test_the_woocommerce_notice_shows_on_the_plugins_screen_only(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		$plugin = new Plugin( '/plugins/nyxify/nyxify.php' );

		foreach ( array( 'dashboard', 'edit', 'woocommerce_page_wc-settings' ) as $base ) {
			Functions\when( 'get_current_screen' )->justReturn( (object) array( 'base' => $base ) );
			ob_start();
			$plugin->render_woocommerce_notice();
			self::assertSame( '', ob_get_clean(), $base );
		}

		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'base' => 'plugins' ) );
		ob_start();
		$plugin->render_woocommerce_notice();
		self::assertStringContainsString( 'Nyxify needs WooCommerce 9.0 or later.', (string) ob_get_clean() );
	}

	public function test_the_woocommerce_notice_needs_a_screen_and_the_capability(): void {
		$plugin = new Plugin( '/plugins/nyxify/nyxify.php' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_screen' )->justReturn( null );
		ob_start();
		$plugin->render_woocommerce_notice();
		self::assertSame( '', ob_get_clean() );

		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'base' => 'plugins' ) );
		ob_start();
		$plugin->render_woocommerce_notice();
		self::assertSame( '', ob_get_clean() );
	}

	public function test_the_deactivation_warning_does_not_wait_for_woocommerce(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_salt' )->justReturn( 'salt' );

		( new Plugin( '/plugins/nyxify/nyxify.php' ) )->boot();

		self::assertFalse( Plugin::woocommerce_is_ready() );
		self::assertNotFalse( has_action( 'admin_enqueue_scripts', 'Nyxify\Features\Settings\DeactivationWarning->enqueue_assets()' ) );
	}
}
