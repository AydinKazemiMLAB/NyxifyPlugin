<?php
/**
 * Tests for the plugin's WooCommerce REST key.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Tests\Unit\Features\Store;

use Brain\Monkey\Functions;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use WooNyx\Features\Store\RestKey;
use WooNyx\Features\Store\RestKeys;
use WooNyx\Tests\Unit\EmulatesPrepare;
use WooNyx\Tests\Unit\TestCase;

/**
 * Key rows like WC_Auth::create_keys(), over a mocked $wpdb and an in-memory wp_options.
 */
final class RestKeysTest extends TestCase {

	use EmulatesPrepare;

	private const TABLE = 'wp_woocommerce_api_keys';

	/**
	 * Mocked database.
	 *
	 * @var \wpdb&MockInterface
	 */
	private $wpdb;

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

	/**
	 * Number of update_option() calls.
	 *
	 * @var int
	 */
	private int $writes = 0;

	protected function setUp(): void {
		parent::setUp();

		$this->wpdb         = Mockery::mock( \wpdb::class );
		$this->wpdb->prefix = 'wp_';
		$GLOBALS['wpdb']    = $this->wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The test's database.

		Functions\when( 'wc_api_hash' )->alias( fn( string $data ): string => hash_hmac( 'sha256', $data, 'wc-api' ) );
		Functions\when( 'get_option' )->alias(
			fn( string $name, $default_value = false ) => $this->options[ $name ] ?? $default_value
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value, $autoload = null ): bool {
				++$this->writes;
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
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Expects one insert, captures its row and formats, and answers with a result and insert id.
	 *
	 * @param array<string, mixed> $row     Receives the inserted row.
	 * @param array<int, string>   $formats Receives the formats.
	 * @param int|false            $result  Value insert() returns.
	 * @param int                  $key_id  Insert id afterwards.
	 */
	private function expect_insert( array &$row, array &$formats, $result = 1, int $key_id = 17 ): void {
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->with( self::TABLE, Mockery::type( 'array' ), Mockery::type( 'array' ) )
			->andReturnUsing(
				function ( string $table, array $data, array $data_formats ) use ( &$row, &$formats, $result, $key_id ) {
					$row                   = $data;
					$formats               = $data_formats;
					$this->wpdb->insert_id = $key_id;
					return $result;
				}
			);
	}

	public function test_create_inserts_a_read_write_key_like_woocommerce(): void {
		$row     = array();
		$formats = array();
		$this->expect_insert( $row, $formats );

		$key = ( new RestKeys() )->create( 5 );

		self::assertInstanceOf( RestKey::class, $key );
		self::assertSame( 17, $key->key_id );
		self::assertMatchesRegularExpression( '/^ck_[a-f0-9]{40}$/D', $key->consumer_key );
		self::assertMatchesRegularExpression( '/^cs_[a-f0-9]{40}$/D', $key->consumer_secret );

		self::assertSame(
			array( 'user_id', 'description', 'permissions', 'consumer_key', 'consumer_secret', 'truncated_key' ),
			array_keys( $row )
		);
		self::assertSame( array( '%d', '%s', '%s', '%s', '%s', '%s' ), $formats );
		self::assertSame( 5, $row['user_id'] );
		self::assertSame( 'read_write', $row['permissions'] );
		self::assertMatchesRegularExpression( '/^WooNyx - API \(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\)$/D', $row['description'] );
		// WooCommerce looks keys up by their hash; only the secret is stored as is.
		self::assertSame( hash_hmac( 'sha256', $key->consumer_key, 'wc-api' ), $row['consumer_key'] );
		self::assertSame( $key->consumer_secret, $row['consumer_secret'] );
		self::assertSame( substr( $key->consumer_key, -7 ), $row['truncated_key'] );
	}

	public function test_every_key_is_new(): void {
		$this->wpdb->shouldReceive( 'insert' )->twice()->andReturn( 1 );
		$this->wpdb->insert_id = 3;

		$keys  = new RestKeys();
		$first = $keys->create( 1 );
		$other = $keys->create( 1 );

		self::assertNotNull( $first );
		self::assertNotNull( $other );
		self::assertNotSame( $first->consumer_key, $other->consumer_key );
		self::assertNotSame( $first->consumer_secret, $other->consumer_secret );
		self::assertNotSame( substr( $first->consumer_key, 3 ), substr( $first->consumer_secret, 3 ) );
	}

	/**
	 * @return array<string, array{int|false, int}>
	 */
	public static function failed_inserts(): array {
		return array(
			'database error' => array( false, 0 ),
			'no row'         => array( 0, 0 ),
			'no insert id'   => array( 1, 0 ),
		);
	}

	/**
	 * @param int|false $result Value insert() returns.
	 * @param int       $key_id Insert id afterwards.
	 */
	#[DataProvider( 'failed_inserts' )]
	public function test_create_returns_null_when_the_insert_fails( $result, int $key_id ): void {
		$row     = array();
		$formats = array();
		$this->expect_insert( $row, $formats, $result, $key_id );

		self::assertNull( ( new RestKeys() )->create( 5 ) );
	}

	public function test_create_needs_a_user(): void {
		$this->wpdb->shouldNotReceive( 'insert' );

		self::assertNull( ( new RestKeys() )->create( 0 ) );
	}

	/**
	 * @return array<string, array{int|false, bool}>
	 */
	public static function delete_results(): array {
		return array(
			'deleted'        => array( 1, true ),
			'already gone'   => array( 0, true ),
			'database error' => array( false, false ),
		);
	}

	/**
	 * @param int|false $result   Value delete() returns.
	 * @param bool      $expected Expected result.
	 */
	#[DataProvider( 'delete_results' )]
	public function test_delete_removes_only_the_given_key( $result, bool $expected ): void {
		$this->wpdb->shouldReceive( 'delete' )
			->once()
			->with( self::TABLE, array( 'key_id' => 17 ), array( '%d' ) )
			->andReturn( $result );

		self::assertSame( $expected, ( new RestKeys() )->delete( 17 ) );
	}

	public function test_delete_ignores_an_invalid_id(): void {
		$this->wpdb->shouldNotReceive( 'delete' );

		self::assertFalse( ( new RestKeys() )->delete( 0 ) );
	}

	public function test_key_id_is_remembered_without_autoload(): void {
		$keys = new RestKeys();

		self::assertNull( $keys->current_id() );
		self::assertTrue( $keys->remember( 17 ) );
		self::assertSame( 17, $this->options[ RestKeys::OPTION_KEY_ID ] );
		self::assertFalse( $this->autoload[ RestKeys::OPTION_KEY_ID ] );
		self::assertSame( 17, $keys->current_id() );
	}

	public function test_remembering_the_same_id_again_succeeds_without_a_write(): void {
		$keys = new RestKeys();
		$keys->remember( 17 );

		self::assertTrue( $keys->remember( 17 ) );
		self::assertSame( 1, $this->writes );
	}

	public function test_invalid_ids_are_not_remembered(): void {
		self::assertFalse( ( new RestKeys() )->remember( 0 ) );
		self::assertSame( 0, $this->writes );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function unusable_option_values(): array {
		return array(
			'zero'     => array( 0 ),
			'negative' => array( -3 ),
			'text'     => array( 'abc' ),
			'array'    => array( array( 17 ) ),
		);
	}

	#[DataProvider( 'unusable_option_values' )]
	public function test_unusable_option_values_read_as_none( mixed $value ): void {
		$this->options[ RestKeys::OPTION_KEY_ID ] = $value;

		self::assertNull( ( new RestKeys() )->current_id() );
	}

	public function test_numeric_string_option_is_read_as_an_id(): void {
		$this->options[ RestKeys::OPTION_KEY_ID ] = '17';

		self::assertSame( 17, ( new RestKeys() )->current_id() );
	}

	public function test_forget_deletes_the_option(): void {
		$keys = new RestKeys();
		$keys->remember( 17 );

		$keys->forget();

		self::assertArrayNotHasKey( RestKeys::OPTION_KEY_ID, $this->options );
		self::assertNull( $keys->current_id() );
	}

	public function test_debug_output_hides_the_key_and_secret(): void {
		$key = new RestKey( 17, 'ck_' . str_repeat( 'a', 40 ), 'cs_' . str_repeat( 'b', 40 ) );

		$output = print_r( $key, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r

		self::assertStringContainsString( '17', $output );
		self::assertStringNotContainsString( 'aaaa', $output );
		self::assertStringNotContainsString( 'bbbb', $output );
	}

	// ------------------------------------------------------------------ user_id

	/**
	 * Expects the key's row to be read and answers with a user id.
	 *
	 * @param mixed $user_id What get_var() returns.
	 */
	private function expect_user_read( $user_id ): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( string $query, ...$args ): string => self::prepare_query( $query, ...$args ) );
		$this->wpdb->shouldReceive( 'get_var' )
			->once()
			->with( 'SELECT user_id FROM `' . self::TABLE . '` WHERE key_id = 17' )
			->andReturn( $user_id );
	}

	public function test_user_id_is_the_remembered_keys_owner(): void {
		$this->options[ RestKeys::OPTION_KEY_ID ] = 17;
		$this->expect_user_read( '5' );

		self::assertSame( 5, ( new RestKeys() )->user_id() );
	}

	/**
	 * Rows that name no user.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function missing_users(): array {
		return array(
			'row gone' => array( null ),
			'user 0'   => array( '0' ),
			'text'     => array( 'admin' ),
		);
	}

	#[DataProvider( 'missing_users' )]
	public function test_user_id_is_null_without_a_user( mixed $user_id ): void {
		$this->options[ RestKeys::OPTION_KEY_ID ] = 17;
		$this->expect_user_read( $user_id );

		self::assertNull( ( new RestKeys() )->user_id() );
	}

	public function test_user_id_reads_nothing_without_a_key(): void {
		$this->wpdb->shouldNotReceive( 'get_var' );

		self::assertNull( ( new RestKeys() )->user_id() );
	}
}
