<?php
/**
 * Tests for the encrypted secret store.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Shared\Options;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Shared\Options\Connection;
use Nyxify\Shared\Options\SecretStore;
use Nyxify\Shared\Security\Encryptor;
use Nyxify\Tests\Unit\TestCase;

/**
 * Connection and PIN storage over an in-memory wp_options.
 */
final class SecretStoreTest extends TestCase {

	private const STORE_ID = 'shop_8f2a0c1d3e4b5a69';

	/**
	 * In-memory wp_options: name => value.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Autoload flag passed to update_option(), per option.
	 *
	 * @var array<string, mixed>
	 */
	private array $autoload = array();

	protected function setUp(): void {
		parent::setUp();

		// PHPUnit creates a new instance per test, so both arrays start empty.
		Functions\when( 'get_option' )->alias(
			fn( string $name, $default_value = false ) => $this->options[ $name ] ?? $default_value
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value, $autoload = null ): bool {
				$this->options[ $name ]  = $value;
				$this->autoload[ $name ] = $autoload;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				unset( $this->options[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	private static function secret(): string {
		return 'sec_' . str_repeat( 'ab', 32 );
	}

	private function store( string $site_secret = 'site secret' ): SecretStore {
		return new SecretStore( Encryptor::from_secret( $site_secret ) );
	}

	public function test_connection_round_trip_is_encrypted_and_not_autoloaded(): void {
		$store = $this->store();

		self::assertTrue( $store->save_connection( new Connection( self::STORE_ID, self::secret() ) ) );

		$raw = $this->options[ SecretStore::OPTION_CONNECTION ];
		self::assertIsString( $raw );
		self::assertStringNotContainsString( self::secret(), $raw );
		self::assertStringNotContainsString( self::STORE_ID, $raw );
		self::assertFalse( $this->autoload[ SecretStore::OPTION_CONNECTION ] );

		$connection = $store->get_connection();
		self::assertNotNull( $connection );
		self::assertSame( self::STORE_ID, $connection->store_id );
		self::assertSame( self::secret(), $connection->shared_secret );
		self::assertFalse( $store->has_unreadable_connection() );
	}

	public function test_no_connection(): void {
		self::assertNull( $this->store()->get_connection() );
		self::assertFalse( $this->store()->has_unreadable_connection() );
	}

	public function test_connection_written_with_other_salts_is_unreadable(): void {
		$this->store( 'old salts' )->save_connection( new Connection( self::STORE_ID, self::secret() ) );

		$store = $this->store( 'new salts' );

		self::assertNull( $store->get_connection() );
		self::assertTrue( $store->has_unreadable_connection() );
	}

	public function test_connection_with_unexpected_content_is_unreadable(): void {
		$this->options[ SecretStore::OPTION_CONNECTION ] = Encryptor::from_secret( 'site secret' )->encrypt( '{"store_id":"shop_x"}' );

		self::assertNull( $this->store()->get_connection() );
		self::assertTrue( $this->store()->has_unreadable_connection() );
	}

	public function test_delete_connection(): void {
		$store = $this->store();
		$store->save_connection( new Connection( self::STORE_ID, self::secret() ) );

		$store->delete_connection();

		self::assertArrayNotHasKey( SecretStore::OPTION_CONNECTION, $this->options );
		self::assertNull( $store->get_connection() );
	}

	public function test_admin_pin_round_trip(): void {
		$store = $this->store();

		self::assertTrue( $store->save_admin_pin( '042917' ) );

		self::assertStringNotContainsString( '042917', (string) $this->options[ SecretStore::OPTION_ADMIN_PIN ] );
		self::assertFalse( $this->autoload[ SecretStore::OPTION_ADMIN_PIN ] );
		self::assertSame( '042917', $store->get_admin_pin() );

		$store->delete_admin_pin();
		self::assertNull( $store->get_admin_pin() );
	}

	public function test_admin_pin_must_be_six_digits(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->store()->save_admin_pin( '12345' );
	}

	/**
	 * Values the core never returns.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function invalid_connections(): array {
		return array(
			'integer store id'   => array( '1', 'sec_' . str_repeat( 'ab', 32 ) ),
			'uppercase store id' => array( 'shop_8F2A0C1D3E4B5A69', 'sec_' . str_repeat( 'ab', 32 ) ),
			'short secret'       => array( self::STORE_ID, 'sec_abc' ),
			'secret with suffix' => array( self::STORE_ID, 'sec_' . str_repeat( 'ab', 32 ) . "\n" ),
		);
	}

	/**
	 * The connection only accepts the core's formats.
	 *
	 * @param string $store_id      Store id.
	 * @param string $shared_secret Shared secret.
	 */
	#[DataProvider( 'invalid_connections' )]
	public function test_connection_rejects_other_formats( string $store_id, string $shared_secret ): void {
		$this->expectException( \InvalidArgumentException::class );

		new Connection( $store_id, $shared_secret );
	}

	public function test_connection_debug_output_hides_the_secret(): void {
		$dump = print_r( new Connection( self::STORE_ID, self::secret() ), true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r

		self::assertStringNotContainsString( self::secret(), $dump );
		self::assertStringContainsString( self::STORE_ID, $dump );
	}
}
