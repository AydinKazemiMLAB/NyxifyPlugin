<?php
/**
 * Tests for the store activation.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Store;

use Brain\Monkey\Functions;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Store\Activator;
use Nyxify\Features\Store\LicenseKey;
use Nyxify\Features\Store\RestKeys;
use Nyxify\Features\Store\StatusService;
use Nyxify\Features\Sync\PullScheduler;
use Nyxify\Shared\Http\CoreClient;
use Nyxify\Shared\Http\CoreError;
use Nyxify\Shared\Logging\Logger;
use Nyxify\Shared\Options\Connection;
use Nyxify\Shared\Options\SecretStore;
use Nyxify\Shared\Security\Encryptor;
use Nyxify\Tests\Unit\TestCase;

/**
 * The whole activation over a mocked wp_remote_request(), $wpdb and WooCommerce logger, with the real client, secret
 * store and REST keys.
 *
 * A fake response is an array like WordPress's; a network failure is a \stdClass, which the mocked is_wp_error()
 * treats as a WP_Error.
 */
final class ActivatorTest extends TestCase {

	private const BASE_URL    = 'https://core.example.com';
	private const SITE_URL    = 'https://shop.example.com';
	private const REST_URL    = 'https://shop.example.com/wp-json/';
	private const LICENSE_KEY = 'NX-ABCDEF-GHIJKL-MNOPQR-ST2345';
	private const STORE_ID    = 'shop_8f2a0c1d3e4b5a69';
	private const OLD_STORE   = 'shop_0000000000000001';

	/**
	 * In-memory wp_options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Options whose update_option() fails.
	 *
	 * @var list<string>
	 */
	private array $failing_options = array();

	/**
	 * Calls to wp_remote_request(): url and args.
	 *
	 * @var list<array{url: string, args: array<string, mixed>}>
	 */
	private array $requests = array();

	/**
	 * What the next wp_remote_request() returns.
	 *
	 * @var array<string, mixed>|\stdClass
	 */
	private $next_response;

	/**
	 * Rows inserted into woocommerce_api_keys, by key id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $inserted = array();

	/**
	 * Key ids deleted from woocommerce_api_keys.
	 *
	 * @var list<int>
	 */
	private array $deleted = array();

	/**
	 * Whether the next insert fails.
	 *
	 * @var bool
	 */
	private bool $insert_fails = false;

	/**
	 * Whether the cached store status is still there.
	 *
	 * @var bool
	 */
	private bool $status_cached = true;

	/**
	 * Log entries.
	 *
	 * @var list<array{level: string, message: string, context: array<string, mixed>}>
	 */
	private array $entries = array();

	/**
	 * Times the pulls were started.
	 *
	 * @var int
	 */
	private int $pulls_started = 0;

	/**
	 * Values returned by site_url(), rest_url(), get_bloginfo( 'version' ) and wp_get_environment_type().
	 *
	 * @var array<string, string>
	 */
	private array $site = array(
		'site_url'    => self::SITE_URL,
		'rest_url'    => self::REST_URL,
		'wp_version'  => '7.1.3',
		'environment' => 'production',
	);

	/**
	 * Mocked database.
	 *
	 * @var \wpdb&MockInterface
	 */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->next_response = self::activated();

		Functions\when( 'get_option' )->alias(
			fn( string $name, $default_value = false ) => $this->options[ $name ] ?? $default_value
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				if ( in_array( $name, $this->failing_options, true ) ) {
					return false;
				}
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				unset( $this->options[ $name ] );
				return true;
			}
		);

		Functions\when( 'delete_transient' )->alias(
			function ( string $name ): bool {
				if ( StatusService::TRANSIENT === $name ) {
					$this->status_cached = false;
				}
				return true;
			}
		);
		Functions\when( 'site_url' )->alias( fn(): string => $this->site['site_url'] );
		Functions\when( 'rest_url' )->alias( fn(): string => $this->site['rest_url'] );
		Functions\when( 'get_bloginfo' )->alias( fn(): string => $this->site['wp_version'] );
		Functions\when( 'wp_get_environment_type' )->alias( fn(): string => $this->site['environment'] );
		Functions\when( 'WC' )->justReturn( (object) array( 'version' => '11.1.2' ) );
		Functions\when( 'wc_api_hash' )->alias( fn( string $data ): string => hash_hmac( 'sha256', $data, 'wc-api' ) );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_remote_request' )->alias(
			function ( string $url, array $args ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return $this->next_response;
			}
		);
		Functions\when( 'is_wp_error' )->alias( fn( $thing ): bool => $thing instanceof \stdClass );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn( array $response ) => $response['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( array $response ) => $response['body'] );
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			fn( array $response, string $header ) => $response['headers'][ strtolower( $header ) ] ?? ''
		);

		$this->wpdb            = Mockery::mock( \wpdb::class );
		$this->wpdb->prefix    = 'wp_';
		$this->wpdb->insert_id = 0;
		$this->wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( string $table, array $row ) {
				if ( $this->insert_fails ) {
					return false;
				}
				$this->wpdb->insert_id                    = 20 + count( $this->inserted );
				$this->inserted[ $this->wpdb->insert_id ] = $row;
				return 1;
			}
		);
		$this->wpdb->shouldReceive( 'delete' )->andReturnUsing(
			function ( string $table, array $where ) {
				$this->deleted[] = $where['key_id'];
				return 1;
			}
		);
		$GLOBALS['wpdb'] = $this->wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The test's database.
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * A fake HTTP response.
	 *
	 * @param int                   $status  HTTP status.
	 * @param string                $body    Raw body.
	 * @param array<string, string> $headers Lowercase header names.
	 * @return array<string, mixed>
	 */
	private static function response( int $status, string $body, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => $body,
			'headers'  => $headers + array( 'x-request-id' => 'req-core-0001' ),
		);
	}

	/**
	 * JSON of a value (wp_json_encode() is only stubbed inside a test).
	 *
	 * @param mixed $value Value.
	 */
	private static function json( $value ): string {
		return (string) json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	private static function secret(): string {
		return 'sec_' . str_repeat( 'cd', 32 );
	}

	private static function old_secret(): string {
		return 'sec_' . str_repeat( '01', 32 );
	}

	/**
	 * The core's 200 answer.
	 *
	 * @return array<string, mixed>
	 */
	private static function activated(): array {
		return self::response(
			200,
			self::json(
				array(
					'store_id'      => self::STORE_ID,
					'shared_secret' => self::secret(),
					'status'        => 'active',
				)
			)
		);
	}

	private function store(): SecretStore {
		return new SecretStore( Encryptor::from_secret( 'site secret' ) );
	}

	private function activator(): Activator {
		$wc_logger = Mockery::mock( 'WC_Logger_Interface' );
		$wc_logger->shouldReceive( 'log' )->andReturnUsing(
			function ( string $level, string $message, array $context ): void {
				$this->entries[] = array(
					'level'   => $level,
					'message' => $message,
					'context' => $context,
				);
			}
		);

		$store = $this->store();

		return new Activator(
			new CoreClient( $store, self::BASE_URL ),
			$store,
			new RestKeys(),
			new Logger( $wc_logger ),
			function (): void {
				++$this->pulls_started;
			}
		);
	}

	private function activate(): ?CoreError {
		$license = LicenseKey::parse( strtolower( self::LICENSE_KEY ) );
		self::assertNotNull( $license );

		return $this->activator()->activate( $license, 5 );
	}

	/**
	 * A connection and a plugin REST key from an earlier activation.
	 */
	private function connect_earlier(): void {
		$this->store()->save_connection( new Connection( self::OLD_STORE, self::old_secret() ) );
		$this->options[ RestKeys::OPTION_KEY_ID ] = 9;
	}

	/**
	 * Body of the only request sent.
	 *
	 * @return array<string, mixed>
	 */
	private function sent_body(): array {
		self::assertCount( 1, $this->requests );
		$body = json_decode( (string) $this->requests[0]['args']['body'], true );
		self::assertIsArray( $body );

		return $body;
	}

	/**
	 * The only REST key inserted, with its id.
	 *
	 * @return array{int, array<string, mixed>}
	 */
	private function inserted_key(): array {
		self::assertCount( 1, $this->inserted );
		$key_id = (int) array_key_first( $this->inserted );

		return array( $key_id, $this->inserted[ $key_id ] );
	}

	/**
	 * No secret in any log entry: not the license key, the REST key or secret, nor a shared secret.
	 */
	private function assert_logs_hold_no_secrets(): void {
		$secrets = array( self::LICENSE_KEY, 'GHIJKL', self::secret(), self::old_secret(), 'ck_', 'cs_' );
		foreach ( $this->inserted as $row ) {
			$secrets[] = (string) $row['consumer_secret'];
			$secrets[] = (string) $row['consumer_key'];
		}

		$logged = self::json( $this->entries );
		foreach ( $secrets as $secret ) {
			self::assertStringNotContainsString( $secret, $logged );
		}
	}

	/**
	 * The levels and error codes logged.
	 *
	 * @return list<string>
	 */
	private function logged_codes(): array {
		return array_map(
			fn( array $entry ): string => $entry['level'] . ':' . (string) ( $entry['context']['error_code'] ?? $entry['message'] ),
			$this->entries
		);
	}

	public function test_activation_sends_the_contract_body_and_saves_the_connection(): void {
		self::assertNull( $this->activate() );
		self::assertSame( 1, $this->pulls_started );

		$request = $this->requests[0];
		self::assertSame( self::BASE_URL . '/api/v1/store/activate', $request['url'] );
		self::assertSame( 'POST', $request['args']['method'] );
		self::assertSame( CoreClient::LONG_TIMEOUT_SECONDS, $request['args']['timeout'] );
		self::assertArrayNotHasKey( 'X-Nyxify-Signature', $request['args']['headers'] );

		$body = $this->sent_body();
		self::assertSame(
			array( 'license_key', 'site_url', 'rest_url', 'consumer_key', 'consumer_secret', 'wp_version', 'woo_version' ),
			array_keys( $body )
		);
		self::assertSame( self::LICENSE_KEY, $body['license_key'] );
		self::assertSame( self::SITE_URL, $body['site_url'] );
		self::assertSame( self::REST_URL, $body['rest_url'] );
		self::assertSame( '7.1.3', $body['wp_version'] );
		self::assertSame( '11.1.2', $body['woo_version'] );

		// The key sent is the one inserted (WooCommerce stores its hash) and belongs to the activating admin.
		list( $key_id, $row ) = $this->inserted_key();
		self::assertMatchesRegularExpression( '/^ck_[a-f0-9]{40}$/D', (string) $body['consumer_key'] );
		self::assertSame( hash_hmac( 'sha256', (string) $body['consumer_key'], 'wc-api' ), $row['consumer_key'] );
		self::assertSame( $body['consumer_secret'], $row['consumer_secret'] );
		self::assertSame( 5, $row['user_id'] );
		self::assertSame( 'read_write', $row['permissions'] );

		$connection = $this->store()->get_connection();
		self::assertNotNull( $connection );
		self::assertSame( self::STORE_ID, $connection->store_id );
		self::assertSame( self::secret(), $connection->shared_secret );

		self::assertSame( $key_id, $this->options[ RestKeys::OPTION_KEY_ID ] );
		self::assertSame( array(), $this->deleted );
		self::assertFalse( $this->status_cached, 'The cached status must be cleared.' );

		self::assertSame( array( 'info:Store activated.' ), $this->logged_codes() );
		self::assertSame( self::STORE_ID, $this->entries[0]['context']['store_id'] );
		self::assertSame( 'req-core-0001', $this->entries[0]['context']['request_id'] );
		$this->assert_logs_hold_no_secrets();
	}

	public function test_activating_again_replaces_the_previous_key_and_connection(): void {
		$this->connect_earlier();

		self::assertNull( $this->activate() );

		list( $key_id ) = $this->inserted_key();
		self::assertSame( $key_id, $this->options[ RestKeys::OPTION_KEY_ID ] );
		self::assertSame( array( 9 ), $this->deleted );
		self::assertSame( self::STORE_ID, $this->store()->get_connection()?->store_id );
	}

	/**
	 * @return array<string, array{array<string, mixed>|\stdClass, string, int}>
	 */
	public static function failed_answers(): array {
		$error = fn( int $status, string $code ): array => self::response(
			$status,
			self::json(
				array(
					'message'    => 'ignored',
					'error_code' => $code,
				)
			)
		);

		return array(
			'invalid license key'      => array( $error( 400, 'invalid_license_key' ), 'invalid_license_key', 400 ),
			'license revoked'          => array( $error( 403, 'license_revoked' ), 'license_revoked', 403 ),
			'no subscription'          => array( $error( 403, 'no_active_subscription' ), 'no_active_subscription', 403 ),
			'license already used'     => array( $error( 409, 'license_already_used' ), 'license_already_used', 409 ),
			'store already registered' => array( $error( 409, 'store_already_registered' ), 'store_already_registered', 409 ),
			'woocommerce auth failed'  => array( $error( 400, 'woocommerce_authentication_failed' ), 'woocommerce_authentication_failed', 400 ),
			'woocommerce unreachable'  => array( $error( 502, 'woocommerce_unreachable' ), 'woocommerce_unreachable', 502 ),
			'too many attempts'        => array( $error( 429, 'too_many_attempts' ), 'too_many_attempts', 429 ),
			'validation error'         => array( $error( 422, 'validation_error' ), 'validation_error', 422 ),
			'server error page'        => array( self::response( 500, '<html>oops</html>' ), CoreError::UNKNOWN_ERROR, 500 ),
			'network failure'          => array( new \stdClass(), CoreError::NETWORK_ERROR, 0 ),
			'redirect'                 => array( self::response( 301, '' ), CoreError::UNEXPECTED_REDIRECT, 301 ),
			'not json'                 => array( self::response( 200, 'ok' ), CoreError::INVALID_RESPONSE, 200 ),
			'no secret'                => array( self::response( 200, '{"store_id":"' . self::STORE_ID . '"}' ), CoreError::INVALID_RESPONSE, 200 ),
			'malformed store id'       => array(
				self::response( 200, '{"store_id":"shop_1","shared_secret":"' . self::secret() . '"}' ),
				CoreError::INVALID_RESPONSE,
				200,
			),
			'malformed secret'         => array(
				self::response( 200, '{"store_id":"' . self::STORE_ID . '","shared_secret":"sec_short"}' ),
				CoreError::INVALID_RESPONSE,
				200,
			),
			'secret not a string'      => array(
				self::response( 200, '{"store_id":"' . self::STORE_ID . '","shared_secret":42}' ),
				CoreError::INVALID_RESPONSE,
				200,
			),
		);
	}

	/**
	 * @param array<string, mixed>|\stdClass $answer What wp_remote_request() returns.
	 * @param string                         $code   Expected error code.
	 * @param int                            $status Expected HTTP status.
	 */
	#[DataProvider( 'failed_answers' )]
	public function test_without_a_usable_answer_the_new_key_is_deleted_and_the_old_state_kept( $answer, string $code, int $status ): void {
		$this->connect_earlier();
		$this->next_response = $answer;

		$error = $this->activate();

		self::assertNotNull( $error );
		self::assertSame( $code, $error->code );
		self::assertSame( $status, $error->status );

		list( $key_id ) = $this->inserted_key();
		self::assertSame( array( $key_id ), $this->deleted );
		self::assertSame( 9, $this->options[ RestKeys::OPTION_KEY_ID ] );
		self::assertTrue( $this->status_cached );

		$connection = $this->store()->get_connection();
		self::assertSame( self::OLD_STORE, $connection?->store_id );
		self::assertSame( self::old_secret(), $connection->shared_secret );

		self::assertCount( 1, $this->entries );
		self::assertSame( $code, $this->entries[0]['context']['error_code'] );
		self::assertSame( Activator::OPERATION, $this->entries[0]['context']['operation'] );
		$this->assert_logs_hold_no_secrets();
		self::assertSame( 0, $this->pulls_started );
	}

	public function test_a_connection_that_cannot_be_saved_is_reported_and_the_new_key_kept(): void {
		$this->connect_earlier();
		$this->failing_options = array( SecretStore::OPTION_CONNECTION );

		$error = $this->activate();

		self::assertNotNull( $error );
		self::assertSame( CoreError::CONNECTION_NOT_SAVED, $error->code );
		self::assertSame( 'req-core-0001', $error->request_id );

		// The core uses the new key now; the plugin's previous one is gone.
		list( $key_id ) = $this->inserted_key();
		self::assertSame( $key_id, $this->options[ RestKeys::OPTION_KEY_ID ] );
		self::assertSame( array( 9 ), $this->deleted );
		self::assertFalse( $this->status_cached );

		self::assertSame( array( 'warning:' . CoreError::CONNECTION_NOT_SAVED ), $this->logged_codes() );
		$this->assert_logs_hold_no_secrets();
		self::assertSame( 0, $this->pulls_started );
	}

	public function test_starting_the_pulls_ends_the_pause_and_schedules_the_recurring_pull(): void {
		$this->options[ PullScheduler::OPTION_RETRY_AT ] = 1_791_000_000;
		$scheduled                                       = array();
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\when( 'as_get_scheduled_actions' )->justReturn( array() );
		Functions\when( 'as_enqueue_async_action' )->justReturn( 0 );
		Functions\when( 'as_schedule_recurring_action' )->alias(
			function ( int $timestamp, int $interval, string $hook, array $args, string $group, bool $unique ) use ( &$scheduled ): int {
				$scheduled[] = array( $interval, $hook, $args, $group, $unique );
				return 4;
			}
		);

		Activator::start_pulls();

		self::assertArrayNotHasKey( PullScheduler::OPTION_RETRY_AT, $this->options );
		self::assertSame( array( array( 300, PullScheduler::HOOK, array(), PullScheduler::GROUP, true ) ), $scheduled );
	}

	public function test_a_key_id_that_cannot_be_remembered_is_logged(): void {
		$this->failing_options = array( RestKeys::OPTION_KEY_ID );

		self::assertNull( $this->activate() );

		self::assertSame(
			array( 'warning:The id of the new REST key could not be saved.', 'info:Store activated.' ),
			$this->logged_codes()
		);
	}

	public function test_a_failed_rest_key_sends_nothing(): void {
		$this->insert_fails = true;

		$error = $this->activate();

		self::assertSame( CoreError::REST_KEY_FAILED, $error?->code );
		self::assertSame( array(), $this->requests );
		self::assertSame( array( 'error:' . CoreError::REST_KEY_FAILED ), $this->logged_codes() );
	}

	/**
	 * @return array<string, array{string, string, string, string}>
	 */
	public static function unusable_addresses(): array {
		return array(
			'http site in production' => array( 'http://shop.example.com', 'http://shop.example.com/wp-json/', 'production', CoreError::SITE_NOT_HTTPS ),
			'http site in staging'    => array( 'http://shop.example.com', 'http://shop.example.com/wp-json/', 'staging', CoreError::SITE_NOT_HTTPS ),
			'not a url'               => array( 'shop.example.com', self::REST_URL, 'production', CoreError::SITE_NOT_HTTPS ),
			'ftp site'                => array( 'ftp://shop.example.com', self::REST_URL, 'local', CoreError::SITE_NOT_HTTPS ),
			'rest on another host'    => array( self::SITE_URL, 'https://www.shop.example.com/wp-json/', 'production', CoreError::REST_URL_MISMATCH ),
			'rest on another port'    => array( self::SITE_URL, 'https://shop.example.com:8443/wp-json/', 'production', CoreError::REST_URL_MISMATCH ),
			'rest over http'          => array( self::SITE_URL, 'http://shop.example.com/wp-json/', 'production', CoreError::REST_URL_MISMATCH ),
			'rest not a url'          => array( self::SITE_URL, '/wp-json/', 'production', CoreError::REST_URL_MISMATCH ),
		);
	}

	#[DataProvider( 'unusable_addresses' )]
	public function test_unusable_addresses_create_and_send_nothing( string $site_url, string $rest_url, string $environment, string $code ): void {
		$this->site = array(
			'site_url'    => $site_url,
			'rest_url'    => $rest_url,
			'environment' => $environment,
		) + $this->site;

		$error = $this->activate();

		self::assertSame( $code, $error?->code );
		self::assertSame( array(), $this->inserted );
		self::assertSame( array(), $this->requests );
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function usable_addresses(): array {
		return array(
			'plain permalinks'     => array( self::SITE_URL, 'https://shop.example.com/?rest_route=/', 'production' ),
			'subdirectory install' => array( 'https://shop.example.com/wp', 'https://shop.example.com/wp-json/', 'production' ),
			'explicit https port'  => array( self::SITE_URL, 'https://shop.example.com:443/wp-json/', 'production' ),
			'upper case host'      => array( 'https://Shop.Example.com', self::REST_URL, 'production' ),
			'custom port'          => array( 'https://shop.example.com:88', 'https://shop.example.com:88/wp-json/', 'production' ),
			'http on a local site' => array( 'http://shop.local', 'http://shop.local/wp-json/', 'local' ),
			'http in development'  => array( 'http://localhost:8080', 'http://localhost:8080/wp-json/', 'development' ),
		);
	}

	#[DataProvider( 'usable_addresses' )]
	public function test_usable_addresses_are_sent_as_wordpress_gives_them( string $site_url, string $rest_url, string $environment ): void {
		$this->site = array(
			'site_url'    => $site_url,
			'rest_url'    => $rest_url,
			'environment' => $environment,
		) + $this->site;

		self::assertNull( $this->activate() );

		$body = $this->sent_body();
		self::assertSame( $site_url, $body['site_url'] );
		self::assertSame( $rest_url, $body['rest_url'] );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function versions(): array {
		return array(
			'release'           => array( '7.1.3', '7.1.3' ),
			'beta'              => array( '7.2-beta1', '7.2-beta1' ),
			'long development'  => array( '7.2-alpha-61234-src-extra', '7.2-alpha-61234-src-' ),
			'unexpected spaces' => array( '7.1.3 custom', '7.1.3' ),
			'not a version'     => array( 'unknown', 'unknown' ),
		);
	}

	#[DataProvider( 'versions' )]
	public function test_versions_are_cut_to_the_core_format( string $version, string $sent ): void {
		$this->site['wp_version'] = $version;

		$this->activate();

		self::assertSame( $sent, $this->sent_body()['wp_version'] );
	}
}
