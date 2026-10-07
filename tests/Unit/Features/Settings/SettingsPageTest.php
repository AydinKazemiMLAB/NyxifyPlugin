<?php
/**
 * Tests for the settings page.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Tests\Unit\Features\Settings;

use Brain\Monkey\Functions;
use WooNyx\Features\Settings\SettingsPage;
use WooNyx\Shared\Options\Connection;
use WooNyx\Shared\Options\SecretStore;
use WooNyx\Shared\Security\Encryptor;
use WooNyx\Tests\Unit\TestCase;

/**
 * Menu, plugins-list link, stylesheet and the page's three connection states.
 */
final class SettingsPageTest extends TestCase {

	private const PLUGIN_FILE = '/plugins/woonyx/woonyx.php';
	private const CORE_URL    = 'https://core.example.com';
	private const HOOK_SUFFIX = 'woocommerce_page_woonyx';

	/**
	 * In-memory wp_options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Whether the current user may manage WooCommerce.
	 *
	 * @var bool
	 */
	private bool $can_manage = true;

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'get_option' )->alias(
			fn( string $name, $default_value = false ) => $this->options[ $name ] ?? $default_value
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'current_user_can' )->alias( fn( string $capability ): bool => 'manage_woocommerce' === $capability && $this->can_manage );
		Functions\when( 'admin_url' )->alias( fn( string $path ): string => 'https://shop.example.com/wp-admin/' . $path );
		Functions\when( 'plugin_basename' )->justReturn( 'woonyx/woonyx.php' );
		Functions\when( 'wp_die' )->alias(
			static function (): void {
				throw new \RuntimeException( 'wp_die' );
			}
		);
		// The page must never call the core.
		Functions\expect( 'wp_remote_request' )->never();
	}

	private function store( string $site_secret = 'site secret' ): SecretStore {
		return new SecretStore( Encryptor::from_secret( $site_secret ) );
	}

	private function page(): SettingsPage {
		return new SettingsPage( self::PLUGIN_FILE, $this->store(), self::CORE_URL );
	}

	private function rendered( SettingsPage $page ): string {
		ob_start();
		$page->render();

		return (string) ob_get_clean();
	}

	public function test_register_hooks(): void {
		$page = $this->page();

		$page->register();

		self::assertNotFalse( has_action( 'admin_menu', array( $page, 'add_menu' ) ) );
		self::assertNotFalse( has_action( 'admin_enqueue_scripts', array( $page, 'enqueue_assets' ) ) );
		self::assertNotFalse( has_filter( 'plugin_action_links_woonyx/woonyx.php', array( $page, 'add_settings_link' ) ) );
	}

	public function test_menu_is_a_woocommerce_submenu_for_shop_managers(): void {
		$page = $this->page();
		Functions\expect( 'add_submenu_page' )
			->once()
			->with( 'woocommerce', 'WooNyx', 'WooNyx', 'manage_woocommerce', 'woonyx', array( $page, 'render' ) )
			->andReturn( self::HOOK_SUFFIX );

		$page->add_menu();
	}

	public function test_settings_link_comes_first(): void {
		$links = $this->page()->add_settings_link( array( 'deactivate' => '<a>Deactivate</a>' ) );

		self::assertSame( array( 'woonyx-settings', 'deactivate' ), array_keys( $links ) );
		self::assertSame( '<a href="https://shop.example.com/wp-admin/admin.php?page=woonyx">Settings</a>', $links['woonyx-settings'] );
	}

	public function test_no_settings_link_without_the_capability(): void {
		$this->can_manage = false;

		self::assertSame( array( 'deactivate' => 'x' ), $this->page()->add_settings_link( array( 'deactivate' => 'x' ) ) );
	}

	public function test_stylesheet_only_on_this_page(): void {
		$page = $this->page();
		Functions\when( 'add_submenu_page' )->justReturn( self::HOOK_SUFFIX );
		Functions\when( 'plugins_url' )->alias( fn( string $path ): string => 'https://shop.example.com/wp-content/plugins/woonyx/' . $path );
		Functions\expect( 'wp_enqueue_style' )
			->once()
			->with( 'woonyx-admin', 'https://shop.example.com/wp-content/plugins/woonyx/assets/css/admin.css', array(), WOONYX_VERSION );

		$page->enqueue_assets( self::HOOK_SUFFIX );
		$page->add_menu();
		$page->enqueue_assets( 'edit.php' );
		$page->enqueue_assets( self::HOOK_SUFFIX );
	}

	public function test_page_needs_the_capability(): void {
		$this->can_manage = false;
		$this->expectExceptionMessage( 'wp_die' );

		$this->page()->render();
	}

	public function test_not_connected(): void {
		$html = $this->rendered( $this->page() );

		self::assertStringContainsString( 'woonyx-status--disconnected', $html );
		self::assertStringContainsString( 'Not connected', $html );
		self::assertStringContainsString( 'Enter your license key to activate it.', $html );
		self::assertStringContainsString( '<code dir="ltr">https://core.example.com</code>', $html );
		self::assertStringContainsString( 'Nothing is sent to the WooNyx server until you enter a license key.', $html );
		self::assertStringNotContainsString( 'WOONYX_API_BASE_URL', $html );
	}

	public function test_connected_shows_the_store_id_but_not_the_secret(): void {
		$secret = 'sec_' . str_repeat( 'e5', 32 );
		$this->store()->save_connection( new Connection( 'shop_8f2a0c1d3e4b5a69', $secret ) );

		$html = $this->rendered( $this->page() );

		self::assertStringContainsString( 'woonyx-status--connected', $html );
		self::assertStringContainsString( 'shop_8f2a0c1d3e4b5a69', $html );
		self::assertStringNotContainsString( $secret, $html );
	}

	public function test_unreadable_connection_asks_to_activate_again(): void {
		$this->store( 'old salts' )->save_connection( new Connection( 'shop_8f2a0c1d3e4b5a69', 'sec_' . str_repeat( 'e5', 32 ) ) );

		$html = $this->rendered( $this->page() );

		self::assertStringContainsString( 'woonyx-status--error', $html );
		self::assertStringContainsString( 'Connection lost', $html );
		self::assertStringContainsString( 'Enter your license key again to reconnect.', $html );
		self::assertStringNotContainsString( 'shop_8f2a0c1d3e4b5a69', $html );
	}

	public function test_core_url_is_escaped(): void {
		$html = $this->rendered( new SettingsPage( self::PLUGIN_FILE, $this->store(), 'https://x.example.com/<script>' ) );

		self::assertStringNotContainsString( '<script>', $html );
	}
}
