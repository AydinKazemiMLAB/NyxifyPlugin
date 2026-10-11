<?php
/**
 * Tests for the warning before the plugin is deactivated.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Settings;

use Brain\Monkey\Functions;
use Nyxify\Features\Settings\DeactivationWarning;
use Nyxify\Shared\Options\Connection;
use Nyxify\Shared\Options\SecretStore;
use Nyxify\Shared\Security\Encryptor;
use Nyxify\Tests\Unit\TestCase;

/**
 * The script is loaded on the plugins list only, and only while the store is connected (always in the network admin).
 */
final class DeactivationWarningTest extends TestCase {

	private const PLUGIN_FILE = '/var/www/wp-content/plugins/nyxify/nyxify.php';

	/**
	 * In-memory wp_options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Whether this is the network admin.
	 *
	 * @var bool
	 */
	private bool $network_admin = false;

	/**
	 * Scripts enqueued: handle => arguments.
	 *
	 * @var array<string, list<mixed>>
	 */
	private array $scripts = array();

	/**
	 * Inline scripts added: handle, code, position.
	 *
	 * @var list<array{string, string, string}>
	 */
	private array $inline = array();

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\when( 'get_option' )->alias(
			fn( string $name, $default_value = false ) => $this->options[ $name ] ?? $default_value
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_json_encode' )->alias(
			fn( $value, int $flags = 0 ) => json_encode( $value, $flags ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		);
		Functions\when( 'is_network_admin' )->alias( fn(): bool => $this->network_admin );
		Functions\when( 'plugin_basename' )->justReturn( 'nyxify/nyxify.php' );
		Functions\when( 'plugins_url' )->alias( fn( string $path ): string => 'https://shop.example.com/wp-content/plugins/nyxify/' . $path );
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( string $handle, ...$args ): void {
				$this->scripts[ $handle ] = array_values( $args );
			}
		);
		Functions\when( 'wp_add_inline_script' )->alias(
			function ( string $handle, string $code, string $position ): bool {
				$this->inline[] = array( $handle, $code, $position );
				return true;
			}
		);
	}

	private function store(): SecretStore {
		return new SecretStore( Encryptor::from_secret( 'site secret' ) );
	}

	private function connect(): void {
		$this->store()->save_connection( new Connection( 'shop_8f2a0c1d3e4b5a69', 'sec_' . str_repeat( 'e5', 32 ) ) );
	}

	private function warning(): DeactivationWarning {
		return new DeactivationWarning( self::PLUGIN_FILE, $this->store() );
	}

	/**
	 * The settings passed to the script.
	 *
	 * @return array<string, mixed>
	 */
	private function settings(): array {
		self::assertCount( 1, $this->inline );
		[ $handle, $code, $position ] = $this->inline[0];
		self::assertSame( 'nyxify-plugins', $handle );
		self::assertSame( 'before', $position );
		$json     = 1 === preg_match( '/^window\.nyxifyPlugins = (\{.*\});$/D', $code, $matches ) ? $matches[1] : '';
		$settings = json_decode( $json, true );
		self::assertIsArray( $settings );

		return $settings;
	}

	public function test_register_hooks_the_script_loader(): void {
		$warning = $this->warning();

		$warning->register();

		self::assertNotFalse( has_action( 'admin_enqueue_scripts', array( $warning, 'enqueue_assets' ) ) );
	}

	public function test_connected_loads_the_script_on_the_plugins_list(): void {
		$this->connect();

		$this->warning()->enqueue_assets( 'plugins.php' );

		self::assertSame(
			array(
				'https://shop.example.com/wp-content/plugins/nyxify/assets/js/plugins.js',
				array(),
				NYXIFY_VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				),
			),
			$this->scripts['nyxify-plugins']
		);
		self::assertSame(
			array(
				'basename' => 'nyxify/nyxify.php',
				'message'  => 'Deactivating Nyxify stops your shop’s bots. To connect again you will need your license key.',
			),
			$this->settings()
		);
	}

	public function test_the_inline_settings_cannot_close_the_script_tag(): void {
		$this->connect();
		Functions\when( '__' )->justReturn( '</script><script>alert("x")</script> & \'' );

		$this->warning()->enqueue_assets( 'plugins.php' );

		$code = $this->inline[0][1];
		self::assertStringNotContainsString( '<', $code );
		self::assertStringNotContainsString( '"x"', $code );
		self::assertSame( '</script><script>alert("x")</script> & \'', $this->settings()['message'] );
	}

	public function test_nothing_on_other_pages(): void {
		$this->connect();

		$this->warning()->enqueue_assets( 'index.php' );
		$this->warning()->enqueue_assets( 'woocommerce_page_nyxify' );

		self::assertSame( array(), $this->scripts );
		self::assertSame( array(), $this->inline );
	}

	public function test_nothing_while_not_connected(): void {
		$this->warning()->enqueue_assets( 'plugins.php' );

		self::assertSame( array(), $this->scripts );
	}

	public function test_nothing_with_an_unreadable_connection(): void {
		$this->options[ SecretStore::OPTION_CONNECTION ] = 'v1:garbage';

		$this->warning()->enqueue_assets( 'plugins.php' );

		self::assertSame( array(), $this->scripts );
	}

	public function test_always_on_the_network_admin_plugins_list(): void {
		$this->network_admin = true;

		$this->warning()->enqueue_assets( 'plugins.php' );

		self::assertArrayHasKey( 'nyxify-plugins', $this->scripts );
		self::assertSame( 'nyxify/nyxify.php', $this->settings()['basename'] );
	}
}
